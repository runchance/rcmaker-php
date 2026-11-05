<?php

namespace RC\Helper\Db\mix\Database;

/**
 * Trait QueryBuilder
 * @package Mix\Database
 */
trait QueryBuilder
{

    /**
     * @var string
     */
    protected $table = '';

    /**
     * @var array
     */
    protected $select = [];

    /**
     * @var array
     */
    protected $join = [];

    /**
     * @var array
     */
    protected $where = [];

    /**
     * @var array
     */
    protected $order = [];

    /**
     * @var array
     */
    protected $group = [];

    /**
     * @var array
     */
    protected $having = [];

    /**
     * @var int
     */
    protected $offset = 0;

    /**
     * @var int|null
     */
    protected $limit = null;

    /**
     * @var string
     */
    protected $lock = '';

    /**
     * @param string $table
     * @return $this
     */
    public function table(string $table): ConnectionInterface
    {
        $this->table = $table;
        return $this;
    }

    /**
     * @param string ...$fields
     * @return $this
     */
    public function select(string ...$fields): ConnectionInterface
    {
        $this->select = array_merge($this->select, $fields);
        return $this;
    }

    /**
     * @param string $table
     * @param string $on
     * @param ...$values
     * @return $this
     */
    public function join(string $table, string $on, ...$values): ConnectionInterface
    {
        array_push($this->join, ['INNER JOIN', $table, $on, $values]);
        return $this;
    }

    /**
     * @param string $table
     * @param string $on
     * @param ...$values
     * @return $this
     */
    public function leftJoin(string $table, string $on, ...$values): ConnectionInterface
    {
        array_push($this->join, ['LEFT JOIN', $table, $on, $values]);
        return $this;
    }

    /**
     * @param string $table
     * @param string $on
     * @param ...$values
     * @return $this
     */
    public function rightJoin(string $table, string $on, ...$values): ConnectionInterface
    {
        array_push($this->join, ['RIGHT JOIN', $table, $on, $values]);
        return $this;
    }

    /**
     * @param string $table
     * @param string $on
     * @param ...$values
     * @return $this
     */
    public function fullJoin(string $table, string $on, ...$values): ConnectionInterface
    {
        array_push($this->join, ['FULL JOIN', $table, $on, $values]);
        return $this;
    }

    /**
     * @param string $expr
     * @param ...$values
     * @return $this
     */
    public function where(string $expr, ...$values): ConnectionInterface
    {
        array_push($this->where, ['AND', $expr, $values]);
        return $this;
    }

    /**
     * @param string $expr
     * @param ...$values
     * @return $this
     */
    public function or(string $expr, ...$values): ConnectionInterface
    {
        array_push($this->where, ['OR', $expr, $values]);
        return $this;
    }

    /**
     * @param string $field
     * @param string $order
     * @return $this
     */
    public function order(string $field, string $order): ConnectionInterface
    {
        if (!in_array($order, ['asc', 'desc'])) {
            throw new \RuntimeException('Sort can only be asc or desc.');
        }
        array_push($this->order, [$field, strtoupper($order)]);
        return $this;
    }

    /**
     * @param string ...$fields
     * @return $this
     */
    public function group(string ...$fields): ConnectionInterface
    {
        $this->group = array_merge($this->group, $fields);
        return $this;
    }

    /**
     * @param string $expr
     * @param ...$values
     * @return $this
     */
    public function having(string $expr, ...$values): ConnectionInterface
    {
        array_push($this->having, [$expr, $values]);
        return $this;
    }

    /**
     * offset
     * @param int $length
     * @return $this
     */
    public function offset(int $length): ConnectionInterface
    {
        if ($length < 0) { throw new \InvalidArgumentException('Mix offset cannot be negative.'); }
        $this->offset = $length;
        return $this;
    }

    /**
     * Explicit zero is an empty bound; omit limit() for an unbounded query.
     * @param int $length
     * @return $this
     */
    public function limit(int $length): ConnectionInterface
    {
        if ($length < 0) { throw new \InvalidArgumentException('Mix limit cannot be negative.'); }
        $this->limit = $length;
        return $this;
    }

    /**
     * 意向排它锁
     * @return $this
     */
    public function lockForUpdate(): ConnectionInterface
    {
        $this->lock = 'FOR UPDATE';
        return $this;
    }

    /**
     * 意向共享锁
     * @return $this
     */
    public function sharedLock(): ConnectionInterface
    {
        $this->lock = 'LOCK IN SHARE MODE';
        return $this;
    }

    /**
     * @param string $index
     * @param array $data
     * @return array
     */
    protected function build(string $index, array $data = []): array
    {
        $sqls = $values = [];
        $write = $index === 'UPDATE' || $index === 'DELETE';
        $emptyWrite = $write && $this->limit === 0;
        if ($write && !$emptyWrite && (($this->offset > 0 && $this->dialect !== 'sqlite')
            || ($this->limit !== null && $this->dialect !== 'mysql' && $this->dialect !== 'sqlite'))) {
            throw new \InvalidArgumentException('Mix positive write limits require MySQL or SQLite; write offsets require SQLite.');
        }

        // select
        if ($index == 'SELECT') {
            if ($this->select) {
                $select = implode(', ', $this->select);
                $sqls[] = "SELECT {$select}";
            } else {
                $sqls[] = "SELECT *";
            }
        }

        // delete
        if ($index == 'DELETE') {
            $sqls[] = "DELETE";
        }

        // table
        if ($this->table) {
            // update
            if ($index == 'UPDATE') {
                $set = [];
                foreach ($data as $k => $v) {
                    if ($v instanceof Expr) {
                        $set[] = $this->quoteIdentifier($k) . ' = ' . $v->sql();
                        array_push($values, ...$v->bindings());
                    } else {
                        $set[] = $this->quoteIdentifier($k) . ' = ?';
                        array_push($values, $v);
                    }
                }
                $sqls[] = "UPDATE {$this->table} SET " . implode(', ', $set);
            } else {
                $sqls[] = "FROM {$this->table}";
            }
        }

        // join
        if ($this->join) {
            foreach ($this->join as $item) {
                list($keyword, $table, $on, $vals) = $item;
                $sqls[] = "{$keyword} {$table} ON {$on}";
                array_push($values, ...$vals);
            }
        }

        // where
        $whereStart = $emptyWrite ? count($sqls) : 0;
        if ($this->where) {
            $sqls[] = "WHERE";
            foreach ($this->where as $key => $item) {
                list($keyword, $expr, $vals) = $item;

                if ($key == 0) {
                    $sqls[] = "{$expr}";
                } else {
                    $sqls[] = "{$keyword} {$expr}";
                }
                array_push($values, ...$vals);
            }
        }
        if ($emptyWrite) {
            if ($this->where) {
                $clauses = array_splice($sqls, $whereStart + 1);
                // Scope every OR and terminate a trailing line comment before the empty-match guard.
                $sqls[] = "(\n" . implode(' ', $clauses) . "\n) AND 1=0";
            } else {
                $sqls[] = 'WHERE 1=0';
            }
        }

        // group
        if ($this->group) {
            $sqls[] = "GROUP BY " . implode(', ', $this->group);
        }

        // having
        if ($this->having) {
            $subSql = [];
            foreach ($this->having as $item) {
                list($expr, $vals) = $item;
                $subSql[] = "$expr";
                array_push($values, ...$vals);
            }
            $subSql = count($subSql) == 1 ? array_pop($subSql) : implode(' AND ', $subSql);
            $sqls[] = "HAVING {$subSql}";
        }

        // order
        if ($this->order) {
            $subSql = [];
            foreach ($this->order as $item) {
                list($field, $order) = $item;
                $subSql[] = "{$field} {$order}";
            }
            $sqls[] = "ORDER BY " . implode(', ', $subSql);
        }

        // limit and offset
        if ($emptyWrite) {
            if ($this->dialect === 'sqlite' && $this->order) { $sqls[] = 'LIMIT 0'; }
        } elseif ($this->limit !== null) {
            $withOffset = !$write || $this->dialect === 'sqlite';
            $sqls[] = $withOffset ? 'LIMIT ? OFFSET ?' : 'LIMIT ?';
            $values[] = $this->limit;
            if ($withOffset) { $values[] = $this->offset; }
        } elseif ($this->offset > 0) {
            $sqls[] = match ($this->dialect) {
                'sqlite' => 'LIMIT -1 OFFSET ?',
                'mysql' => 'LIMIT 18446744073709551615 OFFSET ?',
                'pgsql' => 'OFFSET ?',
                default => throw new \InvalidArgumentException('Mix offset-only reads are unsupported by this driver.'),
            };
            $values[] = $this->offset;
        }

        // lock
        if ($this->lock) {
            $sqls[] = $this->lock;
        }

        // clear
        $this->table = '';
        $this->select = [];
        $this->join = [];
        $this->where = [];
        $this->order = [];
        $this->group = [];
        $this->having = [];
        $this->offset = 0;
        $this->limit = null;
        $this->lock = '';

        // 聚合
        return [implode(' ', $sqls), $values];
    }

}
