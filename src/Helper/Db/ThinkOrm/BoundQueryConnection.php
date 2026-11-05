<?php

declare(strict_types=1);

namespace RC\Helper\Db\ThinkOrm;

use RC\Helper\Db\PostgresSql;
use RC\Helper\Db\SqlParameters;

/** Preserve native execution/retry/transaction policy while selecting lexical modes from its actual PDO. */
trait BoundQueryConnection
{
    private array $rcBoundExecutions = [];

    /** Ordinary SQL keeps the native fast path; only owned subqueries or PostgreSQL CR comments need adaptation. */
    public function getPDOStatement(string $sql, array $bind = [], bool $master = false, bool $procedure = false): \PDOStatement
    {
        $original = isset($bind[BoundSubqueries::BINDING_KEY]) ? [$sql, $bind] : null;
        $active = $this->rcBoundExecutions ? $this->rcBoundExecutions[array_key_last($this->rcBoundExecutions)] : null;
        if ($original === null && $active !== null && $sql === $active['sql'] && $bind === $active['bind']) {
            // Native reconnect re-enters with compiled SQL. Recompile the original scope if the new session changed modes.
            $original = $active['original'];
        }
        $postgres = static::RC_BOUND_DIALECT === 'pgsql';
        if ($original === null && (!$postgres || !str_contains($sql, "\r") || !str_contains($sql, '--'))) {
            return parent::getPDOStatement($sql, $bind, $master, $procedure);
        }
        $this->queryStr = $sql;
        $this->bind = $bind;
        try {
            $this->initConnect($this->readMaster ?: $master);
            if ($original !== null) {
                [$sourceSql, $sourceBind] = $original;
                $flags = BoundSubqueries::needsEscapeMode($sourceSql, $sourceBind)
                    ? SqlParameters::escapeMode($this->linkID, static::RC_BOUND_DIALECT) : [null, null];
                [$sql, $bind] = BoundSubqueries::expand($sourceSql, $sourceBind, static::RC_BOUND_DIALECT, ...$flags);
            }
            if ($postgres) { $sql = PostgresSql::forPdo($sql, $this->linkID); }
        } catch (\PDOException $error) {
            // Metadata runs before the parent's try/catch; retain its exact opt-in retry/transaction boundary here too.
            if ($this->transTimes > 0) {
                if ($this->isBreak($error)) { $this->transTimes = 0; }
            } elseif ($this->reConnectTimes < 4 && $this->isBreak($error)) {
                $this->reConnectTimes++;
                [$retrySql, $retryBind] = $original ?? [$sql, $bind];
                return $this->close()->getPDOStatement($retrySql, $retryBind, $master, $procedure);
            }
            if (str_contains($error->getMessage(), '1062 Duplicate entry')) {
                throw new \think\db\exception\DuplicateException($error, $this->config, $this->getLastsql());
            }
            throw new \think\db\exception\PDOException($error, $this->config, $this->getLastsql());
        }
        if ($original === null) { return parent::getPDOStatement($sql, $bind, $master, $procedure); }
        $this->rcBoundExecutions[] = ['sql' => $sql, 'bind' => $bind, 'original' => $original];
        try { return parent::getPDOStatement($sql, $bind, $master, $procedure); }
        finally { array_pop($this->rcBoundExecutions); }
    }

    /** Keep buildSql()/fetchSql()/getLastSql() as native diagnostic APIs, without opening a database connection. */
    public function getRealSql(string $sql, array $bind = []): string
    {
        if (!isset($bind[BoundSubqueries::BINDING_KEY])) { return parent::getRealSql($sql, $bind); }
        return BoundSubqueries::diagnostic($sql, $bind,
            fn (string $text, array $values): string => parent::getRealSql($text, $values));
    }
}
