<?php

namespace RC\Helper\Db\mix\Database;

/** Mix-derived owned transaction; modified by RC for rollback-on-abandon and exception-safe release. */
class Transaction extends Connection
{
    private bool $active = false;

    /** A failed constructor never adopts or rolls back another transaction's PDO state. */
    public function __construct(Driver $driver, ?LoggerInterface $logger)
    {
        parent::__construct($driver, $logger);
        $driver->beginOwnedTransaction($this);
        $this->active = true;
    }

    /** Commit once; if it fails, clean up our state without replacing the original commit exception. */
    public function commit()
    {
        if (!$this->active) { throw new \LogicException('Mix transaction is already closed.'); }
        try {
            $this->driver->assertTransactionAccess($this);
            $this->cacheIdentity();
            $this->driver->assertTransactionAccess($this);
            $this->closeStatement();
            $pdo = $this->driver->instance();
            if (!$pdo->commit()) { throw \RC\Helper\Db\PdoFailure::fromPdo($pdo, 'Commit Mix transaction failed.'); }
        } catch (\Throwable $error) {
            if (static::isDisconnectException($error)) { $this->driver->markDisconnected(); }
            try { $this->rollback(); } catch (\Throwable) {}
            throw $error;
        }
        $this->cacheIdentity();
        $this->finish();
    }

    /** Roll back if PDO is still active; a manual SQL rollback does not mask an earlier business error. */
    public function rollback()
    {
        if (!$this->active) { return; }
        $failure = null;
        try { $this->closeStatement(); }
        catch (\Throwable $error) { $failure = $error; }
        try {
            $pdo = $this->driver->instance();
            if ($pdo->inTransaction() && !$pdo->rollBack()) {
                throw \RC\Helper\Db\PdoFailure::fromPdo($pdo, 'Rollback Mix transaction failed.');
            }
        } catch (\Throwable $error) {
            $failure ??= $error;
        } finally {
            if ($failure !== null) { $this->driver->markDisconnected(); }
            $this->finish();
        }
        if ($failure !== null) { throw $failure; }
    }

    /** Closing an owned transaction means rollback, not closing a manager's shared PDO. */
    public function close(): void { $this->rollback(); }

    /** Abandoning a transaction must roll back even when another reference still retains its PDO. */
    public function __destruct()
    {
        if (!$this->active) { $this->driver = null; return; }
        try { $this->rollback(); }
        catch (\Throwable) { error_log('Mix abandoned transaction rollback failed.'); }
    }

    /** Release the lease exactly once; cleanup errors cannot turn a confirmed commit into an apparent failed write. */
    private function finish(): void
    {
        if (!$this->active) { return; }
        $driver = $this->driver;
        $this->active = false;
        $driver->releaseTransaction($this);
        $this->driver = new EmptyDriver();
        if (isset($driver->pool)) {
            $discard = $driver->isDisconnected();
            try {
                if ($discard) { $driver->__discard(); }
                elseif (!$driver->__return()) { $discard = true; }
            } catch (\Throwable) {
                $discard = true;
                $driver->markDisconnected();
                error_log('Mix transaction pool cleanup failed.');
            } finally {
                if ($discard) {
                    try { $driver->close(); }
                    catch (\Throwable) { error_log('Mix transaction driver close failed.'); }
                }
            }
        }
    }
}
