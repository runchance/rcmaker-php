<?php

namespace RC\Helper\Db\Laravel;

use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Support\Collection;

/** Framework-owned callback cleanup; native commit ordering and public registration APIs remain unchanged. */
class TransactionsManager extends \Illuminate\Database\DatabaseTransactionsManager
{
    /** Internal explicit-connection registration; manager-level addCallback() retains native ambient semantics. */
    public function addRcmakerCallback($connection, $callback, bool $rollback = false): void
    {
        $transactions = $this->callbackApplicableTransactions();
        $current = $transactions->last();
        if ($current && $current->connection !== $connection) {
            $current = $transactions->last(static fn ($transaction) => $transaction->connection === $connection);
        }
        if ($current) {
            if ($rollback) { $current->addCallbackForRollback($callback); }
            else { $current->addCallback($callback); }
        } elseif (!$rollback) {
            $callback();
        }
    }

    /** Internal uncertain-outcome cleanup: discard only this connection's records without claiming rollback. */
    public function discardRcmakerTransactions($connection): void
    {
        $this->detachConnection($connection);
    }

    /** Detach a rolled-back scope before invoking user callbacks, preserving outer and committed sibling records. */
    public function rollback($connection, $newTransactionLevel)
    {
        if ($newTransactionLevel === 0) {
            return $this->removeAllTransactionsForConnection($connection);
        }

        $this->pendingTransactions = $this->pendingTransactions->reject(
            fn ($transaction) => $transaction->connection == $connection && $transaction->level > $newTransactionLevel
        )->values();
        $records = [];
        for ($current = $this->currentTransaction[$connection] ?? null;
            $current !== null && $current->level > $newTransactionLevel; $current = $current->parent) {
            foreach ($this->detachChildren($current) as $child) { $records[] = $child; }
            $records[] = $current;
        }
        $this->currentTransaction[$connection] = $current;
        foreach ($records as $record) { $record->executeCallbacksForRollback(); }
    }

    /** Full rollback keeps native deepest-first callback order, but exceptions cannot retain aborted records. */
    protected function removeAllTransactionsForConnection($connection)
    {
        $this->detachConnection($connection)->each(fn ($transaction) => $transaction->executeCallbacksForRollback());
    }

    /** Remove registry state first so a callback may run an independent transaction without inheriting old callbacks. */
    private function detachConnection($connection): Collection
    {
        $records = $this->committedTransactions->filter(fn ($transaction) => $transaction->connection == $connection);
        for ($current = $this->currentTransaction[$connection] ?? null; $current !== null; $current = $current->parent) { $records->push($current); }
        $this->currentTransaction[$connection] = null;
        $this->pendingTransactions = $this->pendingTransactions->reject(fn ($transaction) => $transaction->connection == $connection)->values();
        $this->committedTransactions = $this->committedTransactions->reject(fn ($transaction) => $transaction->connection == $connection)->values();
        return $records->sortByDesc(fn ($transaction) => $transaction->level);
    }

    /** Collect committed descendants in native recursive order without executing callbacks while records remain attached. */
    private function detachChildren(DatabaseTransactionRecord $transaction): array
    {
        [$children, $this->committedTransactions] = $this->committedTransactions->partition(
            fn ($child) => $child->connection == $transaction->connection && $child->parent === $transaction
        );
        $records = [];
        foreach ($children as $child) { foreach ($this->detachChildren($child) as $descendant) { $records[] = $descendant; } }
        foreach ($children as $child) { $records[] = $child; }
        return $records;
    }
}
