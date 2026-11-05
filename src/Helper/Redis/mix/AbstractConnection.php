<?php

namespace RC\Helper\Redis\mix;

/** RC-derived Mix command scope with explicit ownership and no automatic command replay. */
abstract class AbstractConnection implements ConnectionInterface
{
    use ScanTrait;

    protected $driver;
    protected $logger;
    protected bool $invoking = false;

    /** Internal borrowed scope; ordinary application access starts from redis('mix'). */
    public function __construct(Driver $driver, ?LoggerInterface $logger)
    {
        $driver->acquire($this);
        $this->driver = $driver;
        $this->logger = $logger;
    }

    /** Explicit reconnect belongs to the manager, not a borrowed result or active batch. */
    public function connect() { throw new \LogicException('Reconnect through the Mix Redis manager, outside active scopes.'); }

    /** Closing a borrowed ordinary scope drops its session; Multi overrides this with discard(). */
    public function close() { $this->assertIdle(); $this->releaseConnection(true); }

    /** A command result cannot execute a transaction without acquiring an owned Multi or Pipeline. */
    public function exec() { throw new \LogicException('Start a Mix Redis multi() or pipeline() scope first.'); }

    /** WATCH is paired with immediate MULTI in the legacy API; abandon it with discard(). */
    public function unwatch() { throw new \LogicException('Use discard() to abandon an owned WATCH/MULTI scope.'); }

    /** Preserve legacy WATCH-then-MULTI behavior, including typed variadic key names. */
    public function watch(string ...$keys): Multi
    {
        if ($keys === []) { throw new \InvalidArgumentException('WATCH requires at least one key.'); }
        return $this->transfer(Multi::class, true, $keys);
    }

    /** Transfer the driver's lease, without temporarily returning it to a pool. */
    public function multi(): Multi { return $this->transfer(Multi::class); }

    /** Pipeline is a separate owned mode; no command is sent until exec(). */
    public function pipeline(): Pipeline { return $this->transfer(Pipeline::class); }

    /** Adopt the new scope before the old wrapper can recycle the driver's pool lease. */
    private function transfer(string $class, bool $watch = false, array $keys = []): Multi
    {
        $this->assertIdle();
        $driver = $this->requireDriver();
        $driver->release($this);
        $this->driver = null;
        return new $class($driver, $this->logger, $watch, $keys);
    }

    /** Dispatch exactly once; observer failures must not replace command errors or confirmed write results. */
    protected function invoke(string $command, array $arguments = [], bool $lifecycle = false): mixed
    {
        $this->assertIdle();
        $driver = $this->requireDriver();
        $this->invoking = true;
        $start = $this->logger ? microtime(true) : null;
        $error = null;
        try { return $driver->invoke($this, $command, $arguments, $lifecycle); }
        catch (\Throwable $failure) { $error = $failure; throw $failure; }
        finally {
            if ($this->logger) {
                try {
                    $safeArguments = strtolower($command) === 'auth' ? ['[redacted]'] : $arguments;
                    $this->logger->trace(round((microtime(true) - $start) * 1000, 2), $command, $safeArguments, $error);
                } catch (\Throwable) {
                    try { error_log('Mix Redis logger failed; original command outcome preserved.'); } catch (\Throwable) {}
                }
            }
            $this->invoking = false;
        }
    }

    /** Observer callbacks cannot submit, execute, cancel or transfer the scope currently being observed. */
    protected function assertIdle(): void
    {
        if ($this->invoking) { throw new \LogicException('Mix Redis scope is executing or observing a command.'); }
    }

    /** Reject finished/inherited scopes before accessing a native client or mutating pool state. */
    protected function requireDriver(): Driver
    {
        if (!$this->driver instanceof Driver || !$this->driver->isOwner($this)) {
            throw new \LogicException('Mix Redis scope is closed or no longer owns its connection.');
        }
        return $this->driver;
    }

    /** Release once; cleanup failures cannot overwrite an acknowledged business command or its original exception. */
    protected function releaseConnection(bool $discard = false): void
    {
        $driver = $this->driver;
        $this->driver = null;
        if (!$driver instanceof Driver || !$driver->release($this)) { return; }
        try {
            if (isset($driver->pool)) {
                if ($discard || $driver->requiresDiscard()) { $driver->close(); $driver->__discard(); }
                elseif (!$driver->__return()) { $driver->close(); }
            } elseif ($discard || $driver->requiresDiscard()) {
                $driver->close();
            }
        } catch (\Throwable) {
            $driver->markDisconnected();
            try { error_log('Mix Redis connection cleanup failed.'); } catch (\Throwable) {}
        }
    }

    /** Clones would hold an invalid duplicate owner identity. */
    public function __clone() { throw new \LogicException('Mix Redis scopes cannot be cloned.'); }

    /** Borrowed physical connection state cannot survive serialization. */
    public function __serialize(): array { throw new \LogicException('Mix Redis scopes cannot be serialized.'); }
}
