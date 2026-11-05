<?php

namespace RC\Helper\Db\mix\Database;

/** Mix-derived result wrapper, modified by RC to remove recursive SQL replay and relative callables. */
class Connection extends AbstractConnection
{
    /** Preserve the public fetch API while retaining base cursor ownership. */
    public function queryOne(?int $fetchStyle = null) { return $this->call(__FUNCTION__, func_get_args()); }

    /** Fetch all remaining rows and release only this query's resources. */
    public function queryAll(?int $fetchStyle = null): array { return $this->call(__FUNCTION__, func_get_args()); }

    /** Fetch a complete column without treating falsy values as EOF. */
    public function queryColumn(int $columnNumber = 0): array { return $this->call(__FUNCTION__, func_get_args()); }

    /** Fetch the next scalar through the same no-replay error boundary. */
    public function queryScalar() { return $this->call(__FUNCTION__); }

    /** The base executes SQL once; no connection error is permission to repeat it. */
    public function execute(): ConnectionInterface { return $this->call(__FUNCTION__); }

    /** Transaction ownership transfers only on successful PDO begin. */
    public function beginTransaction(): Transaction { return $this->call(__FUNCTION__); }

    /** Metadata belongs to this result rather than a later borrower. */
    public function rowCount(): int { return $this->call(__FUNCTION__); }

    /** Use PHP's bound parent dispatch, not deprecated parent strings or unbounded recursive retries. */
    protected function call($name, $arguments = [])
    {
        try { return parent::{$name}(...$arguments); }
        catch (\Throwable $error) {
            if (static::isDisconnectException($error) && $this->driver && !$this->driver instanceof EmptyDriver) {
                $this->driver->markDisconnected();
            }
            throw $error;
        }
    }

    /** Destructors cannot throw over a business exception; close cursors before returning pooled drivers. */
    public function __destruct()
    {
        if (!$this->driver || $this->driver instanceof EmptyDriver) { return; }
        try {
            if (isset($this->driver->pool)) {
                $discard = $this->failed || $this->driver->isDisconnected();
                try { $discard = $discard || $this->inTransaction(); }
                catch (\Throwable) { $discard = true; $this->driver->markDisconnected(); }
                $this->releaseResult($discard);
            } else { $this->closeStatement(); }
        } catch (\Throwable $error) {
            if (static::isDisconnectException($error)) { $this->driver->markDisconnected(); }
            error_log('Mix query cleanup failed.');
        }
        finally { $this->driver = new EmptyDriver(); }
    }
}
