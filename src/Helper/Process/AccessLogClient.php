<?php

declare(strict_types=1);

namespace RC\Helper\Process;

use Workerman\Connection\AsyncTcpConnection;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;

/** One best-effort asynchronous sender per APP process; no second queue or replay after an uncertain send. */
final class AccessLogClient
{
    private static ?self $instance = null;
    private int $pid;
    private ?AsyncTcpConnection $connection = null;
    private ?int $heartbeatTimer = null;
    private ?int $connectTimer = null;
    private ?int $drainTimer = null;
    private int $nextAttempt = 0;
    private int $lastReply = 0;
    private int $lastWarning = 0;
    private bool $stopping = false;
    private array $counters = ['queued' => 0, 'acknowledged' => 0, 'dropped' => 0, 'connections' => 0, 'disconnects' => 0];

    /** Capture only the endpoint; construction does not connect until the first record. */
    private function __construct(private string $endpoint)
    {
        $this->pid = getmypid();
    }

    /** Called only after cli_log's cached switch; all logging failures stay outside the business response. */
    public static function write(array $record): void
    {
        try {
            if (self::$instance !== null && self::$instance->pid !== getmypid()) {
                self::$instance->stop(false);
                self::$instance = null;
            }
            self::$instance ??= new self((string) \RC\Config::get('worker', 'logger_listen'));
            self::$instance->push($record);
        } catch (\Throwable) {
            // This boundary also covers unavailable event loops and invalid developer endpoint configuration.
            if (self::$instance !== null) { ++self::$instance->counters['dropped']; self::$instance->warn(); }
        }
    }

    /** Queue only within native buffer capacity; connection startup/retry is not repeated on each healthy request. */
    private function push(array $record): void
    {
        if ($this->stopping) { ++$this->counters['dropped']; return; }
        $packet = AccessLog::packet($record);
        if ($this->connection === null) {
            if (hrtime(true) < $this->nextAttempt) { ++$this->counters['dropped']; return; }
            $this->connect();
        }
        $connection = $this->connection;
        if ($connection === null || $connection->getSendBufferQueueSize() + strlen($packet) + 1 > AccessLog::MAX_SEND_BUFFER_BYTES) {
            ++$this->counters['dropped']; $this->warn(); return;
        }
        if ($connection->send($packet) === false) {
            ++$this->counters['dropped']; $this->retire($connection); return;
        }
        ++$this->counters['queued'];
    }

    /** Native sockets remain nonblocking; one deadline bounds a connection attempt and one timer checks idle peers. */
    private function connect(): void
    {
        $this->nextAttempt = hrtime(true) + 1000000000;
        $endpoint = preg_replace('~^text://~i', 'tcp://', $this->endpoint);
        $connection = new AsyncTcpConnection($endpoint);
        $connection->protocol = AccessLogProtocol::class;
        $connection->maxSendBufferSize = AccessLog::MAX_SEND_BUFFER_BYTES;
        $connection->maxPackageSize = AccessLog::MAX_PACKET_BYTES;
        $this->connection = $connection;
        ++$this->counters['connections'];
        $connection->onConnect = function ($active): void {
            if ($this->connection !== $active || $this->stopping) { return; }
            $this->cancelConnectTimer();
            $this->lastReply = hrtime(true);
        };
        $connection->onMessage = function ($active, $reply): void {
            if ($this->connection !== $active) { return; }
            if ($reply !== 'ok' && $reply !== 'dropped') { $this->retire($active); return; }
            $this->lastReply = hrtime(true);
            ++$this->counters['acknowledged'];
            if ($reply === 'dropped') { ++$this->counters['dropped']; $this->warn(); }
        };
        $connection->onError = function ($active, $code, $message): void { $this->retire($active); };
        $connection->onClose = function ($active): void { $this->retire($active); };
        try {
            $this->connectTimer = Timer::add(3, function () use ($connection): void { $this->retire($connection); }, [], false);
            if ($this->heartbeatTimer === null) { $this->heartbeatTimer = Timer::add(25, $this->heartbeat(...)); }
            $connection->connect();
        } catch (\Throwable) { $this->retire($connection); }
    }

    /** Release only this connection; pending or partially transmitted bytes are deliberately not replayed. */
    private function retire(AsyncTcpConnection $connection): void
    {
        if ($this->connection !== $connection) { return; }
        $this->connection = null;
        $this->cancelConnectTimer();
        $this->nextAttempt = hrtime(true) + 1000000000;
        ++$this->counters['disconnects'];
        $connection->onConnect = $connection->onMessage = $connection->onError = $connection->onClose = null;
        $connection->destroy();
        if (!$this->stopping) { $this->warn(); }
    }

    /** Ping only established peers, retiring silent connections without holding up normal APP requests. */
    private function heartbeat(): void
    {
        if ($this->stopping || $this->pid !== getmypid()) { return; }
        $connection = $this->connection;
        if ($connection === null || $connection->getStatus() !== TcpConnection::STATUS_ESTABLISHED) { return; }
        if (hrtime(true) - $this->lastReply >= 60000000000) { $this->retire($connection); return; }
        $ping = '{"type":"ping","data":""}';
        if ($connection->getSendBufferQueueSize() + strlen($ping) + 1 <= AccessLog::MAX_SEND_BUFFER_BYTES && $connection->send($ping) === false) {
            $this->retire($connection);
        }
    }

    /** Deadlines are owned and cancelled even when an error callback fires synchronously during connect(). */
    private function cancelConnectTimer(): void
    {
        if ($this->connectTimer !== null) { Timer::del($this->connectTimer); $this->connectTimer = null; }
    }

    /** Rate-limit generic diagnostics; never print request fields, endpoint credentials or a failure trace. */
    private function warn(): void
    {
        $now = time();
        if ($this->lastWarning !== 0 && $now - $this->lastWarning < 30) { return; }
        $this->lastWarning = $now;
        try { @error_log('[rcmaker-access-log] sender unavailable or at capacity; diagnostic records may be lost.'); } catch (\Throwable) {}
    }

    /** Runtime shutdown hook: allow at most one second for already queued native bytes during graceful stop. */
    public static function shutdown(bool $graceful = false): void
    {
        if (self::$instance !== null) { self::$instance->stop($graceful && self::$instance->pid === getmypid()); }
    }

    /** Cancel owned timers and avoid a reconnect loop keeping a stopped APP process alive. */
    private function stop(bool $graceful): void
    {
        if ($this->stopping) {
            if (!$graceful && $this->connection !== null) {
                if ($this->drainTimer !== null) { Timer::del($this->drainTimer); $this->drainTimer = null; }
                $this->retire($this->connection);
            }
            return;
        }
        $this->stopping = true;
        if ($this->heartbeatTimer !== null) { Timer::del($this->heartbeatTimer); $this->heartbeatTimer = null; }
        $this->cancelConnectTimer();
        $connection = $this->connection;
        if ($connection === null) { return; }
        if (!$graceful || $connection->getStatus() !== TcpConnection::STATUS_ESTABLISHED) { $this->retire($connection); return; }
        $connection->onMessage = $connection->onError = $connection->onConnect = null;
        $connection->onClose = function ($active): void {
            if ($this->drainTimer !== null) { Timer::del($this->drainTimer); $this->drainTimer = null; }
            if ($this->connection === $active) { $this->connection = null; }
        };
        $connection->close();
        if ($connection->getStatus() !== TcpConnection::STATUS_CLOSED) {
            $this->drainTimer = Timer::add(1, function () use ($connection): void {
                $this->drainTimer = null;
                $this->retire($connection);
            }, [], false);
        }
    }

    /** Return bounded counters without creating a sender when logging was never enabled. */
    public static function statistics(): array
    {
        $instance = self::$instance;
        if ($instance === null || $instance->pid !== getmypid()) { return ['queued' => 0, 'acknowledged' => 0, 'dropped' => 0, 'connections' => 0, 'disconnects' => 0, 'pending_bytes' => 0]; }
        return $instance->counters + ['pending_bytes' => $instance->connection?->getSendBufferQueueSize() ?? 0];
    }

    /** An active sender cannot be copied into another scope. */
    public function __clone() { throw new \LogicException('An access-log sender cannot be cloned.'); }

    /** Never serialize diagnostic queues or native connection ownership. */
    public function __serialize(): array { throw new \LogicException('An access-log sender cannot be serialized.'); }

    /** Reject crafted serialized instances that would bypass endpoint/PID initialization. */
    public function __unserialize(array $data): void { throw new \LogicException('An access-log sender cannot be unserialized.'); }
}
