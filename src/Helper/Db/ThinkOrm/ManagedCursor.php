<?php

declare(strict_types=1);

namespace RC\Helper\Db\ThinkOrm;

use Closure;
use RC\Helper\Db\PdoFailure;
use think\db\BaseQuery;
use Throwable;

/** Cursor-owned PDO statements survive nested buffered reads and close when iteration ends or is released. */
trait ManagedCursor
{
    /** Keep native row/model hydration, but never fetch through the connection's replaceable statement property. */
    public function getCursor(BaseQuery $query, string $sql, $model = null)
    {
        $statement = $this->queryPDOStatement($query,$sql);
        $completed = false;
        $failure = null;
        try {
            while ($row = $statement->fetch($this->fetchType)) {
                yield $model ? $model->newInstance($row) : $row;
            }
            $completed = true;
        } catch (Throwable $error) {
            $failure = $error;
            throw $error;
        } finally {
            $this->rcCursorCleanup(static function () use ($statement): void {
                if (!$statement->closeCursor()) {
                    throw PdoFailure::fromPdo($statement,'Cannot close the ThinkORM cursor result.');
                }
            },$completed,$failure);
        }
    }

    /** Completion errors remain observable; cancellation cleanup must not replace an external consumer exception. */
    private function rcCursorCleanup(Closure $cleanup, bool $completed, ?Throwable $failure): void
    {
        try { $cleanup(); }
        catch (Throwable $error) {
            if ($completed && $failure === null) { throw $error; }
            // An aborted generator cannot inspect the consumer's exception; report cleanup without masking it.
            @error_log('RCMAKER ThinkORM cursor cleanup failed during interrupted iteration (' . $error::class . ').');
        }
    }
}
