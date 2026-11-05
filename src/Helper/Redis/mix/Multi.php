<?php

namespace RC\Helper\Redis\mix;

/** Owned Mix MULTI/EXEC scope; abandoning it cancels queued commands rather than executing them. */
class Multi extends Connection
{
    protected bool $active = false;

    /** WATCH retains its legacy immediate-MULTI semantics; keys are supplied by the manager's watch() API. */
    public function __construct(Driver $driver, ?LoggerInterface $logger, bool $watch = false, array $keys = [])
    {
        parent::__construct($driver, $logger);
        try {
            if ($watch && $this->nativeMode() === \Redis::PIPELINE) {
                throw new \LogicException('WATCH cannot be combined with pipeline mode.');
            }
            if ($this->nativeMode() === \Redis::PIPELINE && $driver->instance() instanceof \RedisCluster) {
                throw new \LogicException('Native RedisCluster does not support pipelines; use MULTI or a standalone connection.');
            }
            if ($watch && $keys !== [] && $this->invoke('watch', [$keys], true) !== true) {
                throw new \RedisException('Redis WATCH was rejected.');
            }
            if ($this->invoke('multi', [$this->nativeMode()], true) === false) {
                throw new \RedisException('Redis could not enter the requested batch mode.');
            }
            $this->active = true;
        } catch (\Throwable $error) { $this->releaseConnection(true); throw $error; }
    }

    /** Pipeline overrides only its native mode, sharing the same ownership and cleanup implementation. */
    protected function nativeMode(): int { return \Redis::MULTI; }

    /** Queued native fluent results must stay inside the RC wrapper instead of leaking the raw client. */
    public function __call($command, $arguments = [])
    {
        $this->assertIdle();
        $driver = $this->driver;
        try {
            $result = $this->invoke($command, $arguments);
            return $result === $driver->instance() ? $this : $result;
        } catch (\Throwable $error) {
            $this->active = false;
            $this->releaseConnection(true);
            throw $error;
        }
    }

    /** Legacy WATCH already starts MULTI; a repeated fluent multi() keeps this owner rather than nesting. */
    public function multi(): Multi { $this->assertIdle(); $this->requireDriver(); return $this; }

    /** Switching an active transaction to pipeline can be a native fatal error; reject before native dispatch. */
    public function pipeline(): Pipeline { throw new \LogicException('Cannot switch an active Mix Redis batch to pipeline mode.'); }

    /** WATCH must be established before MULTI; never transfer an active owner's connection to another scope. */
    public function watch(string ...$keys): Multi { throw new \LogicException('WATCH must start from the Mix Redis manager.'); }

    /** Execute once; false remains a valid WATCH conflict, not permission to retry the batch. */
    public function exec()
    {
        $this->assertIdle();
        $this->requireDriver();
        try { return $this->invoke('exec', [], true); }
        catch (\Throwable $error) {
            $this->requireDriver()->markDisconnected();
            throw $error;
        } finally {
            $this->active = false;
            $this->releaseConnection();
        }
    }

    /** Cancel the whole queue and WATCH state; completed scopes return false without touching a later borrower. */
    public function discard()
    {
        $this->assertIdle();
        if (!$this->active) { return false; }
        try {
            $result = $this->invoke('discard', [], true);
            if ($result !== true) { throw new \RedisException('Redis did not acknowledge batch cancellation.'); }
            return true;
        }
        catch (\Throwable $error) {
            if ($this->driver instanceof Driver) { $this->driver->markDisconnected(); }
            throw $error;
        } finally {
            $this->active = false;
            $this->releaseConnection();
        }
    }

    /** Explicit scope close is cancellation, never EXEC. */
    public function close() { $this->discard(); }

    /** Cancel only this process's active owner; an inherited or stale destructor cannot touch another lease. */
    public function __destruct()
    {
        if ($this->invoking) { return; }
        if (!$this->active) { $this->releaseConnection(); return; }
        if (!$this->driver instanceof Driver || !$this->driver->isOwner($this)) { $this->driver = null; return; }
        try { $this->discard(); }
        catch (\Throwable) { try { error_log('Mix Redis abandoned batch cleanup failed.'); } catch (\Throwable) {} }
    }
}
