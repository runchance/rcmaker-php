<?php

declare(strict_types=1);

namespace RC\Http;

use RC\Helper\Redis\Connector;
use Workerman\Protocols\Http\Session;
use Workerman\Protocols\Http\Session\RedisSessionHandler as SessionHandler;

/** Sequential APP session storage; each handler owns its native connection, never the upstream global pool. */
class RedisSessionHandler extends SessionHandler
{
    private int $ownerPid = 0;
    private string $identity;
    private ?int $lifetimeOverride = null;

    /** Keep legacy auth/database settings; sockets are opened lazily on the first storage operation. */
    public function __construct(array $config)
    {
        if (!in_array($config['client'] ?? 'auto', ['auto', 'phpredis'], true)) {
            throw new \InvalidArgumentException('Redis session storage requires ext-redis, not Predis.');
        }
        if (!$this->isCluster() && is_array($config['host'] ?? null)) {
            throw new \InvalidArgumentException('Use RedisClusterSessionHandler for Redis Cluster seeds.');
        }
        $config['type'] = $this->isCluster() ? 'cluster' : 'redis';
        $config['client'] = 'phpredis';
        $config['password'] ??= $config['auth'] ?? '';
        $config['database'] = ($config['database'] ?? '') === '' ? 0 : $config['database'];
        if (!isset($config['prefix']) || $config['prefix'] === '') { $config['prefix'] = 'redis_session_'; }
        parent::__construct($config);
        if (array_key_exists('lifetime', $config)) {
            $value = $config['lifetime'];
            if ((!is_int($value) && !is_string($value)) || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
                throw new \InvalidArgumentException('Session lifetime must be positive.');
            }
            $this->lifetimeOverride = (int) $value;
        }
        $this->identity = 'session-' . bin2hex(random_bytes(16));
    }

    /** Cluster adapters specialize only the connection kind; CRUD and lifecycle remain shared. */
    protected function isCluster(): bool { return false; }

    /** An inherited descriptor must be closed in the child before opening a PID-owned replacement. */
    protected function connection(): \Redis|\RedisCluster
    {
        if (\Fiber::getCurrent() !== null || extension_loaded('swow')
            || extension_loaded('swoole') && \Swoole\Coroutine::getCid() >= 0) {
            throw new \LogicException('RC Redis sessions require sequential APP execution; coroutine sharing is unsupported.');
        }
        $pid = getmypid();
        if ($this->ownerPid !== $pid) {
            $this->releaseConnection();
            $this->ownerPid = $pid;
        }
        return $this->connection ??= $this->createRedisConnection($this->config);
    }

    /** Reuse the audited framework connector for ACL, TLS, prefixes, persistence and disabled write retries. */
    protected function createRedisConnection(array $config): \Redis|\RedisCluster
    {
        return Connector::open($config, $this->identity, \Redis::class, \RedisCluster::class);
    }

    /** Missing sessions return false; protocol/server/transport errors are never treated as a cache miss. */
    public function read(string $sessionId): string|false
    {
        $this->assertSessionId($sessionId);
        return $this->command('get', [$sessionId]);
    }

    /** One SETEX only: propagate uncertain writes and discard their connection without replaying them. */
    public function write(string $sessionId, string $sessionData): bool
    {
        $this->assertSessionId($sessionId);
        $lifetime = $this->lifetimeOverride ?? Session::$lifetime;
        if ($lifetime < 1) { throw new \InvalidArgumentException('Session lifetime must be positive.'); }
        return $this->command('setex', [$sessionId, $lifetime, $sessionData]);
    }

    /** Expiring a missing key returns false; an actual Redis error still throws. */
    public function updateTimestamp(string $sessionId, string $data = ''): bool
    {
        $this->assertSessionId($sessionId);
        $lifetime = $this->lifetimeOverride ?? Session::$lifetime;
        if ($lifetime < 1) { throw new \InvalidArgumentException('Session lifetime must be positive.'); }
        return $this->command('expire', [$sessionId, $lifetime]) === true;
    }

    /** DEL is successful even when the session has already expired; no whole-database operation is used. */
    public function destroy(string $sessionId): bool
    {
        $this->assertSessionId($sessionId);
        $this->command('del', [$sessionId]);
        return true;
    }

    /** Explicitly release this handler's socket; a later independent operation can open another one. */
    public function close(): bool
    {
        $this->releaseConnection();
        return true;
    }

    /** A thrown command is not retried; the next caller receives a fresh, fully configured connection. */
    private function command(string $method, array $arguments): mixed
    {
        $connection = $this->connection();
        try {
            $connection->clearLastError();
            $result = $connection->$method(...$arguments);
            if ($connection->getLastError() !== null) { throw new \RuntimeException('Redis session command failed.'); }
            $valid = match ($method) {
                'get' => is_string($result) || $result === false,
                'expire' => is_bool($result),
                'setex' => $result === true,
                'del' => is_int($result) && $result >= 0,
            };
            if (!$valid) { throw new \RuntimeException('Redis session command returned an invalid result.'); }
            return $result;
        } catch (\Throwable $error) {
            $this->releaseConnection();
            throw $error;
        }
    }

    /** Match the native session alphabet, with a strict end anchor rather than accepting a final newline. */
    private function assertSessionId(string $sessionId): void
    {
        if (!preg_match('/\A[a-zA-Z0-9,-]{16,256}\z/', $sessionId)) {
            throw new \InvalidArgumentException('Invalid session identifier.');
        }
    }

    /** Drop only the owned native client; cleanup failure must not mask an earlier storage exception. */
    private function releaseConnection(): void
    {
        $connection = $this->connection;
        $this->connection = null;
        if ($connection !== null) { try { $connection->close(); } catch (\Throwable) {} }
    }

    /** Active transports cannot be duplicated through cloning. */
    public function __clone(): void
    {
        $this->connection = null;
        throw new \LogicException('Redis session handlers cannot be cloned.');
    }

    /** Prevent serializing credentials or an active transport into application data. */
    public function __serialize(): array { throw new \LogicException('Redis session handlers cannot be serialized.'); }

    /** Deserialized transport objects are never allowed to acquire a new connection. */
    public function __unserialize(array $data): void { throw new \LogicException('Redis session handlers cannot be unserialized.'); }

    /** No timers or global pools to reclaim; close only this instance's native connection. */
    public function __destruct() { $this->releaseConnection(); }
}
