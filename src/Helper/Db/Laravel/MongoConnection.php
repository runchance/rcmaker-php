<?php

namespace RC\Helper\Db\Laravel;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use MongoDB\Laravel\CommandSubscriber;
use MongoDB\Driver\Session;
use Throwable;

/** Optional native MongoDB connection with RC-owned bounded tracing and failed-transaction cleanup. */
class MongoConnection extends \MongoDB\Laravel\Connection
{
    private array $rcTraces = [];
    private ?CommandSubscriber $rcTraceSubscriber = null;
    private int $rcTransactionSequence = 0;
    private bool $rcCommitFailed = false;
    private array $rcClosureScopes = [];
    private ?Closure $rcDisconnectHandler = null;

    /** Internal ownership hook from the RC manager; this is not a business transaction/result callback. */
    public function setRcmakerDisconnectHandler(Closure $handler): void
    {
        $this->rcDisconnectHandler = $handler;
    }

    /** A failed begin notification may release only the scope that this call actually started. */
    public function beginTransaction(array $options = []): void
    {
        $this->assertRcmakerTransaction();
        foreach ($this->beforeStartingTransaction as $beforeTransactionCallback) { $beforeTransactionCallback($this); }
        $session = $this->getRcmakerSession();
        $session->startTransaction($options);
        $scope = ++$this->rcTransactionSequence;
        try {
            $this->transactions = 1;
            $this->transactionsManager?->begin($this->getName(), 1);
            $this->fireConnectionEvent('beganTransaction');
        } catch (Throwable $error) {
            if ($scope === $this->rcTransactionSequence && $this->transactions > 0) {
                try { $this->clearFailedRcmakerTransaction($session); } catch (Throwable) {}
            }
            throw $error;
        }
    }

    /** Retain native commit-only retries; an unresolved result cannot accept ordinary queries or a new BEGIN. */
    public function commit(): void
    {
        $session = $this->session;
        $scope = $this->rcTransactionSequence;
        $level = $this->transactions;
        $this->fireConnectionEvent('committing');
        if ($scope !== $this->rcTransactionSequence || $session !== $this->session || $level !== $this->transactions) {
            throw new \LogicException('MongoDB transaction changed during its committing notification.');
        }
        if ($session === null) { throw new \MongoDB\Driver\Exception\RuntimeException('There is no active session.'); }
        try {
            $session->commitTransaction();
        } catch (Throwable $error) {
            if ($level > 0) { $this->rcCommitFailed = true; }
            throw $error;
        }
        $this->rcCommitFailed = false;
        $this->transactions = max(0, $level - 1);
        $this->completeRcmakerScope($session);
        $this->transactionsManager?->commit($this->getName(), $level, $this->transactions);
        $this->fireConnectionEvent('committed');
    }

    /** An uncertain manual commit can be abandoned locally, but cannot be reported as a confirmed rollback. */
    public function rollBack($toLevel = null): void
    {
        $session = $this->session;
        if ($session === null) { throw new \MongoDB\Driver\Exception\RuntimeException('There is no active session.'); }
        if ($this->rcCommitFailed) { $this->discardRcmakerScope($session); return; }
        try {
            if ($session->isInTransaction()) { $session->abortTransaction(); }
            $state = $session->getTransactionState();
        } catch (Throwable $error) {
            $this->discardRcmakerScope($session);
            throw $error;
        }
        if ($this->transactions > 0 && $state !== Session::TRANSACTION_ABORTED) {
            $this->discardRcmakerScope($session);
            return;
        }
        $this->transactions = 0;
        $this->completeRcmakerScope($session);
        $this->transactionsManager?->rollback($this->getName(), 0);
        $this->fireConnectionEvent('rollingBack');
    }

    /** Register on this MongoDB connection, not a different connection's latest transaction. */
    public function afterCommit($callback)
    {
        if ($this->transactionsManager instanceof TransactionsManager) {
            return $this->transactionsManager->addRcmakerCallback($this->getName(), $callback);
        }
        return parent::afterCommit($callback);
    }

    /** A connection without its own transaction has no rollback callback scope. */
    public function afterRollBack($callback)
    {
        if ($this->transactionsManager instanceof TransactionsManager) {
            return $this->transactionsManager->addRcmakerCallback($this->getName(), $callback, true);
        }
        return parent::afterRollBack($callback);
    }

    /** Begin one SDB trace without enabling native query logging or registering global event listeners. */
    public function beginRcmakerTrace(): object
    {
        $this->assertRcmakerTransaction();
        $scope = (object) ['query' => null];
        $this->rcTraces[] = $scope;
        try { $this->synchronizeRcmakerSubscriber(); }
        catch (Throwable $error) { array_pop($this->rcTraces); throw $error; }
        return $scope;
    }

    /** Finish only the owning scope; upstream renders canonical Extended JSON, not SQL interpolation. */
    public function endRcmakerTrace(object $scope): ?string
    {
        if (!$this->rcTraces || $this->rcTraces[array_key_last($this->rcTraces)] !== $scope) {
            throw new \LogicException('MongoDB trace scopes must finish in ownership order.');
        }
        array_pop($this->rcTraces);
        $this->synchronizeRcmakerSubscriber();
        return $scope->query;
    }

    /** Keep upstream monitoring for foreign consumers; attach a temporary native subscriber only when needed. */
    public function syncCommandSubscriber(): void
    {
        $this->assertRcmakerTransaction();
        $this->synchronizeRcmakerSubscriber();
    }

    /** Tracing cleanup must detach monitoring even when a failed/closed transaction rejects new business commands. */
    private function synchronizeRcmakerSubscriber(): void
    {
        parent::syncCommandSubscriber();
        $watched = $this->loggingQueries || (bool) $this->events?->hasListeners(QueryExecuted::class);
        if ($this->connection !== null && $this->rcTraces && !$watched) {
            if ($this->rcTraceSubscriber === null) {
                $this->rcTraceSubscriber = new CommandSubscriber($this);
                $this->connection->addSubscriber($this->rcTraceSubscriber);
            }
        } elseif ($this->rcTraceSubscriber !== null) {
            $this->connection?->removeSubscriber($this->rcTraceSubscriber);
            $this->rcTraceSubscriber = null;
        }
    }

    /** Native command monitoring reaches this hook once, with existing event/log behavior unchanged. */
    public function logQuery($query, $bindings, $time = null)
    {
        if ($this->rcTraces) { $this->rcTraces[array_key_last($this->rcTraces)]->query = $query; }
        return parent::logQuery($query, $bindings, $time);
    }

    /** Detach only RC monitoring after native log cleanup, which may attach a final trace subscriber. */
    public function disconnect()
    {
        $session = $this->session;
        $this->transactions = 0;
        $this->rcCommitFailed = false;
        if ($session !== null) { $this->completeRcmakerScope($session); }
        if ($this->transactionsManager instanceof TransactionsManager) { $this->transactionsManager->discardRcmakerTransactions($this->getName()); }
        try { $session?->endSession(); } catch (Throwable) {}
        $this->session = null;
        $client = $this->connection;
        $failure = null;
        try { if ($client !== null) { parent::disconnect(); } }
        catch (Throwable $error) { $failure = $error; }
        if ($this->rcTraceSubscriber !== null) {
            $subscriber = $this->rcTraceSubscriber;
            $this->rcTraceSubscriber = null;
            try { $client?->removeSubscriber($subscriber); }
            catch (Throwable $error) { $failure ??= $error; }
        }
        try { ($this->rcDisconnectHandler)?->__invoke($this); }
        catch (Throwable $error) { $failure ??= $error; }
        if ($failure !== null) { throw $failure; }
    }

    /** Use native transaction retries while keeping failed attempts and postcommit callbacks in distinct scopes. */
    public function transaction(Closure $callback, $attempts = 1, array $options = []): mixed
    {
        if ($this->transactionLevel() > 0 || $this->getSession()?->isInTransaction()) {
            throw new \LogicException('MongoDB does not support nested transactions.');
        }
        $this->assertRcmakerTransaction();
        $session = $this->getRcmakerSession();
        $context = (object) ['session' => $session, 'scope' => $this->rcTransactionSequence, 'completed' => false];
        $this->rcClosureScopes[] = $context;
        $attemptsLeft = $attempts;
        $attempt = 0;
        $callbackResult = null;
        $callbackError = null;
        $limitReached = false;
        $run = function (Session $session) use ($callback, $context, &$attemptsLeft, &$attempt, &$callbackResult, &$callbackError, &$limitReached): void {
            if (--$attemptsLeft < 0) {
                $session->abortTransaction();
                $limitReached = true;
                return;
            }
            if ($attempt++ > 0 && $this->transactionsManager instanceof TransactionsManager) {
                $this->transactionsManager->discardRcmakerTransactions($this->getName());
            }
            $callbackError = null;
            $context->scope = ++$this->rcTransactionSequence;
            $context->completed = false;
            try {
                foreach ($this->beforeStartingTransaction as $beforeTransactionCallback) { $beforeTransactionCallback($this); }
                if ($context->completed) { throw new \LogicException('MongoDB transaction completed before its callback started.'); }
                $this->transactions = 1;
                $this->transactionsManager?->begin($this->getName(), 1);
                $this->fireConnectionEvent('beganTransaction');
                $callbackResult = $callback($this);
                if (!$context->completed) { $this->fireConnectionEvent('committing'); }
            } catch (Throwable $error) {
                $callbackError = $error;
                if ($context->completed && $error instanceof \MongoDB\Driver\Exception\RuntimeException) {
                    throw new \RuntimeException('MongoDB transaction was already completed inside its callback.', 0, $error);
                }
                throw $error;
            }
        };
        try {
            try {
                \MongoDB\with_transaction($session, $run, $options);
            } catch (Throwable $error) {
                if (!$context->completed && $context->scope === $this->rcTransactionSequence) {
                    try { $this->clearFailedRcmakerTransaction($session); } catch (Throwable) {}
                }
                throw $callbackError ?? $error;
            }
            if ($context->completed) { return $callbackResult; }
            if ($limitReached) {
                if ($callbackError !== null) {
                    try { $this->clearFailedRcmakerTransaction($session, true); } catch (Throwable) {}
                    throw $callbackError;
                }
                $this->clearFailedRcmakerTransaction($session, true);
            }
        } finally {
            array_pop($this->rcClosureScopes);
            if ($this->session !== $session) { try { $session->endSession(); } catch (Throwable) {} }
        }

        // The driver has returned successfully. Callback failures must not abort a new transaction opened by that callback.
        [$levelBeingCommitted, $this->transactions] = [$this->transactions, max(0, $this->transactions - 1)];
        $this->transactionsManager?->commit($this->getName(), $levelBeingCommitted, $this->transactions);
        $this->fireConnectionEvent('committed');
        return $callbackResult;
    }

    /** Release only a failed closure's scope; an unconfirmed outcome cannot emit either success callback. */
    private function clearFailedRcmakerTransaction(Session $session, bool $notifyRollback = false): void
    {
        $aborted = false;
        try {
            if ($session->isInTransaction()) { $session->abortTransaction(); }
            $aborted = $session->getTransactionState() === Session::TRANSACTION_ABORTED;
        } catch (Throwable) {}
        if (!$aborted) {
            try { $session->endSession(); } catch (Throwable) {}
            if ($this->session === $session) { $this->session = null; }
        }
        $this->transactions = 0;
        $this->rcCommitFailed = false;
        $this->completeRcmakerScope($session);
        if ($aborted) {
            $this->transactionsManager?->rollback($this->getName(), 0);
            if ($notifyRollback) { $this->fireConnectionEvent('rollingBack'); }
        } elseif ($this->transactionsManager instanceof TransactionsManager) {
            $this->transactionsManager->discardRcmakerTransactions($this->getName());
        }
    }

    /** Native builders call syncCommandSubscriber() for every command, including builders created before a failure. */
    private function assertRcmakerTransaction(): void
    {
        if ($this->connection === null) { throw new \LogicException('MongoDB connection is disconnected; obtain a new managed connection.'); }
        if ($this->rcCommitFailed || ($this->transactions > 0 && !$this->session?->isInTransaction())) {
            throw new \LogicException('MongoDB transaction is unresolved; retry its commit or roll back the local scope before a new operation.');
        }
    }

    /** A manually finished closure keeps its captured session separate from a callback's new transaction. */
    private function getRcmakerSession(): Session
    {
        $context = $this->rcClosureScopes ? $this->rcClosureScopes[array_key_last($this->rcClosureScopes)] : null;
        if ($context?->completed && $this->session === $context->session) { $this->session = null; }
        return $this->session ??= $this->getClient()->startSession();
    }

    /** Mark only the owning closure as complete; an independent transaction remains owned by its caller. */
    private function completeRcmakerScope(Session $session): void
    {
        $context = $this->rcClosureScopes ? $this->rcClosureScopes[array_key_last($this->rcClosureScopes)] : null;
        if ($context && $context->session === $session && $context->scope === $this->rcTransactionSequence) { $context->completed = true; }
    }

    /** Discard uncertain records without claiming rollback or replacing a caller's exception. */
    private function discardRcmakerScope(Session $session): void
    {
        $this->transactions = 0;
        $this->rcCommitFailed = false;
        $this->completeRcmakerScope($session);
        if ($this->session === $session) { $this->session = null; }
        try { $session->endSession(); } catch (Throwable) {}
        if ($this->transactionsManager instanceof TransactionsManager) { $this->transactionsManager->discardRcmakerTransactions($this->getName()); }
    }
}
