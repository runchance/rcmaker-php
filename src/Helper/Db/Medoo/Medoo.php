<?php

declare(strict_types=1);

namespace RC\Helper\Db\Medoo;

use InvalidArgumentException;
use PDO;

if (!class_exists(\Medoo\Medoo::class)) {
    require_once dirname(__DIR__, 3) . '/ThirdParty/medoo/src/Medoo.php';
}
if (!class_exists(__NAMESPACE__ . '\\Raw', false)) {
    class_alias(\Medoo\Raw::class, __NAMESPACE__ . '\\Raw');
}

/**
 * Keep the rcmaker Medoo API on top of the unmodified upstream implementation.
 *
 * Construct through the framework database bootstrap. SDB supplies RCWhere;
 * direct DB callers can continue using LOCK, raw column aliases and <custom> tables.
 */
class Medoo extends \Medoo\Medoo
{
    private array $rcWhere = [];
    private bool $buildingSelect = false;
    private string $tableLock = '';
    private array $postgresPrimaryKeys = [];
    private ?string $postgresInsertId = null;
    private ?string $postgresReturningColumn = null;

    /** Own one transaction: action(fn($db) => ...); only a strict false result requests rollback. */
    public function action(callable $actions): void
    {
        $pdo = $this->pdo;
        if ($pdo === null) { throw new InvalidArgumentException('Database connection is not initialized.'); }
        if ($pdo->beginTransaction() === false) {
            throw \RC\Helper\Db\PdoFailure::fromPdo($pdo, 'Could not begin the Medoo action transaction.');
        }

        $rollbackAttempted = false;
        try {
            if ($actions($this) === false) {
                $rollbackAttempted = true;
                if ($pdo->rollBack() === false) {
                    throw \RC\Helper\Db\PdoFailure::fromPdo($pdo, 'Could not roll back the Medoo action transaction.');
                }
            } elseif ($pdo->commit() === false) {
                throw \RC\Helper\Db\PdoFailure::fromPdo($pdo, 'Could not commit the Medoo action transaction.');
            }
        } catch (\Throwable $error) {
            if (!$rollbackAttempted) {
                try {
                    if ($pdo->inTransaction()) { $pdo->rollBack(); }
                } catch (\Throwable) {}
            }
            throw $error;
        }
    }

    /** Keep PDO comment recognition aligned with PostgreSQL without changing bindings or dry-run connection behavior. */
    public function exec(string $statement, array $map = [], ?callable $callback = null): ?\PDOStatement
    {
        if ($this->type === 'pgsql' && str_contains($statement, "\r") && str_contains($statement, '--')
            && !$this->testMode && !$this->debugMode && $this->pdo instanceof PDO) {
            $this->statement = $this->errorInfo = $this->error = null;
            $statement = \RC\Helper\Db\PostgresSql::forPdo($statement, $this->pdo);
        }
        return parent::exec($statement, $map, $callback);
    }

    /** Clear table/identity metadata after low-level PDO/exec schema or session changes; query/create/drop do this automatically. */
    public function clearPostgresIdentityCache(): void
    {
        $this->postgresPrimaryKeys = [];
        $this->postgresInsertId = null;
        if ($this->type === 'pgsql') {
            $this->returnId = '';
        }
    }

    /** Raw SQL may change schema/search_path/role or insert outside this adapter's identity scope. */
    public function query(string $statement, array $map = []): ?\PDOStatement
    {
        if ($this->type === 'pgsql') {
            $this->clearPostgresIdentityCache();
        }
        return parent::query($statement, $map);
    }

    /** Schema creation invalidates cached PostgreSQL primary-key information. */
    public function create(string $table, $columns, $options = null): ?\PDOStatement
    {
        if ($this->type === 'pgsql') { $this->clearPostgresIdentityCache(); }
        return parent::create($table, $columns, $options);
    }

    /** Dropping/recreating a logical table must not retain its former primary key. */
    public function drop(string $table): ?\PDOStatement
    {
        if ($this->type === 'pgsql') { $this->clearPostgresIdentityCache(); }
        return parent::drop($table);
    }

    /** Resolve one readable single-column key; never add SELECT requirements to write-only/RLS-protected inserts. */
    private function postgresPrimaryKey(string $table): ?string
    {
        $quoted = $this->tableQuote($table);
        if (array_key_exists($quoted, $this->postgresPrimaryKeys)) {
            return $this->postgresPrimaryKeys[$quoted];
        }
        if ($this->testMode || $this->debugMode) { return null; }
        $statement = parent::query('SELECT a.attname,
            CAST(has_column_privilege(c.conrelid, a.attname, \'SELECT\') AS integer) AS readable,
            CAST(row_security_active(c.conrelid) AS integer) AS row_security
            FROM pg_constraint c JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY(c.conkey)
            WHERE c.conrelid = to_regclass(:rc_table) AND c.contype = \'p\'', ['rc_table' => $quoted]);
        if ($statement === null) { throw new \RuntimeException('Cannot resolve PostgreSQL primary-key metadata.'); }
        $rows = $statement->fetchAll(PDO::FETCH_NUM);
        $statement->closeCursor();
        $key = count($rows) === 1 && filter_var($rows[0][1], FILTER_VALIDATE_BOOLEAN)
            && !filter_var($rows[0][2], FILTER_VALIDATE_BOOLEAN) ? $rows[0][0] : null;
        if (count($this->postgresPrimaryKeys) >= 256) { array_shift($this->postgresPrimaryKeys); }
        return $this->postgresPrimaryKeys[$quoted] = $key;
    }

    /** PostgreSQL captures the inserted table's key in the original write, including zero/manual/triggered keys. */
    public function insert(string $table, array $values, ?string $primaryKey = null): ?\PDOStatement
    {
        if ($this->type !== 'pgsql') { return parent::insert($table, $values, $primaryKey); }
        $this->postgresInsertId = null;
        $this->returnId = '';
        if ($primaryKey === null) {
            $primaryKey = $this->postgresPrimaryKey($table);
            $this->postgresReturningColumn = $primaryKey;
        }
        try {
            $statement = parent::insert($table, $values, $primaryKey);
            if ($statement !== null && $primaryKey !== null && $primaryKey !== '' && $statement->rowCount() > 0) {
                $this->postgresInsertId = $this->returnId;
            }
            return $statement;
        } finally {
            $this->postgresReturningColumn = null;
        }
    }

    /** Quote only this insert's catalog-resolved primary key literally; all caller-provided identifiers retain native validation. */
    public function columnQuote(string $column): string
    {
        if ($column === $this->postgresReturningColumn) {
            return '"' . str_replace('"', '""', $column) . '"';
        }
        return parent::columnQuote($column);
    }

    /** Unscoped PostgreSQL LASTVAL is unsafe with triggers or absent sequences; explicit sequence names retain native behavior. */
    public function id(?string $name = null): ?string
    {
        if ($this->type === 'pgsql' && $name === null) { return $this->postgresInsertId; }
        return parent::id($name);
    }

    /**
     * Supply SDB clauses for the next statement that uses a raw WHERE expression.
     *
     * @param array|null $where Framework-generated conditions/order/group/limit.
     * @return void
     */
    public function buildRCWhere(?array $where): void
    {
        $this->rcWhere = $where ?? [];
    }

    /**
     * Compile a statement and scope custom table/lock state to this query only.
     *
     * @param string $table Table name, Medoo alias, or trusted <custom>(SELECT ...) AS alias.
     * @param array $map Parameter bindings, populated by the upstream builder.
     * @param mixed $join Join definition or overloaded column/condition argument.
     * @param mixed $columns Selected columns; upstream may normalize them in place.
     * @param mixed $where Conditions or a raw SQL expression.
     * @param mixed $columnFn Aggregate/select expression used by upstream helpers.
     * @return string Compiled SQL, with dialect-specific locking where requested.
     * @throws InvalidArgumentException For an unsupported derived table or lock.
     */
    protected function selectContext(string $table, array &$map, $join, &$columns = null, $where = null, $columnFn = null): string
    {
        if ($this->rcWhere === [] && !str_starts_with($table, '<custom>')
            && !(is_array($where) && isset($where['LOCK']))
            && !(is_array($columns) && isset($columns['LOCK']))
            && !(is_array($join) && isset($join['LOCK']))) {
            return parent::selectContext($table, $map, $join, $columns, $where, $columnFn);
        }
        $this->buildingSelect = true;
        $this->tableLock = '';
        try {
            $derived = null;
            if (str_starts_with($table, '<custom>')) {
                if (!preg_match('/^<custom>(\(.+\))\s+AS\s+([\p{L}_][\p{L}\p{N}_]*)$/isuD', $table, $match)) {
                    throw new InvalidArgumentException('Derived tables require <custom>(SELECT ...) AS alias.');
                }
                $derived = $match[1];
                $table = '__rcmaker_derived_table(' . $match[2] . ')';
            }

            $sql = parent::selectContext($table, $map, $join, $columns, $where, $columnFn);
            if ($this->tableLock !== '') {
                preg_match('/^(.*?)\s*\(([^()]+)\)$/uD', $table, $alias);
                $reference = isset($alias[1])
                    ? $this->tableQuote($alias[1]) . $this->tableAliasConnector . $this->tableQuote($alias[2])
                    : $this->tableQuote($table);
                $needle = ' FROM ' . $reference;
                $offset = strpos($sql, $needle);
                if ($offset === false) {
                    throw new InvalidArgumentException('Cannot place the requested table lock.');
                }
                $position = $offset + strlen($needle);
                $sql = substr_replace($sql, ' ' . $this->tableLock, $position, 0);
            }
            if ($derived !== null) {
                $needle = ' FROM ' . $this->tableQuote('__rcmaker_derived_table');
                $offset = strpos($sql, $needle);
                if ($offset === false) {
                    throw new InvalidArgumentException('Cannot place the derived table.');
                }
                $sql = substr_replace($sql, ' FROM ' . $derived, $offset, strlen($needle));
            }
            return $sql;
        } finally {
            $this->buildingSelect = false;
            $this->tableLock = '';
            $this->rcWhere = [];
        }
    }

    /**
     * Merge SDB raw and structured clauses without retaining state for another query.
     *
     * @param mixed $where Upstream conditions or raw SQL.
     * @param array $map Bound parameter map populated during compilation.
     * @return string WHERE and trailing clauses.
     */
    protected function whereClause($where, array &$map): string
    {
        if ($this->rcWhere === [] && !(is_array($where) && array_key_exists('LOCK', $where))) {
            return parent::whereClause($where, $map);
        }
        $extra = $this->rcWhere;
        $this->rcWhere = [];
        $lock = is_array($where) ? ($where['LOCK'] ?? false) : ($extra['LOCK'] ?? false);
        if (is_array($where)) {
            unset($where['LOCK']);
        }
        unset($extra['LOCK']);
        $clause = parent::whereClause($where, $map);
        if ($where instanceof \Medoo\Raw && $extra !== []) {
            $tail = parent::whereClause($extra, $map);
            if (str_starts_with($tail, ' WHERE ')) {
                $tail = substr($tail, strlen(' WHERE '));
                $clause = rtrim($clause);
                $clause .= preg_match('/\b(?:AND|OR)\s*$/i', $clause) ? ' ' : ' AND ';
            }
            $clause .= $tail;
        }
        return $clause . $this->compileLock($lock);
    }

    /** Allow bound raw predicates inside SDB boolean groups; other nodes stay upstream. */
    protected function dataImplode(array $data, array &$map, string $conjunctor): string
    {
        $hasRaw = false;
        foreach ($data as $key => $value) {
            if (is_int($key) && $value instanceof \Medoo\Raw) {
                $hasRaw = true;
                break;
            }
        }
        if (!$hasRaw) {
            return parent::dataImplode($data, $map, $conjunctor);
        }
        $parts = [];
        foreach ($data as $key => $value) {
            if (is_int($key) && $value instanceof \Medoo\Raw) {
                $parts[] = '(' . $this->buildRaw($value, $map) . ')';
            } else {
                $part = parent::dataImplode([$key => $value], $map, $conjunctor);
                if ($part === '') {
                    throw new InvalidArgumentException('A database condition could not be compiled.');
                }
                $parts[] = $part;
            }
        }
        return implode($conjunctor . ' ', $parts);
    }

    /** Reject conflicting raw placeholders instead of silently replacing another condition's value. */
    protected function buildRaw($raw, array &$map): ?string
    {
        if (!$raw instanceof \Medoo\Raw) {
            return null;
        }
        $parameters = [];
        $sql = parent::buildRaw($raw, $parameters);
        foreach ($parameters as $key => $value) {
            if (array_key_exists($key, $map) && $map[$key] !== $value) {
                throw new InvalidArgumentException('Conflicting SQL placeholder: ' . $key . '. Use unique names for different values.');
            }
            $map[$key] = $value;
        }
        return $sql;
    }

    /**
     * Build a supported locking clause, never silently drop a requested lock.
     *
     * @param mixed $lock Boolean or trusted SQL locking instruction from SDB/DB.
     * @return string Suffix, or an empty string for a table-hint dialect/no lock.
     * @throws InvalidArgumentException For invalid syntax or unsupported SQLite locks.
     */
    private function compileLock($lock): string
    {
        if ($lock === false || $lock === null || $lock === '') {
            return '';
        }
        if (!$this->buildingSelect) {
            throw new InvalidArgumentException('LOCK is only supported for SELECT statements.');
        }
        if ($this->type === 'sqlite') {
            throw new InvalidArgumentException('SQLite has no SELECT FOR UPDATE; use a transaction instead.');
        }
        if ($this->type === 'mssql') {
            $hint = $lock === true ? 'ROWLOCK,UPDLOCK,HOLDLOCK' : strtoupper(trim((string)$lock));
            if (preg_match('/^WITH\s*\((.*)\)$/iD', $hint, $match)) {
                $hint = trim($match[1]);
            }
            $allowed = 'ROWLOCK|UPDLOCK|HOLDLOCK|READPAST|NOWAIT|XLOCK|TABLOCK|TABLOCKX|READCOMMITTED|REPEATABLEREAD|SERIALIZABLE|NOLOCK';
            if (!preg_match('/^(?:' . $allowed . ')(?:\s*,\s*(?:' . $allowed . '))*$/D', $hint)) {
                throw new InvalidArgumentException('Unsupported SQL Server table lock.');
            }
            $this->tableLock = 'WITH(' . $hint . ')';
            return '';
        }
        if ($lock === true) {
            $lock = $this->type === 'oracle' ? 'FOR UPDATE NOWAIT' : 'FOR UPDATE';
        }
        $pattern = match ($this->type) {
            'mysql' => '(?:FOR (?:UPDATE|SHARE)(?: (?:NOWAIT|SKIP LOCKED))?|LOCK IN SHARE MODE)',
            'pgsql' => 'FOR (?:UPDATE|NO KEY UPDATE|SHARE|KEY SHARE)(?: (?:NOWAIT|SKIP LOCKED))?',
            'oracle' => 'FOR UPDATE(?: (?:NOWAIT|SKIP LOCKED|WAIT \d+))?',
            default => null,
        };
        if ($pattern === null || !is_string($lock) || !preg_match('/^' . $pattern . '$/iD', trim($lock))) {
            throw new InvalidArgumentException('Unsupported SELECT lock for ' . $this->type . '.');
        }
        return ' ' . strtoupper(trim($lock));
    }

    /**
     * Preserve rcmaker raw expression aliases while delegating SQL generation upstream.
     *
     * @param mixed $columns Column definitions, normalized in place for result mapping.
     * @param array $map Bound parameter map.
     * @param bool $root Whether this is the root selection.
     * @param bool $isJoin Whether the query has JOIN clauses.
     * @return string Compiled column list.
     */
    protected function columnPush(&$columns, array &$map, bool $root, bool $isJoin = false): string
    {
        $special = false;
        foreach (is_array($columns) ? $columns : [$columns] as $column) {
            if (!is_string($column) || stripos($column, '[Object]') !== false) {
                $special = true;
                break;
            }
        }
        if (!$special) {
            return parent::columnPush($columns, $map, $root, $isJoin);
        }
        $this->normalizeColumns($columns);
        $sql = parent::columnPush($columns, $map, $root, $isJoin);
        // Treat an already-selected JSON alias as a normal typed result column.
        $this->normalizeColumns($columns, true);
        return $sql;
    }

    /** Normalize nested raw aliases and reject implicit PHP object deserialization. */
    private function normalizeColumns(&$columns, bool $forMapping = false): void
    {
        if (is_string($columns) && preg_match('/\[Object\]/i', $columns)) {
            throw new InvalidArgumentException('[Object] deserialization is disabled; use [JSON] or read the serialized value as a string.');
        }
        if (is_array($columns)) {
            $normalized = [];
            foreach ($columns as $key => $value) {
                if (is_array($value) || is_string($value)) {
                    $this->normalizeColumns($value, $forMapping);
                }
                if (is_string($key) && preg_match('/\[Object\]/i', $key)) {
                    throw new InvalidArgumentException('[Object] deserialization is disabled; use [JSON] or read the serialized value as a string.');
                }
                if (is_string($key) && $value instanceof \Medoo\Raw
                    && preg_match('/^[\p{L}_][\p{L}\p{N}@$#_.-]*\s*\(([\p{L}_][\p{L}\p{N}@$#_-]*)\)(\s*\[(?:String|Bool|Int|Number|Object|JSON)\])?$/uD', $key, $match)) {
                    $key = $match[1] . ($match[2] ?? '');
                }
                if ($forMapping && $value instanceof \Medoo\Raw && is_string($key)
                    && preg_match('/\[JSON\]$/D', $key)) {
                    $normalized[] = $key;
                    continue;
                }
                $normalized[$key] = $value;
            }
            $columns = $normalized;
        }
    }

    /**
     * Count a grouped selection with bindings, without interpolating debug SQL.
     * Use from SDB pagination after removing LIMIT, ORDER and LOCK from the count clauses.
     */
    public function countSelection(string $table, $columns, $where, ?array $join = null): int
    {
        $map = [];
        if ($join) {
            $sql = $this->selectContext($table, $map, $join, $columns, $where);
        } else {
            $conditions = $where;
            $sql = $this->selectContext($table, $map, $columns, $conditions);
        }
        $alias = $this->tableAliasConnector . $this->tableQuote('__rcmaker_count');
        $statement = $this->exec('SELECT COUNT(*) FROM (' . $sql . ')' . $alias, $map);
        if ($statement === null) {
            throw new \RuntimeException('Cannot count the selected rows.');
        }
        return (int)$statement->fetchColumn();
    }

    /** Plain, non-aliased column lists can use PDO's associative mapping directly. */
    private function isPlainColumnList($columns): bool
    {
        if (!is_array($columns) || !array_is_list($columns) || $columns === []) {
            return false;
        }
        foreach ($columns as $column) {
            if (!is_string($column) || strpbrk($column, '[(@') !== false) {
                return false;
            }
        }
        return true;
    }

    /**
     * Avoid recursive result mapping for ordinary SDB lists without changing typed,
     * nested, aliased, joined or callback queries, which still use upstream select().
     */
    public function select(string $table, $join, $columns = null, $where = null, $callback = null): ?array
    {
        if ($where === null && $callback === null && is_array($columns) && !is_callable($columns) && $this->isPlainColumnList($join)) {
            $map = [];
            $sql = $this->selectContext($table, $map, $join, $columns);
            $statement = $this->exec($sql, $map);
            return $statement === null ? [] : $statement->fetchAll(PDO::FETCH_ASSOC);
        }
        return parent::select(...func_get_args());
    }

    /**
     * Preserve raw WHERE in get(table, columns, raw), discarded by upstream get().
     *
     * @param string $table Table name.
     * @param mixed $join Join definition or selected columns.
     * @param mixed $columns Selected columns or conditions.
     * @param mixed $where Conditions when using a JOIN.
     * @return mixed First mapped row/scalar, null if empty, or false on query failure.
     */
    public function get(string $table, $join = null, $columns = null, $where = null)
    {
        // Ordinary one-row reads need no recursive type/result mapper.
        if ($where === null && is_array($columns) && $this->isPlainColumnList($join)) {
            $columns['LIMIT'] = 1;
            $map = [];
            $sql = $this->selectContext($table, $map, $join, $columns);
            $statement = $this->exec($sql, $map);
            if ($statement === null) {
                return false;
            }
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            $statement->closeCursor();
            return $row === false ? null : $row;
        }
        if ($where !== null || !$columns instanceof \Medoo\Raw || $this->isJoin($join)) {
            return parent::get($table, $join, $columns, $where);
        }
        $selected = $join;
        $map = [];
        $sql = $this->selectContext($table, $map, $join, $columns, $where);
        $this->exec($sql, $map);
        if ($this->statement === null) {
            return false;
        }
        $row = $this->statement->fetch(PDO::FETCH_ASSOC);
        $this->statement->closeCursor();
        if ($row === false) {
            return null;
        }
        if ($selected === '*') {
            return $row;
        }
        $columnMap = [];
        $result = [];
        $this->columnMap($columns, $columnMap, true);
        $this->dataMap($row, $columns, $columnMap, $result, true);
        return is_string($selected) ? $result[$columnMap[$selected][0]] : $result;
    }
}
