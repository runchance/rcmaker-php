<?php

namespace RC\Helper\Redis\mix;

require_once dirname(__DIR__, 2) . '/Db/mix/bootstrap.php';

/**
 * RC-derived Mix driver: native connection setup is shared with raw Redis.
 * Original Mix sources remain unchanged under src/ThirdParty/mix-redis.
 */
class Driver
{
    use \Mix\ObjectPool\ObjectTrait;

    protected $redis;
    protected $host = '';
    protected $port = 6379;
    protected $password = '';
    protected $database = 0;
    protected $timeout = 5.0;
    protected $retryInterval = 0;
    protected $readTimeout = -1;
    private array $configuration;
    private array $optionOverrides = [];
    private string $identity;
    private int $ownerPid = 0;
    private ?int $owner = null;
    private bool $disconnected = false;
    private bool $busy = false;
    private bool $dirty = false;

    /** Legacy positional arguments remain; trailing options carry ACL/TLS/prefix/persistence settings. */
    public function __construct(string $host, int $port = 6379, string|array $password = '', int $database = 0,
        float $timeout = 5.0, int $retryInterval = 0, ?float $readTimeout = null, array $options = [])
    {
        $this->configuration = $options + ['host' => $host, 'port' => $port, 'password' => $password,
            'database' => $database, 'timeout' => $timeout, 'retryInterval' => $options['retry_interval'] ?? $retryInterval,
            'readTimeout' => $readTimeout ?? $timeout];
        foreach (['host', 'port', 'password', 'database', 'timeout'] as $field) {
            $this->$field = $this->configuration[$field];
        }
        $this->retryInterval = $this->configuration['retryInterval'] ?? $this->configuration['retry_interval'] ?? 0;
        $this->readTimeout = $this->configuration['read_timeout'] ?? $this->configuration['readTimeout'] ?? $this->timeout;
        if (!in_array($this->configuration['client'] ?? 'auto', ['auto', 'phpredis'], true)) {
            throw new \InvalidArgumentException('Mix Redis requires ext-redis; use redis("raw") for Predis.');
        }
        $this->configuration['client'] = 'phpredis';
        $this->identity = 'mix-' . bin2hex(random_bytes(16));
        $this->ownerPid = getmypid();
        $this->connect();
    }

    /** Return the real native client; framework adapters retain ownership of its lifecycle. */
    public function instance()
    {
        if ($this->redis === null) { throw new \LogicException('Mix Redis driver is closed.'); }
        return $this->redis;
    }

    /** Explicit reconnect is permitted only when no command, transaction or pipeline owns this driver. */
    public function connect()
    {
        if ($this->hasOwner()) { throw new \LogicException('Cannot reconnect an owned Mix Redis connection.'); }
        if (!extension_loaded('redis')) { throw new \RuntimeException('Mix Redis requires ext-redis; use redis("raw") for Predis.'); }
        $configuration = array_replace($this->configuration, ['host' => $this->host, 'port' => $this->port,
            'password' => $this->password, 'database' => $this->database, 'timeout' => $this->timeout,
            'retryInterval' => $this->retryInterval, 'read_timeout' => $this->readTimeout]);
        $client = \RC\Helper\Redis\Connector::open($configuration, $this->identity, \Redis::class, \RedisCluster::class);
        try {
            foreach ($this->optionOverrides as $option => $value) {
                if (!$client->setOption($option, $value)) { throw new \RuntimeException('Mix Redis option restoration failed.'); }
            }
        } catch (\Throwable $error) {
            try { $client->close(); } catch (\Throwable) {}
            throw $error;
        }
        $this->redis = $client;
        $this->ownerPid = getmypid();
        $this->owner = null;
        $this->disconnected = false;
        $this->dirty = false;
    }

    /** A fresh manager borrow may recover a failed or inherited connection, never replay the old command. */
    public function prepareBorrow(): void
    {
        if ($this->ownerPid !== getmypid()) {
            // Unsetting a persistent native client can return its inherited stream to phpredis' idle pool.
            // Explicit close drops only this process's descriptor before the fresh child connection is opened.
            if ($this->redis !== null) { $this->redis->close(); }
            $this->redis = null;
            $this->owner = null;
            $this->busy = false;
            $this->ownerPid = getmypid();
            $this->disconnected = true;
        }
        if ($this->hasOwner()) { throw new \LogicException('Use the active Mix Redis transaction or pipeline until it finishes.'); }
        if ($this->disconnected || $this->redis === null) { $this->connect(); }
    }

    /** Adopt a physical connection before sending any command or entering MULTI/PIPELINE. */
    public function acquire(object $owner): void
    {
        if ($this->ownerPid !== getmypid() || $this->owner !== null) {
            throw new \LogicException('Mix Redis connection is already owned or inherited from another process.');
        }
        $this->owner = spl_object_id($owner);
    }

    /** Only the exact owner in the originating process may use this physical session. */
    public function isOwner(object $owner): bool
    {
        return $this->ownerPid === getmypid() && $this->owner === spl_object_id($owner);
    }

    /** Pool replacement and reconnect must not invalidate an active scope. */
    public function hasOwner(): bool { return $this->ownerPid === getmypid() && $this->owner !== null; }

    /** Release once; stale scope destructors cannot revoke a later borrower's lease. */
    public function release(object $owner): bool
    {
        if (!$this->isOwner($owner)) { return false; }
        $this->owner = null;
        return true;
    }

    /** Mark an uncertain network outcome without retrying its business command. */
    public function markDisconnected(): void { $this->disconnected = true; }

    /** Pool cleanup must discard broken or session-mutated clients. */
    public function requiresDiscard(): bool { return $this->disconnected || $this->dirty; }

    /** Native errors are not all disconnects: WRONGTYPE and syntax errors may leave a healthy socket. */
    public static function isDisconnectException(\Throwable $error): bool
    {
        if (!$error instanceof \RedisException && !$error instanceof \RedisClusterException) { return false; }
        foreach (['failed with errno', 'connection lost', 'connection closed', 'connection reset',
            'read error', 'socket error', 'went away', 'broken pipe', 'EOF', 'I/O error'] as $message) {
            if (stripos($error->getMessage(), $message) !== false) { return true; }
        }
        return false;
    }

    /** Dispatch once while retaining references (SCAN cursors) and forbidding raw lifecycle bypasses. */
    public function invoke(object $owner, string $command, array $arguments, bool $lifecycle = false): mixed
    {
        if (!$this->isOwner($owner) || $this->busy) { throw new \LogicException('Mix Redis scope is closed, inherited or busy.'); }
        $name = strtolower($command);
        if (!$lifecycle) { $this->validateCommand($owner, $name, $arguments); }
        $this->busy = true;
        try {
            $result = $this->instance()->$command(...$arguments);
            if (!$owner instanceof Multi && $result === true) {
                if ($name === 'select') { $this->configuration['database'] = $this->database = $arguments[0]; $this->dirty = true; }
                if ($name === 'auth') {
                    $this->configuration['password'] = $this->password = $arguments[0];
                    unset($this->configuration['username']);
                    $this->dirty = true;
                }
                if ($name === 'setoption') {
                    $this->optionOverrides[$arguments[0]] = $arguments[1];
                    $this->dirty = true;
                }
            }
            return $result;
        } catch (\Throwable $error) {
            if (self::isDisconnectException($error)) { $this->markDisconnected(); }
            throw $error;
        } finally { $this->busy = false; }
    }

    /** Keep mode and transport transitions inside owned APIs; arbitrary data commands still use native signatures. */
    private function validateCommand(object $owner, string $name, array $arguments): void
    {
        if (in_array($name, ['multi', 'pipeline', 'exec', 'discard', 'watch', 'unwatch',
            'connect', 'pconnect', 'open', 'popen', 'close', 'reset', 'hello'], true)) {
            throw new \LogicException('Use the Mix Redis owned lifecycle API for ' . $name . '.');
        }
        if ($owner instanceof Multi && in_array($name, ['auth', 'select', 'setoption'], true)) {
            throw new \LogicException('Configure Redis credentials, database and options outside a batch.');
        }
        if ($name === 'setoption' && ($arguments[0] ?? null) === \Redis::OPT_MAX_RETRIES && ($arguments[1] ?? null) !== 0) {
            throw new \LogicException('Mix Redis does not allow implicit network command retries.');
        }
        if ($name === 'rawcommand') {
            $offset = $this->instance() instanceof \RedisCluster ? 1 : 0;
            $wire = strtoupper((string) ($arguments[$offset] ?? ''));
            if (in_array($wire, ['MULTI', 'EXEC', 'DISCARD', 'WATCH', 'UNWATCH', 'AUTH', 'SELECT', 'RESET', 'HELLO', 'QUIT'], true)) {
                throw new \LogicException('Raw Redis lifecycle/configuration commands bypass ownership; use the named API.');
            }
        }
    }

    /** Close only an unowned session; callers must first end their transaction or pipeline. */
    public function close()
    {
        if ($this->hasOwner()) { throw new \LogicException('Cannot close an owned Mix Redis connection.'); }
        $client = $this->redis;
        $this->redis = null;
        $this->disconnected = true;
        if ($client !== null) { $client->close(); }
    }

    /** A forked cache eviction must close its local descriptor instead of recycling a parent stream as idle. */
    public function __destruct()
    {
        if ($this->ownerPid !== getmypid() && $this->redis !== null) {
            try { $this->redis->close(); } catch (\Throwable) {}
        }
    }

    /** Copying a live native session would create two managers for one transaction state. */
    public function __clone() { throw new \LogicException('Create another Mix Redis connection instead of cloning a live driver.'); }

    /** Live sockets and owner identities cannot be serialized into a reusable connection. */
    public function __serialize(): array { throw new \LogicException('Mix Redis drivers cannot be serialized.'); }
}
