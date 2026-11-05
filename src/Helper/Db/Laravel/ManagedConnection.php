<?php

namespace RC\Helper\Db\Laravel;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Throwable;

/** Native SQL connections retain their builders/grammars; this trait owns only RC tracing and lost-query handling. */
trait ManagedConnection
{
    private array $rcTraces = [];
    private bool $rcLostTransaction = false;

    /** Prefer native status over user/query text, while honoring an explicitly bound Illuminate detector. */
    protected function causedByLostConnection(Throwable $e)
    {
        if (\Illuminate\Container\Container::getInstance()->bound(\Illuminate\Contracts\Database\LostConnectionDetector::class)) {
            return parent::causedByLostConnection($e);
        }
        $native = $e instanceof QueryException ? $e->getPrevious() ?? $e : $e;
        return \RC\Helper\Db\PdoFailure::disconnectState($native) ?? parent::causedByLostConnection($native);
    }

    /** Internal named-binding bridge. Ordinary positional Laravel queries never enter this path. */
    public function normalizeRcmakerParameters(string $sql, array $values, bool $useReadPdo): array
    {
        $this->assertRcmakerTransaction();
        $driver = $this->getDriverName();
        $single = $double = false;
        if (str_contains($sql, '\\') && in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
            $this->reconnectIfMissingConnection();
            $pdo = $useReadPdo ? $this->getReadPdo() : $this->getPdo();
            try {
                [$single, $double] = \RC\Helper\Db\SqlParameters::escapeMode($pdo, $driver);
            } catch (Throwable $error) {
                if ($this->causedByLostConnection($error)) { $this->disconnect(); }
                throw $error;
            }
        }
        return \RC\Helper\Db\SqlParameters::toPositional($sql, $values, $driver, $single, $double);
    }

    /** Start a bounded trace for one SDB operation without enabling or clearing native query logs. */
    public function beginRcmakerTrace(): object
    {
        $scope = (object) ['query' => null, 'bindings' => []];
        $this->rcTraces[] = $scope;
        return $scope;
    }

    /** Finish exactly the owning trace; debugging cannot replace a successful result or its original exception. */
    public function endRcmakerTrace(object $scope): ?string
    {
        if (!$this->rcTraces || $this->rcTraces[array_key_last($this->rcTraces)] !== $scope) {
            throw new \LogicException('Laravel trace scopes must finish in ownership order.');
        }
        array_pop($this->rcTraces);
        if ($scope->query === null) {
            return null;
        }
        try {
            // Match native quote routing without resolving a lazy or disconnected PDO just for diagnostics.
            $quotePdo = $this->getRawReadPdo();
            if ($this->transactions > 0 || $this->readOnWriteConnection
                || ($this->recordsModified && $this->getConfig('sticky')) || !$quotePdo) {
                $quotePdo = $this->getRawPdo();
            }
            if (!($quotePdo instanceof \PDO)) {
                foreach ($this->prepareBindings($scope->bindings) as $binding) {
                    if ($binding !== null && !is_bool($binding) && !is_int($binding) && !is_float($binding)) {
                        return $scope->query;
                    }
                }
            }
            return (new QueryExecuted($scope->query, $scope->bindings, 0, $this))->toRawSql();
        } catch (Throwable) {
            return $scope->query;
        }
    }

    /** Preserve native execution and observer behavior, with no extra clock/logging work when tracing is disabled. */
    protected function run($query, $bindings, Closure $callback)
    {
        $this->assertRcmakerTransaction();
        if ($this->rcTraces) {
            $scope = $this->rcTraces[array_key_last($this->rcTraces)];
            $scope->query = $query;
            $scope->bindings = $bindings;
        }
        return parent::run($query, $bindings, $callback);
    }

    /** Never reconnect and replay a business query whose server-side outcome may be unknown. */
    protected function handleQueryException(QueryException $error, $query, $bindings, Closure $callback)
    {
        if ($this->causedByLostConnection($error->getPrevious() ?? $error)) {
            $this->disconnect();
        }
        throw $error;
    }

    /** Lazy cursor fetch errors happen outside native run(); discard the failed session without replaying rows. */
    public function cursor($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        try { yield from parent::cursor($query, $bindings, $useReadPdo, $fetchUsing); }
        catch (Throwable $error) {
            if ($this->causedByLostConnection($error)) { $this->disconnect(); }
            throw $error;
        }
    }

    /** Closing an active transaction invalidates it; a fresh connection must not silently continue that transaction. */
    public function disconnect()
    {
        $level = $this->transactionLevel();
        if ($this->transactionsManager instanceof TransactionsManager) {
            $this->transactionsManager->discardRcmakerTransactions($this->getName());
        }
        parent::disconnect();
        if ($level > 0) {
            $this->transactions = $level;
            $this->rcLostTransaction = true;
        }
    }

    /** Native begin notifications may fail after opening a level; release only that newly opened scope. */
    public function beginTransaction()
    {
        $this->assertRcmakerTransaction();
        $level = $this->transactionLevel();
        try {
            return parent::beginTransaction();
        } catch (Throwable $error) {
            if ($this->transactionLevel() > $level) {
                try { $this->rollBack($level); } catch (Throwable) {}
            }
            throw $error;
        }
    }

    /** A dead transaction cannot register a callback that native state would mistake for already committed. */
    public function afterCommit($callback)
    {
        $this->assertRcmakerTransaction();
        if ($this->transactionsManager instanceof TransactionsManager) {
            return $this->transactionsManager->addRcmakerCallback($this->getName(), $callback);
        }
        return parent::afterCommit($callback);
    }

    /** A lost scope cannot register a native rollback callback as if its outcome were confirmed. */
    public function afterRollBack($callback)
    {
        $this->assertRcmakerTransaction();
        if ($this->transactionsManager instanceof TransactionsManager) {
            return $this->transactionsManager->addRcmakerCallback($this->getName(), $callback, true);
        }
        return parent::afterRollBack($callback);
    }

    /** Do not retry even BEGIN implicitly; a later explicit new operation may reconnect. */
    protected function handleBeginTransactionException(Throwable $error)
    {
        if ($this->causedByLostConnection($error)) {
            $this->disconnect();
        }
        throw $error;
    }

    /** Manual commit failures retain an invalid scope until explicit rollback; commit acknowledgement may be lost. */
    public function commit()
    {
        $this->assertRcmakerTransaction();
        try {
            return parent::commit();
        } catch (Throwable $error) {
            if ($this->causedByLostConnection($error)) {
                $this->disconnect();
            }
            throw $error;
        }
    }

    /** A dead session has no usable savepoint; only a whole local rollback can release its invalid scope. */
    public function rollBack($toLevel = null)
    {
        if ($this->rcLostTransaction) {
            if ($toLevel !== null && $toLevel !== 0) {
                throw new \LogicException('A lost Laravel transaction requires rollBack(0).');
            }
            $this->resetRcmakerTransaction();
            return;
        }
        return parent::rollBack($toLevel);
    }

    /** Preserve a callback's original error if native rollback or its observers also fail. */
    protected function handleTransactionException(Throwable $e, $currentAttempt, $maxAttempts)
    {
        if ($this->causedByConcurrencyError($e) && $this->transactions > 1) {
            // Discard inner writes before a caller catches the deadlock and continues its outer transaction.
            try { $this->performRollBack($this->transactions - 1); } catch (Throwable) { throw $e; }
            --$this->transactions;
            try { $this->transactionsManager?->rollback($this->getName(), $this->transactions); } catch (Throwable) { throw $e; }
            throw new \Illuminate\Database\DeadlockException($e->getMessage(), is_int($e->getCode()) ? $e->getCode() : 0, $e);
        }
        try { $this->rollBack(); } catch (Throwable) { throw $e; }
        if ($this->causedByConcurrencyError($e) && $currentAttempt < $maxAttempts) { return; }
        throw $e;
    }

    /** Native closure commits own their scope: clean a failed commit before returning control or retrying a known concurrency error. */
    protected function handleCommitTransactionException(Throwable $error, $currentAttempt, $maxAttempts)
    {
        if ($this->causedByLostConnection($error)) {
            $this->disconnect();
            try { $this->resetRcmakerTransaction(); } catch (Throwable) {}
            throw $error;
        }
        try { $this->rollBack(); } catch (Throwable) { throw $error; }
        if ($this->causedByConcurrencyError($error) && $currentAttempt < $maxAttempts) { return; }
        throw $error;
    }

    /** Native rollback SQL/ownership stays intact; a failed cleanup invalidates the old session instead of continuing it. */
    protected function performRollBack($toLevel)
    {
        try {
            $pdo = $this->getPdo();
            if ($toLevel === 0) {
                if ($pdo->inTransaction() && $pdo->rollBack() === false) {
                    throw \RC\Helper\Db\PdoFailure::fromPdo($pdo, 'Could not roll back the Laravel transaction.');
                }
            } elseif ($this->queryGrammar->supportsSavepoints()) {
                if ($pdo->exec($this->queryGrammar->compileSavepointRollBack('trans'.($toLevel + 1))) === false) {
                    throw \RC\Helper\Db\PdoFailure::fromPdo($pdo, 'Could not roll back the Laravel savepoint.');
                }
            }
        } catch (Throwable $error) {
            $this->disconnect();
            throw $error;
        }
    }

    /** A failed rollback also discards that session, without issuing commands on a replacement connection. */
    protected function handleRollBackException(Throwable $error)
    {
        if ($this->causedByLostConnection($error)) {
            $this->disconnect();
            try { $this->resetRcmakerTransaction(); } catch (Throwable) {}
            throw $error;
        }
        return parent::handleRollBackException($error);
    }

    /** Prevent writes and nested BEGIN/COMMIT after the owning server transaction has been lost. */
    private function assertRcmakerTransaction(): void
    {
        if ($this->rcLostTransaction) {
            throw new \LogicException('Laravel transaction connection was lost; roll back before a new operation.');
        }
    }

    /** Clear native transaction callbacks together with the failed local scope. */
    private function resetRcmakerTransaction(): void
    {
        $this->transactions = 0;
        $this->rcLostTransaction = false;
        if ($this->transactionsManager instanceof TransactionsManager) {
            $this->transactionsManager->discardRcmakerTransactions($this->getName());
        } else {
            $this->transactionsManager?->rollback($this->getName(), 0);
        }
    }
}
