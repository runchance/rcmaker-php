<?php

namespace RC\Helper\Db\mix\Database;

require_once dirname(__DIR__) . '/bootstrap.php';

/** Stable Mix PDO driver with RC transaction ownership and fresh-query-only recovery. */
class Driver extends \Mix\Database\Driver
{
    private bool $disconnected = false;
    private ?int $transactionOwner = null;

    /** Reset fault state only after a replacement PDO has connected successfully. */
    public function connect()
    {
        if ($this->transactionOwner !== null) { throw new \LogicException('Cannot reconnect a driver owned by an active Mix transaction.'); }
        parent::connect();
        $this->disconnected = false;
    }

    /** Return this physical connection; an explicit close is never an implicit new query. */
    public function instance(): \PDO
    {
        if (!$this->pdo instanceof \PDO) { throw new \LogicException('Mix database driver is closed.'); }
        return $this->pdo;
    }

    /** Only a new manager borrow may replace a lost connection; the failed command is never replayed. */
    public function prepareBorrow(): void
    {
        if ($this->transactionOwner !== null) { throw new \LogicException('Use the supplied Mix transaction object until it commits or rolls back.'); }
        if ($this->disconnected) { $this->connect(); }
    }

    /** Remember an uncertain network result without retrying its SQL. */
    public function markDisconnected(): void { $this->disconnected = true; }

    /** Cleanup can distinguish a broken socket from an ordinary SQL error. */
    public function isDisconnected(): bool { return $this->disconnected; }

    /** Pool changes must not revoke a transaction's live driver. */
    public function hasTransactionOwner(): bool { return $this->transactionOwner !== null; }

    /** Retained builders cannot join a transaction that began after they were created. */
    public function assertTransactionAccess(object $owner): void
    {
        if ($this->transactionOwner !== null) {
            if ($this->transactionOwner !== spl_object_id($owner)) { throw new \LogicException('A different Mix transaction owns this driver.'); }
            if ($this->disconnected) { throw new \LogicException('Lost Mix transaction cannot execute or commit; roll it back.'); }
        }
    }

    /** Explicit close cannot invalidate another live transaction. */
    public function close()
    {
        if ($this->transactionOwner !== null) { throw new \LogicException('Cannot close a driver owned by an active Mix transaction.'); }
        parent::close();
    }

    /** Register ownership only after PDO actually starts a transaction; failed nesting cannot own the outer one. */
    public function beginOwnedTransaction(object $owner): void
    {
        if ($this->transactionOwner !== null) { throw new \PDOException('A Mix transaction already owns this driver.'); }
        $pdo = $this->instance();
        try {
            if (!$pdo->beginTransaction()) { throw \RC\Helper\Db\PdoFailure::fromPdo($pdo, 'Begin Mix transaction failed.'); }
        } catch (\Throwable $error) {
            if (\RC\Helper\Db\PdoFailure::isDisconnect($error)) { $this->markDisconnected(); }
            throw $error;
        }
        $this->transactionOwner = spl_object_id($owner);
    }

    /** Only the originating transaction can release its driver's lease. */
    public function releaseTransaction(object $owner): void
    {
        if ($this->transactionOwner === spl_object_id($owner)) { $this->transactionOwner = null; }
    }
}
