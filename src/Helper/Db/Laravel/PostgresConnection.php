<?php
namespace RC\Helper\Db\Laravel;

/** Native PostgreSQL connection, including RETURNING and direct/read/write PDO features. */
class PostgresConnection extends \Illuminate\Database\PostgresConnection
{
    use ManagedConnection { cursor as protected rcmakerCursor; }

    /** Preserve read-routing and native result/observer handling while adapting bare-CR comments. */
    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        if (!$this->needsPostgresComments($query)) {
            return parent::select($query, $bindings, $useReadPdo, $fetchUsing);
        }
        return $this->run($query, $bindings, function ($sql, $values) use ($useReadPdo, $fetchUsing) {
            if ($this->pretending()) { return []; }
            $pdo = $this->getPdoForSelect($useReadPdo);
            $statement = $this->prepared($pdo->prepare(\RC\Helper\Db\PostgresSql::forPdo($sql, $pdo)));
            $this->bindValues($statement, $this->prepareBindings($values));
            $statement->execute();
            return $statement->fetchAll(...$fetchUsing);
        });
    }

    /** Multi-result reads use the same actual read PDO as the native implementation. */
    public function selectResultSets($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        if (!$this->needsPostgresComments($query)) {
            return parent::selectResultSets($query, $bindings, $useReadPdo, $fetchUsing);
        }
        return $this->run($query, $bindings, function ($sql, $values) use ($useReadPdo, $fetchUsing) {
            if ($this->pretending()) { return []; }
            $pdo = $this->getPdoForSelect($useReadPdo);
            $statement = $this->prepared($pdo->prepare(\RC\Helper\Db\PostgresSql::forPdo($sql, $pdo)));
            $this->bindValues($statement, $this->prepareBindings($values));
            $statement->execute();
            $sets = [];
            do { $sets[] = $statement->fetchAll(...$fetchUsing); } while ($statement->nextRowset());
            return $sets;
        });
    }

    /** Retain lazy cursor execution and the managed fetch-failure/disconnect boundary. */
    public function cursor($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        if (!$this->needsPostgresComments($query)) {
            yield from $this->rcmakerCursor($query, $bindings, $useReadPdo, $fetchUsing);
            return;
        }
        try {
            $statement = $this->run($query, $bindings, function ($sql, $values) use ($useReadPdo) {
                if ($this->pretending()) { return []; }
                $pdo = $this->getPdoForSelect($useReadPdo);
                $statement = $this->prepared($pdo->prepare(\RC\Helper\Db\PostgresSql::forPdo($sql, $pdo)));
                $this->bindValues($statement, $this->prepareBindings($values));
                $statement->execute();
                return $statement;
            });
            while ($record = $statement->fetch(...$fetchUsing)) { yield $record; }
        } catch (\Throwable $error) {
            if ($this->causedByLostConnection($error)) { $this->disconnect(); }
            throw $error;
        }
    }

    /** Prepared statements keep native binding, modification flags and return types. */
    public function statement($query, $bindings = [])
    {
        if (!$this->needsPostgresComments($query)) { return parent::statement($query, $bindings); }
        return $this->run($query, $bindings, function ($sql, $values) {
            if ($this->pretending()) { return true; }
            $pdo = $this->getPdo();
            $statement = $pdo->prepare(\RC\Helper\Db\PostgresSql::forPdo($sql, $pdo));
            $this->bindValues($statement, $this->prepareBindings($values));
            $this->recordsHaveBeenModified();
            return $statement->execute();
        });
    }

    /** Writes normalize on the actual write connection without reconnecting/replaying a failed command. */
    public function affectingStatement($query, $bindings = [])
    {
        if (!$this->needsPostgresComments($query)) { return parent::affectingStatement($query, $bindings); }
        return $this->run($query, $bindings, function ($sql, $values) {
            if ($this->pretending()) { return 0; }
            $pdo = $this->getPdo();
            $statement = $pdo->prepare(\RC\Helper\Db\PostgresSql::forPdo($sql, $pdo));
            $this->bindValues($statement, $this->prepareBindings($values));
            $statement->execute();
            $this->recordsHaveBeenModified(($count = $statement->rowCount()) > 0);
            return $count;
        });
    }

    /** Ordinary SQL and dry runs retain the native path; no connection, lexer or mode query here. */
    private function needsPostgresComments(string $query): bool
    {
        return str_contains($query, "\r") && str_contains($query, '--') && !$this->pretending();
    }
}
