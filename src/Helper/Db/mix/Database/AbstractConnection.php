<?php

namespace RC\Helper\Db\mix\Database;

use RC\Helper\Db\PdoFailure;

/**
 * Mix-derived PDO compatibility core. Modified by RC for PHP 8.5, bound expressions and owned cleanup.
 * Original stable sources and Apache-2.0 attribution remain under src/ThirdParty/mix-database.
 */
abstract class AbstractConnection implements ConnectionInterface
{
    use QueryBuilder;

    protected $driver;
    protected $logger;
    protected $debug;
    protected $statement;
    protected $sql = '';
    protected $params = [];
    protected $values = [];
    protected $sqlData = [];
    protected $options = [];
    protected $lastInsertId;
    protected $rowCount;
    protected bool $executed = false;
    protected bool $failed = false;
    private bool $executing = false;
    private bool $observing = false;
    private string $dialect;

    /** Internal result wrapper; Database borrows drivers, application code uses Database methods. */
    public function __construct(Driver $driver, ?LoggerInterface $logger)
    {
        $this->driver = $driver;
        $this->logger = $logger;
        $this->options = $driver->options();
        $this->dialect = $driver->instance()->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }

    /** Explicitly reconnect this driver; never replay an earlier statement. */
    public function connect(): void
    {
        if (!$this->driver || $this->driver instanceof EmptyDriver) { throw new \LogicException('Mix result no longer owns a driver.'); }
        $this->driver->connect();
    }

    /** Close this statement and its underlying driver explicitly. */
    public function close(): void
    {
        if (!$this->driver || $this->driver instanceof EmptyDriver) { return; }
        $this->driver->assertTransactionAccess($this);
        $driver = $this->driver;
        $failure = null;
        try { $this->closeStatement(); }
        catch (\Throwable $error) { $failure = $error; }
        if (isset($driver->pool)) {
            try { $driver->__discard(); }
            catch (\Throwable $error) { $failure ??= $error; }
        }
        try { $driver->close(); }
        catch (\Throwable $error) { $failure ??= $error; }
        finally {
            $this->statement = null;
            $this->driver = new EmptyDriver();
            $this->executed = true;
        }
        if ($failure !== null) { throw $failure; }
    }

    /** Recognize connection errors only; a SQL/business error is not permission to replay a write. */
    protected static function isDisconnectException(\Throwable $error): bool
    {
        return PdoFailure::isDisconnect($error);
    }

    /** Execute trusted SQL with positional bound values, e.g. raw('SELECT * FROM users WHERE id = ?', 1). */
    public function raw(string $sql, ...$values): ConnectionInterface
    {
        $this->sql = $sql;
        $this->params = [];
        $this->values = $values;
        return $this->execute();
    }

    /** Legacy raw() alias. */
    public function exec(string $sql, ...$values): ConnectionInterface { return $this->raw($sql, ...$values); }

    /** Execute once per ordinary wrapper; transactions can issue sequential statements until completion. */
    public function execute(): ConnectionInterface
    {
        if ($this->executing || !$this->driver || $this->driver instanceof EmptyDriver
            || ($this->executed && !$this instanceof Transaction)) {
            throw new \LogicException('Mix result connection is closed, busy or already executed; start a new Database query.');
        }
        $this->driver->assertTransactionAccess($this);
        $this->executing = true;
        $this->executed = true;
        $this->lastInsertId = null;
        $this->rowCount = 0;
        $this->failed = false;
        $this->sqlData = [$this->sql, $this->params, $this->values, 0];
        $error = null;
        $start = microtime(true);
        try {
            $this->closeStatement();
            $this->statement = null;
            $this->prepare();
            if (!$this->statement->execute()) {
                throw PdoFailure::fromPdo($this->statement, 'Mix statement execution failed.');
            }
            $this->rowCount = $this->statement->rowCount();
        } catch (\Throwable $failure) {
            $error = $failure;
            $this->failed = true;
        } finally {
            $this->sqlData[3] = round((microtime(true) - $start) * 1000, 2);
            try {
                $this->observing = true;
                if ($this->logger) {
                    $log = $this->queryLog();
                    $this->logger->trace($log['time'], $log['sql'], $log['bindings'], $this->rowCount, $error);
                }
                if ($this->debug) { ($this->debug)($this); }
            } catch (\Throwable $observerError) {
                $error ??= $observerError;
                $this->failed = true;
            } finally {
                $this->observing = $this->executing = false;
                $this->debug = null;
                $this->sql = '';
                $this->params = $this->values = [];
            }
        }
        if ($error !== null) {
            if (self::isDisconnectException($error)) { $this->driver->markDisconnected(); }
            if (!$this instanceof Transaction) { $this->releaseResult(true); }
            throw $error;
        }
        if (!$this instanceof Transaction && $this->statement->columnCount() === 0) { $this->releaseResult(); }
        return $this;
    }

    /** Bind values without mutating the caller's parameter array or formatting SQL with printf. */
    protected function prepare()
    {
        $pdo = $this->driver->instance();
        if ($this->params !== []) {
            foreach ($this->params as $value) {
                if ($value instanceof Expr) { throw new \InvalidArgumentException('Use Expr through insert/update, not internal named parameters.'); }
            }
            $values = $this->params;
        } else {
            [$this->sql, $this->values] = Bindings::expand($this->sql, $this->values, $this->dialect, $pdo);
            $values = $this->values;
        }
        if ($this->dialect === 'pgsql' && str_contains($this->sql, "\r") && str_contains($this->sql, '--')) {
            $this->sql = \RC\Helper\Db\PostgresSql::forPdo($this->sql, $pdo);
        }
        $this->sqlData = [$this->sql, $this->params, $this->values, 0];
        $statement = $pdo->prepare($this->sql);
        if (!$statement) { throw PdoFailure::fromPdo($pdo, 'Mix PDO prepare failed.'); }
        $this->statement = $statement;
        foreach ($values as $key => $value) {
            if (is_array($value) || is_object($value)) { throw new \InvalidArgumentException('Mix SQL parameters must be scalar, null or a stream.'); }
            $parameter = $this->params !== [] ? $key : $key + 1;
            if (!$statement->bindValue($parameter, $value, static::bindType($value))) {
                throw PdoFailure::fromPdo($statement, 'Mix PDO parameter binding failed.');
            }
        }
    }

    /** Preserve booleans, null, integers and stream values; decimal/text values use PDO string binding. */
    protected static function bindType($value): int
    {
        return match (gettype($value)) {
            'boolean' => \PDO::PARAM_BOOL, 'NULL' => \PDO::PARAM_NULL, 'integer' => \PDO::PARAM_INT,
            'resource' => \PDO::PARAM_LOB, default => \PDO::PARAM_STR,
        };
    }

    /** Inspect only the current execution; the callback is cleared before another transaction statement. */
    public function debug(\Closure $func): ConnectionInterface { $this->debug = $func; return $this; }

    /** Fetch rows from a builder or a previously executed raw statement. */
    public function get(): array
    {
        if ($this->table) { [$sql, $values] = $this->build('SELECT'); $this->raw($sql, ...$values); }
        return $this->queryAll();
    }

    /** Fetch the first row; false means no row, not a nullable field. */
    public function first()
    {
        if ($this->table) { [$sql, $values] = $this->build('SELECT'); $this->raw($sql, ...$values); }
        return $this->queryOne();
    }

    /** Read one field, including a stored null; absent rows/columns remain explicit errors. */
    public function value(string $field)
    {
        $result = $this->first();
        if ($result === false || (is_array($result) ? !array_key_exists($field, $result) : !property_exists($result, $field))) {
            throw new \PDOException('Field ' . $field . ' not found');
        }
        return is_array($result) ? $result[$field] : $result->$field;
    }

    /** Update bound fields; Expr is reserved for developer-supplied SQL templates. */
    public function updates(array $data): ConnectionInterface
    {
        if ($data === []) { throw new \InvalidArgumentException('Mix update data cannot be empty.'); }
        [$sql, $values] = $this->build('UPDATE', $data);
        return $this->exec($sql, ...$values);
    }

    /** Single-field update convenience method. */
    public function update(string $field, $value): ConnectionInterface { return $this->updates([$field => $value]); }

    /** Delete using the current builder predicates; callers still own the intended scope. */
    public function delete(): ConnectionInterface
    {
        [$sql, $values] = $this->build('DELETE');
        return $this->exec($sql, ...$values);
    }

    /** Expose a statement only while its debug/logger observer owns the active execution. */
    public function statement(): \PDOStatement
    {
        if (!$this->observing || !$this->statement) { throw new \LogicException('Mix statement() is available only during execution observation.'); }
        return $this->statement;
    }

    /** Fetch one row without releasing an unread cursor back to a pool. */
    public function queryOne(?int $fetchStyle = null)
    {
        $this->requireStatement();
        try {
            $result = $this->statement->fetch($fetchStyle ?? $this->options[\PDO::ATTR_DEFAULT_FETCH_MODE]);
            if ($result === false) { $this->assertFetchSucceeded(); }
        }
        catch (\Throwable $error) { $this->fetchFailed($error); }
        if ($result === false) { $this->releaseResult(); }
        return $result;
    }

    /** Drain all remaining rows, then release any owned pooled driver. */
    public function queryAll(?int $fetchStyle = null): array
    {
        $this->requireStatement();
        try {
            $result = $this->statement->fetchAll($fetchStyle ?? $this->options[\PDO::ATTR_DEFAULT_FETCH_MODE]);
            $this->assertFetchSucceeded();
        }
        catch (\Throwable $error) { $this->fetchFailed($error); }
        $this->releaseResult();
        return $result;
    }

    /** PDO's column fetch preserves 0, false, empty strings and null rather than treating them as EOF. */
    public function queryColumn(int $columnNumber = 0): array
    {
        $this->requireStatement();
        try {
            $result = $this->statement->fetchAll(\PDO::FETCH_COLUMN, $columnNumber);
            $this->assertFetchSucceeded();
        }
        catch (\Throwable $error) { $this->fetchFailed($error); }
        $this->releaseResult();
        return $result;
    }

    /** Read one scalar; only PDO's false EOF result allows an ordinary cursor to be released. */
    public function queryScalar()
    {
        $this->requireStatement();
        try {
            $row = $this->statement->fetch(\PDO::FETCH_NUM);
            if ($row === false) { $this->assertFetchSucceeded(); }
        }
        catch (\Throwable $error) { $this->fetchFailed($error); }
        if ($row === false) { $this->releaseResult(); return false; }
        return $row[0];
    }

    /** Native PDO identity; PostgreSQL callers needing a specific key should use explicit RETURNING. */
    public function lastInsertId(): string
    {
        if ($this->lastInsertId === null) {
            if (!$this->driver || $this->driver instanceof EmptyDriver) { throw new \LogicException('Mix insert identity is unavailable.'); }
            $pdo = $this->driver->instance();
            try {
                $identity = $pdo->lastInsertId();
                if ($identity === false) { throw PdoFailure::fromPdo($pdo, 'Mix insert identity is unavailable.'); }
                $this->lastInsertId = $identity;
            } catch (\Throwable $error) {
                if (self::isDisconnectException($error)) { $this->driver->markDisconnected(); }
                throw $error;
            }
        }
        return $this->lastInsertId;
    }

    /** Affected-row metadata belongs to this execution, not the next user of a pooled driver. */
    public function rowCount(): int
    {
        if ($this->rowCount === null) { throw new \LogicException('No Mix statement has executed.'); }
        return $this->rowCount;
    }

    /** Return the last execution's SQL and original typed bindings, never interpolated SQL. */
    public function queryLog(): array
    {
        [$sql, $params, $values, $time] = $this->sqlData ?: ['', [], [], 0];
        return ['time' => $time, 'sql' => $sql, 'bindings' => $values ?: $params];
    }

    /** Insert one row, compiling every Expr value into positional bindings. */
    public function insert(string $table, array $data, string $insert = 'INSERT INTO'): ConnectionInterface
    {
        return $this->batchInsert($table, [$data], $insert);
    }

    /** Validate the entire batch before executing one statement; rows may reorder but not omit/add columns. */
    public function batchInsert(string $table, array $data, string $insert = 'INSERT INTO'): ConnectionInterface
    {
        if ($data === [] || !isset($data[0]) || !is_array($data[0]) || $data[0] === []) {
            throw new \InvalidArgumentException('Mix insert requires nonempty rows.');
        }
        $keys = array_keys($data[0]);
        $columns = array_map($this->quoteIdentifier(...), $keys);
        $rows = $values = [];
        foreach ($data as $row) {
            if (!is_array($row) || count($row) !== count($keys) || array_diff($keys, array_keys($row)) !== []) {
                throw new \InvalidArgumentException('Mix batch rows must contain the same columns.');
            }
            $placeholders = [];
            foreach ($keys as $key) {
                $value = $row[$key];
                if ($value instanceof Expr) {
                    $placeholders[] = $value->sql();
                    array_push($values, ...$value->bindings());
                } else {
                    $placeholders[] = '?';
                    $values[] = $value;
                }
            }
            $rows[] = '(' . implode(', ', $placeholders) . ')';
        }
        return $this->exec($insert . ' ' . $this->quoteIdentifier($table, true) . ' (' . implode(', ', $columns)
            . ') VALUES ' . implode(', ', $rows), ...$values);
    }

    /** Quote structured insert/update identifiers; raw table/select/join expressions stay trusted SQL APIs. */
    protected function quoteIdentifier(string $identifier, bool $qualified = false): string
    {
        $parts = $qualified ? explode('.', $identifier) : [$identifier];
        $quote = $this->dialect === 'mysql' ? '`' : '"';
        foreach ($parts as &$part) {
            if ($part === '' || str_contains($part, "\0")) {
                throw new \InvalidArgumentException('Invalid structured Mix SQL identifier.');
            }
            $part = $quote . str_replace($quote, $quote . $quote, $part) . $quote;
        }
        return implode('.', $parts);
    }

    /** Report actual PDO transaction state; completed result wrappers no longer own a driver. */
    public function inTransaction(): bool
    {
        return $this->driver && !$this->driver instanceof EmptyDriver && $this->driver->instance()->inTransaction();
    }

    /** Execute a closure with its owned transaction; cleanup errors must not replace a business error. */
    public function transaction(\Closure $closure)
    {
        $transaction = $this->beginTransaction();
        try { $closure($transaction); $transaction->commit(); }
        catch (\Throwable $error) {
            try { $transaction->rollback(); } catch (\Throwable) { error_log('Mix transaction rollback failed during exception cleanup.'); }
            throw $error;
        }
    }

    /** Transfer this wrapper's driver only after a new transaction successfully begins. */
    public function beginTransaction(): Transaction
    {
        if (!$this->driver || $this->driver instanceof EmptyDriver || $this->executed) {
            throw new \LogicException('Cannot start a transaction from a completed Mix result.');
        }
        $transaction = new Transaction($this->driver, $this->logger);
        $this->driver = null;
        return $transaction;
    }

    /** Prevent fetches before execution while retaining PDO's native EOF behavior afterward. */
    private function requireStatement(): void
    {
        if (!$this->statement) { throw new \LogicException('No Mix statement is available to fetch.'); }
    }

    /** Native silent fetchAll may return a partial array; inspect its statement before any cleanup. */
    private function assertFetchSucceeded(): void
    {
        if (!in_array($this->statement->errorCode(), [null, '00000', '02000'], true)) {
            throw PdoFailure::fromPdo($this->statement, 'Mix result fetch failed.');
        }
    }

    /** Closing a cursor can return false in silent mode just like execute/fetch. */
    protected function closeStatement(): void
    {
        if ($this->statement && !$this->statement->closeCursor()) {
            throw PdoFailure::fromPdo($this->statement, 'Mix result cursor cleanup failed.');
        }
    }

    /** Observe failures before releasing a driver; finally-based release could put a broken socket back in a pool. */
    private function fetchFailed(\Throwable $error): never
    {
        $this->failed = true;
        if (self::isDisconnectException($error) && $this->driver && !$this->driver instanceof EmptyDriver) {
            $this->driver->markDisconnected();
        }
        $this->releaseResult(true);
        throw $error;
    }

    /** Preserve identity before a pool or transaction releases its driver. */
    protected function cacheIdentity(): void
    {
        if ($this->lastInsertId !== null) { return; }
        if (!preg_match('/^\\s*(?:INSERT|REPLACE)\\b/i', $this->sqlData[0] ?? '')) { $this->lastInsertId = ''; return; }
        try {
            $pdo = $this->driver->instance();
            // PostgreSQL LASTVAL can abort a transaction that inserted a manual key; defer until commit.
            if ($this->dialect === 'pgsql' && $pdo->inTransaction()) { return; }
            $identity = $pdo->lastInsertId();
            if ($identity === false) { throw PdoFailure::fromPdo($pdo, 'Mix insert identity is unavailable.'); }
            $this->lastInsertId = $identity;
        } catch (\Throwable $error) {
            $this->lastInsertId = '';
            if (self::isDisconnectException($error)) { $this->driver->markDisconnected(); }
        }
    }

    /** Never return an active cursor or failed driver to a pool; nonpooled drivers remain manager-owned. */
    protected function releaseResult(bool $discard = false): void
    {
        if ($this instanceof Transaction || !$this->driver || $this->driver instanceof EmptyDriver || !isset($this->driver->pool)) { return; }
        if ($this->driver->hasTransactionOwner()) { return; }
        $driver = $this->driver;
        try {
            if (!$discard && !$driver->isDisconnected()) { $this->cacheIdentity(); }
            $this->closeStatement();
        } catch (\Throwable) {
            $driver->markDisconnected();
            error_log('Mix result pool cleanup failed.');
        }
        $discard = $discard || $driver->isDisconnected();
        try {
            if ($discard) { $driver->__discard(); }
            elseif (!$driver->__return()) { $discard = true; }
        } catch (\Throwable) {
            $discard = true;
            $driver->markDisconnected();
            error_log('Mix result pool release failed.');
        } finally {
            if ($discard) {
                try { $driver->close(); }
                catch (\Throwable) { error_log('Mix discarded driver close failed.'); }
                $this->statement = null;
            }
            $this->driver = new EmptyDriver();
        }
    }
}
