<?php

declare(strict_types=1);

namespace RC\Helper\Process;

use Workerman\Connection\TcpConnection;

/** Best-effort asynchronous access-log writer; construct in its owning worker and bind with worker_bind(). */
class Logger
{
    private int $pid;
    private string $directory;
    private array $batches = [];
    private int $queuedBytes = 0;
    private int $queuedRecords = 0;
    private int $maxBytes;
    private int $maxRecords;
    private int $maxConnections;
    private float $flushInterval;
    private int $idleSeconds;
    private ?int $timerId = null;
    private bool $stopping = false;
    private int $lastWarning = 0;
    private array $counters = ['accepted' => 0, 'rejected' => 0, 'dropped' => 0, 'write_failures' => 0];

    /** Retain the four legacy arguments; optional policy is read once from config/worker.php logger. */
    public function __construct(protected $_type, protected $_work, protected $_timer, protected $_table = null, ?array $options = null)
    {
        $options ??= \RC\Config::get('worker', 'logger') ?: [];
        if (!is_array($options)) { throw new \InvalidArgumentException('Logger policy must be an array.'); }
        $this->maxBytes = self::positiveInteger($options['max_buffer_bytes'] ?? 4194304, 67108864);
        $this->maxRecords = self::positiveInteger($options['max_buffer_records'] ?? 8192, 1048576);
        $this->maxConnections = self::positiveInteger($options['max_connections'] ?? 1024, 65536);
        $this->idleSeconds = self::positiveInteger($options['idle_seconds'] ?? 90, 86400);
        $interval = $options['flush_interval'] ?? 3.0;
        if ((!is_int($interval) && !is_float($interval)) || !is_finite((float) $interval) || $interval < 0.01 || $interval > 60) {
            throw new \InvalidArgumentException('Logger flush_interval must be between 0.01 and 60 seconds.');
        }
        $this->flushInterval = (float) $interval;
        $this->directory = rtrim(runtime_path(), '/\\') . '/logs';
        $this->pid = getmypid();
        $this->start();
    }

    /** Reject invalid limits before creating a directory or registering a callback. */
    private static function positiveInteger(mixed $value, int $maximum): int
    {
        if ((!is_int($value) && !is_string($value)) || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1 || (int) $value > $maximum) {
            throw new \InvalidArgumentException('Invalid access-log capacity.');
        }
        return (int) $value;
    }

    /** Bound incoming native connections and use the compatible per-frame Text adapter before the first read. */
    public function onConnect($connection): void
    {
        if ($this->_type !== 'workerman' || !$connection instanceof TcpConnection) { return; }
        if ($this->stopping || $this->pid !== getmypid() || count($this->_work->connections) > $this->maxConnections) {
            $connection->close();
            return;
        }
        $connection->maxPackageSize = AccessLog::MAX_PACKET_BYTES;
        $connection->maxSendBufferSize = AccessLog::MAX_SEND_BUFFER_BYTES;
        $connection->protocol = AccessLogProtocol::class;
        $connection->context->rcLoggerSeen = time();
    }

    /** Acknowledge bounded queue acceptance, not disk durability; malformed messages never reach the writer. */
    public function onMessage($connection, $data)
    {
        try {
            if ($this->stopping || $this->pid !== getmypid() || !is_string($data) || strlen($data) >= AccessLog::MAX_PACKET_BYTES) {
                throw new \InvalidArgumentException('Logger message rejected.');
            }
            if ($connection instanceof TcpConnection) { $connection->context->rcLoggerSeen = time(); }
            $message = json_decode($data, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($message) || !isset($message['type'])) { throw new \InvalidArgumentException('Invalid logger message.'); }
            if ($message['type'] === 'ping') { $this->reply($connection, 'ok'); return; }
            if ($message['type'] !== 'log' || !is_array($message['data'] ?? null)) { throw new \InvalidArgumentException('Invalid logger record.'); }
            $accepted = $this->append(AccessLog::normalize($message['data']));
            $this->reply($connection, $accepted ? 'ok' : 'dropped');
        } catch (\Throwable) {
            ++$this->counters['rejected'];
            try { $connection->close('error'); } catch (\Throwable) {}
        }
    }

    /** Keep acknowledgements bounded if a sender stops reading them. */
    private function reply($connection, string $message): void
    {
        if ($connection instanceof TcpConnection && $connection->getSendBufferQueueSize() + strlen($message) + 1 > AccessLog::MAX_SEND_BUFFER_BYTES) {
            $connection->destroy();
            return;
        }
        $connection->send($message);
    }

    /** Format once in the logger process and flush before bounded buffers could overflow. */
    private function append(array $record): bool
    {
        $line = AccessLog::line($record, $this->_type);
        $length = strlen($line);
        if ($length > $this->maxBytes) { ++$this->counters['dropped']; $this->warn(); return false; }
        if ($this->queuedBytes + $length > $this->maxBytes || $this->queuedRecords >= $this->maxRecords) { $this->flush(); }
        $file = AccessLog::fileName($record);
        $this->batches[$file] = ($this->batches[$file] ?? '') . $line;
        $this->queuedBytes += $length;
        ++$this->queuedRecords;
        ++$this->counters['accepted'];
        if ($this->queuedBytes >= 262144 || $this->queuedRecords >= 1024) { $this->flush(); }
        return true;
    }

    /** Register only one owned timer; the retired Swoole path keeps sequential table draining. */
    public function start()
    {
        if ($this->timerId !== null || $this->stopping || $this->pid !== getmypid()) { return; }
        $this->ensureDirectory();
        if ($this->_type === 'workerman') {
            $this->timerId = $this->_timer::add($this->flushInterval, $this->tick(...));
        } elseif ($this->_type === 'swoole') {
            $this->timerId = $this->_timer::tick((int) ceil($this->flushInterval * 1000), $this->tick(...));
        } else { throw new \InvalidArgumentException('Unknown logger runtime.'); }
    }

    /** Periodic work is confined to the logger process, never an HTTP success-path disk write. */
    private function tick(): void
    {
        if ($this->pid !== getmypid() || $this->stopping) { return; }
        if ($this->_type === 'swoole' && $this->_table !== null) {
            foreach ($this->_table as $key => $record) {
                try { $this->append(AccessLog::normalize($record)); } catch (\Throwable) { ++$this->counters['rejected']; }
                $this->_table->del($key);
            }
        }
        $this->flush();
        if ($this->_type === 'workerman') {
            $oldest = time() - $this->idleSeconds;
            foreach ($this->_work->connections as $connection) {
                if (($connection->context->rcLoggerSeen ?? 0) < $oldest) { $connection->destroy(); }
            }
        }
    }

    /** Consume each batch once; an uncertain partial append is never replayed automatically. */
    public function flush(): void
    {
        if ($this->pid !== getmypid() || $this->batches === []) { return; }
        $batches = $this->batches;
        $this->batches = [];
        $this->queuedBytes = $this->queuedRecords = 0;
        foreach ($batches as $file => $bytes) {
            try {
                if (!$this->ensureDirectory() || $this->writeBatch($this->directory . '/' . $file, $bytes) !== strlen($bytes)) {
                    throw new \RuntimeException('Incomplete access-log append.');
                }
            } catch (\Throwable) {
                ++$this->counters['write_failures'];
                $this->counters['dropped'] += substr_count($bytes, "\n");
                $this->warn();
            }
        }
    }

    /** Append under a nonblocking lock, handling partial writes without holding an unbounded retry queue. */
    protected function writeBatch(string $path, string $bytes): int
    {
        if (is_link($path) || file_exists($path) && !is_file($path)) { return 0; }
        $exists = file_exists($path);
        $stream = @fopen($path, 'ab');
        if ($stream === false) { return 0; }
        $written = 0;
        try {
            if (!flock($stream, LOCK_EX | LOCK_NB)) { return 0; }
            if (!$exists && DIRECTORY_SEPARATOR === '/' && !@chmod($path, 0600)) { return 0; }
            for ($length = strlen($bytes); $written < $length;) {
                $count = @fwrite($stream, substr($bytes, $written));
                if ($count === false || $count === 0) { break; }
                $written += $count;
            }
            return $written;
        } finally { fclose($stream); }
    }

    /** Runtime paths remain external under PHAR; existing permissions are not silently changed. */
    private function ensureDirectory(): bool
    {
        try { return is_dir($this->directory) || @mkdir($this->directory, 0750, true) || is_dir($this->directory); }
        catch (\Throwable) { return false; }
    }

    /** Report bounded loss without echoing client fields, filesystem paths or recursive per-record warnings. */
    private function warn(): void
    {
        $now = time();
        if ($this->lastWarning !== 0 && $now - $this->lastWarning < 30) { return; }
        $this->lastWarning = $now;
        try { @error_log('[rcmaker-access-log] records dropped or write failed; inspect logger counters and storage.'); } catch (\Throwable) {}
    }

    /** Flush accepted records once and close only this worker's log connections before it exits. */
    public function onWorkerStop($worker = null): void
    {
        if ($this->pid !== getmypid() || $this->stopping) { return; }
        $this->flush();
        $this->stopping = true;
        if ($this->timerId !== null) {
            $this->_type === 'swoole' ? $this->_timer::clear($this->timerId) : $this->_timer::del($this->timerId);
            $this->timerId = null;
        }
        if ($this->_type === 'workerman') { foreach ($this->_work->connections as $connection) { $connection->destroy(); } }
    }

    /** Diagnostics expose counts, not queued request data; accepted does not mean durable. */
    public function statistics(): array
    {
        return $this->counters + ['queued_bytes' => $this->queuedBytes, 'queued_records' => $this->queuedRecords];
    }

    /** A logger owns timers and process-local batches, not a transferable value. */
    public function __clone() { throw new \LogicException('A process logger cannot be cloned.'); }

    /** Do not serialize queued diagnostic data or active timer ownership. */
    public function __serialize(): array { throw new \LogicException('A process logger cannot be serialized.'); }

    /** Reject crafted serialized instances that bypass directory and process ownership initialization. */
    public function __unserialize(array $data): void { throw new \LogicException('A process logger cannot be unserialized.'); }
}
