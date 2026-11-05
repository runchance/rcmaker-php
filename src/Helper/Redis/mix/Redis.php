<?php

namespace RC\Helper\Redis\mix;

use RC\Helper\Redis\mix\Pool\ConnectionPool;
use RC\Helper\Redis\mix\Pool\Dialer;

/** Legacy Mix manager; one synchronous session per manager, with explicit exclusive batch ownership. */
class Redis implements ConnectionInterface
{
    use ScanTrait;

    protected $driver;
    protected $pool;
    protected $logger;
    protected array $configuration;
    protected $host = '';
    protected $port = 6379;
    protected $password = '';
    protected $database = 0;
    protected $timeout = 5.0;
    protected $retryInterval = 0;
    protected $readTimeout = -1;
    protected $maxOpen = -1;
    protected $maxIdle = -1;
    protected $maxLifetime = 0;
    protected $waitTimeout = 0.0;

    /** Existing positional calls work; the final options array passes through extended framework configuration. */
    public function __construct(string $host, int $port = 6379, string|array $password = '', int $database = 0,
        float $timeout = 5.0, int $retryInterval = 0, ?float $readTimeout = null, array $options = [])
    {
        $this->configure($options + ['host' => $host, 'port' => $port, 'password' => $password,
            'database' => $database, 'timeout' => $timeout, 'retryInterval' => $options['retry_interval'] ?? $retryInterval,
            'readTimeout' => $readTimeout ?? $timeout]);
        $this->driver = new Driver($host, $port, $password, $database, $timeout, $retryInterval, $readTimeout, $options);
    }

    /** Preserve legacy protected extension fields without discarding ACL/TLS or modern configuration aliases. */
    protected function configure(array $configuration): void
    {
        $this->configuration = $configuration;
        $this->host = $configuration['host'] ?? '127.0.0.1';
        $this->port = $configuration['port'] ?? 6379;
        $this->password = $configuration['password'] ?? '';
        $this->database = $configuration['database'] ?? 0;
        $this->timeout = $configuration['timeout'] ?? 5;
        $this->retryInterval = $configuration['retryInterval'] ?? $configuration['retry_interval'] ?? 0;
        $this->readTimeout = $configuration['read_timeout'] ?? $configuration['readTimeout'] ?? $this->timeout;
    }

    /** Validate and construct first so unavailable Swoole support cannot destroy the working synchronous driver. */
    protected function createPool()
    {
        if (($this->driver && $this->driver->hasOwner()) || ($this->pool && $this->pool->stats()['active'] > 0)) {
            throw new \LogicException('Cannot replace a Mix Redis pool with active connections or batches.');
        }
        $configuration = array_replace($this->configuration, ['host' => $this->host, 'port' => $this->port,
            'password' => $this->password, 'database' => $this->database, 'timeout' => $this->timeout,
            'retryInterval' => $this->retryInterval, 'read_timeout' => $this->readTimeout]);
        $pool = new ConnectionPool(new Dialer('', 6379, '', 0, 5.0, 0, null, $configuration),
            $this->maxOpen, $this->maxIdle, $this->maxLifetime, $this->waitTimeout);
        if ($this->driver) { $this->driver->close(); $this->driver = null; }
        $this->pool = $pool;
    }

    /** Legacy Swoole-only pool; this does not activate Workerman coroutines. */
    public function startPool(int $maxOpen, int $maxIdle, int $maxLifetime = 0, float $waitTimeout = 0.0)
    {
        $previous = [$this->maxOpen, $this->maxIdle, $this->maxLifetime, $this->waitTimeout];
        [$this->maxOpen, $this->maxIdle, $this->maxLifetime, $this->waitTimeout] = [$maxOpen, $maxIdle, $maxLifetime, $waitTimeout];
        try { $this->createPool(); }
        catch (\Throwable $error) {
            [$this->maxOpen, $this->maxIdle, $this->maxLifetime, $this->waitTimeout] = $previous;
            throw $error;
        }
    }

    /** Recreate only after validating the requested active limit. */
    public function setMaxOpenConns(int $value)
    {
        if ($value !== $this->maxOpen) { $this->startPool($value, $this->maxIdle, $this->maxLifetime, $this->waitTimeout); }
    }

    /** Recreate only after validating the requested idle limit. */
    public function setMaxIdleConns(int $value)
    {
        if ($value !== $this->maxIdle) { $this->startPool($this->maxOpen, $value, $this->maxLifetime, $this->waitTimeout); }
    }

    /** Preserve the existing Mix Redis lifetime setter name. */
    public function setConnMaxLifetime(int $value)
    {
        if ($value !== $this->maxLifetime) { $this->startPool($this->maxOpen, $this->maxIdle, $value, $this->waitTimeout); }
    }

    /** Recreate only after validating a finite nonnegative wait timeout. */
    public function setPoolWaitTimeout(float $value)
    {
        if ($value !== $this->waitTimeout) { $this->startPool($this->maxOpen, $this->maxIdle, $this->maxLifetime, $value); }
    }

    /** Empty statistics mean synchronous mode, not an implicitly running coroutine pool. */
    public function poolStats(): array { return $this->pool ? $this->pool->stats() : []; }

    /** Loggers are optional and do not add timing calls to the unobserved command path. */
    public function setLogger(LoggerInterface $logger) { $this->logger = $logger; }

    /** Recover only before a fresh command; refuse interleaving with an active transaction or pipeline. */
    protected function borrow(): Connection
    {
        $driver = $this->pool ? $this->pool->borrow() : $this->driver;
        $driver->prepareBorrow();
        return new Connection($driver, $this->logger);
    }

    /** WATCH begins an owned MULTI scope immediately, retaining the established legacy API. */
    public function watch(string ...$keys): Multi { return $this->borrow()->watch(...$keys); }

    /** Application commands in a transaction must use this returned object. */
    public function multi(): Multi { return $this->borrow()->multi(); }

    /** Standalone pipelining retains one connection until explicit exec() or cancellation. */
    public function pipeline(): Pipeline { return $this->borrow()->pipeline(); }

    /** Explicit reconnect is allowed only outside batches; never replay a failed command. */
    public function connect()
    {
        if ($this->pool) { throw new \LogicException('Reconnect individual pooled sessions through normal borrowing.'); }
        $this->driver->connect();
    }

    /** Explicit manager close cannot revoke another active transaction or pipeline. */
    public function close()
    {
        if ($this->pool) { throw new \LogicException('A coroutine pool cannot be closed through a borrowed native session.'); }
        $this->driver->close();
    }

    /** Ordinary native Redis data commands keep their arguments and actual return values. */
    public function __call($command, $arguments) { return $this->borrow()->__call($command, $arguments); }

    /** Use another helper connection ID instead of creating a second manager for the same native socket. */
    public function __clone() { throw new \LogicException('Mix Redis managers cannot be cloned; request another connection ID.'); }

    /** Managers contain live connections, not portable configuration-only values. */
    public function __serialize(): array { throw new \LogicException('Mix Redis managers cannot be serialized.'); }
}
