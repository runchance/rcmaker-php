<?php

namespace RC\Helper\Db\Laravel;

/** Laravel-specific SDB translation. Uses the owning Db state and native builders, never a second SQL engine. */
trait SdbMethods
{
    private bool $laravelReadOperation = true;
    private bool $laravelRootWritePdo = false;
    /** Run one SDB operation without enabling, flushing or changing a foreign Illuminate query log. */
    private function executeLaravel(callable $operation)
    {
        $scope = $this->enbleDebug && method_exists($this->connect, 'beginRcmakerTrace') ? $this->connect->beginRcmakerTrace() : null;
        $this->error = $this->sql = null;
        try {
            return $operation();
        } catch (\Throwable $error) {
            $this->error = $error->getMessage();
            return false;
        } finally {
            if ($scope !== null) { $this->sql = $this->connect->endRcmakerTrace($scope); }
        }
    }

    /** Preserve upstream expressions/subqueries; comma-separated ordinary columns remain convenient. */
    private function laravelFields($fields): array
    {
        if (is_array($fields)) { return $fields; }
        if ($fields instanceof \Illuminate\Contracts\Database\Query\Expression) { return [$fields]; }
        if (!is_string($fields) || trim($fields) === '') { throw new \InvalidArgumentException('Laravel fields must be columns or native expressions.'); }
        return array_map('trim', explode(',', $fields));
    }

    /** Standard SQL rows become arrays without JSON round trips or scalar type loss. */
    private function laravelRow($row)
    {
        if ($row instanceof \Illuminate\Contracts\Support\Arrayable) { return $row->toArray(); }
        return $row instanceof \stdClass ? (array) $row : $row;
    }

    /** Normalize returned row containers only, retaining native values inside each row. */
    private function laravelRows($rows): array
    {
        if ($rows instanceof \Illuminate\Support\Collection) { $rows = $rows->all(); }
        return array_map(fn($row) => $this->laravelRow($row), $rows);
    }

    /** Count selected groups/distinct rows through a native subquery, including null rows and bound expressions. */
    private function laravelPaginationCount(array $columns): int
    {
        $query = clone $this->conn;
        if ($query->columns === null) { $query->select($columns); }
        if ($this->connect->getDriverName() === 'mongodb') { return $query->getCountForPagination(); }
        if ($query->groups || $query->havings || $query->distinct || $query->unions) {
            $query = $query->cloneWithout(['orders', 'limit', 'offset', 'unionOrders', 'unionLimit', 'unionOffset'])
                ->cloneWithoutBindings(['order', 'unionOrder']);
            $count = $this->connect->query();
            if ($query->useWritePdo) { $count->useWritePdo(); }
            return $count->fromSub($query, '__rcmaker_count')->count();
        }
        return $query->getCountForPagination();
    }

    /** Normalize legacy three-column joins and native four-argument joins without dropping remaining filters. */
    private function applyLaravelJoins(): void
    {
        if ($this->connect->getDriverName() === 'mongodb') { throw new \InvalidArgumentException('MongoDB does not support SQL joins; use native document relationships or aggregation.'); }
        foreach ($this->join as $arguments) {
            $count = count($arguments);
            $type = 'INNER';
            $last = $count > 0 ? $arguments[$count - 1] : null;
            $hasOperator = $count >= 3 && is_string($arguments[2]) && in_array(strtolower($arguments[2]), $this->conn->operators, true);
            if ($count === 5 || ($count === 4 && !$hasOperator && is_string($last) && in_array(strtoupper($last), ['LEFT', 'RIGHT', 'INNER'], true))) {
                $type = strtoupper(array_pop($arguments));
            }
            if (!in_array($type, ['INNER', 'LEFT', 'RIGHT'], true)) { throw new \InvalidArgumentException('Invalid Laravel join type.'); }
            if (count($arguments) === 3) { array_splice($arguments, 2, 0, ['=']); }
            if (count($arguments) !== 4 && !(count($arguments) === 2 && is_callable($arguments[1]))) {
                throw new \InvalidArgumentException('Laravel joins require two columns or a native join closure.');
            }
            $method = match ($type) { 'LEFT' => 'leftJoin', 'RIGHT' => 'rightJoin', default => 'join' };
            $this->conn->{$method}(...$arguments);
        }
    }

    /** Column/direction data stays quoted by the native builder; trusted raw order expressions use bind(). */
    private function applyLaravelOrder(): void
    {
        if (is_array($this->order)) {
            foreach ($this->order as $key => $entry) {
                if (is_string($key) && is_string($entry)) { $this->conn->orderBy($key, $entry); continue; }
                if (!is_array($entry)) { throw new \InvalidArgumentException('Invalid Laravel order configuration.'); }
                $column = $entry['f'] ?? $entry[0] ?? null;
                if (!is_string($column)) { throw new \InvalidArgumentException('Order columns must be strings.'); }
                $this->conn->orderBy($column, $entry['t'] ?? $entry[1] ?? 'DESC');
            }
        } elseif ($this->order === 'rand()') {
            $this->conn->inRandomOrder();
        } elseif (is_string($this->order)) {
            if (preg_match('/\A(.+?)\s+(ASC|DESC)\s*\z/i', $this->order, $match)) { $this->conn->orderBy($match[1], $match[2]); }
            else { $this->conn->orderBy($this->order); }
        } else { throw new \InvalidArgumentException('Invalid Laravel order configuration.'); }
    }

    /** Preserve zero limits and reject numeric overflow/negative values instead of silently widening a query. */
    private function applyLaravelLimit(): void
    {
        $limit = $this->limit;
        $offset = $this->offset ?? 0;
        $mongo = $this->connect->getDriverName() === 'mongodb';
        if ($limit === 0) {
            // Nest the entire original predicate so a native root OR cannot bypass the empty-set guard.
            $where = $this->conn;
            $this->conn = $where->cloneWithout(['wheres'])->cloneWithoutBindings(['where']);
            $this->conn->addNestedWhereQuery($where)->whereRaw($mongo ? ['$expr' => false] : '1=0');
        }
        if ($mongo) {
            if ($limit !== 0 && !$this->laravelReadOperation) {
                throw new \InvalidArgumentException('MongoDB SDB writes cannot apply a row limit; use a unique document condition or a native single-document operation.');
            }
            if (!$this->laravelReadOperation && $offset > 0) { throw new \InvalidArgumentException('MongoDB writes do not support offset.'); }
        }
        $this->conn->offset($offset)->limit($limit);
    }

    /** Do not turn misspelled exclusive locks into weaker shared locks. Native SQL remains dialect-specific. */
    private function applyLaravelLock(): void
    {
        if ($this->connect->getDriverName() === 'mongodb') { throw new \InvalidArgumentException('MongoDB does not support SQL row locks; use transactions or atomic document operations.'); }
        if ($this->lock === true) { $this->conn->lockForUpdate(); return; }
        $lock = is_string($this->lock) ? strtoupper(trim($this->lock)) : '';
        if ($lock === 'FOR UPDATE') { $this->conn->lockForUpdate(); }
        elseif (in_array($lock, ['FOR SHARE', 'LOCK IN SHARE MODE'], true)) { $this->conn->sharedLock(); }
        elseif (preg_match('/\AFOR (?:UPDATE|NO KEY UPDATE|SHARE|KEY SHARE)(?: (?:NOWAIT|SKIP LOCKED))?\z/', $lock)) { $this->conn->lock($lock); }
        else { throw new \InvalidArgumentException('Unsupported Laravel row lock.'); }
    }

    /** Preserve framework boolean grouping while delegating each predicate to upstream. */
    private function applyLaravelWhere($query): void
    {
        $and = $or = [];
        foreach ($this->where as $condition) {
            $boolean = 'AND';
            if (is_array($condition) && isset($condition['raw'])) { $boolean = $condition['boolean']; }
            elseif (is_array($condition)) {
                $count = count($condition);
                $closure = isset($condition[0]) && !is_string($condition[0]) && is_callable($condition[0]);
                if ($count === 4 || ($count === 2 && $closure)) { $boolean = array_pop($condition); }
                elseif ($count === 3 && is_string($condition[2]) && in_array(strtoupper($condition[2]), ['AND', 'OR'], true)
                    && !(is_string($condition[1]) && (in_array(strtolower($condition[1]), $query->operators, true)
                        || in_array(strtolower($condition[1]), $query->getGrammar()->getOperators(), true)))) {
                    $boolean = array_pop($condition);
                }
            }
            if (!is_string($boolean) || !in_array(strtoupper($boolean), ['AND', 'OR'], true)) {
                throw new \InvalidArgumentException('Invalid Laravel WHERE connector.');
            }
            if (strtoupper($boolean) === 'OR') { $or[] = $condition; } else { $and[] = $condition; }
        }
        if (count($or) === 1 && $and) {
            $query->where(function ($group) use ($and, $or): void {
                $group->where(function ($inner) use ($and): void {
                    foreach ($and as $condition) { $this->applyLaravelCondition($inner, $condition); }
                });
                $this->applyLaravelCondition($group, $or[0], 'OR');
            });
            return;
        }
        foreach ($and as $condition) { $this->applyLaravelCondition($query, $condition); }
        if ($or) {
            $query->where(function ($group) use ($or): void {
                foreach ($or as $condition) { $this->applyLaravelCondition($group, $condition, 'OR'); }
            });
        }
    }

    /** Typed closure groups receive SDB rather than an unrelated native builder instance. */
    private function applyLaravelCondition($query, string|array $condition, string $boolean = 'AND'): void
    {
        if (is_string($condition) || isset($condition['raw'])) {
            if ($this->connect->getDriverName() === 'mongodb') { throw new \InvalidArgumentException('MongoDB conditions cannot use SQL strings; use document conditions or native MQL.'); }
            $sql = is_string($condition) ? $condition : $condition['raw'];
            if (trim($sql) === '') { throw new \InvalidArgumentException('Empty raw WHERE conditions are not allowed.'); }
            $bindings = is_string($condition) ? [] : $condition['bindings'];
            foreach (array_keys($bindings) as $key) {
                if (!is_string($key)) { continue; }
                if (!method_exists($this->connect, 'normalizeRcmakerParameters')) { throw new \LogicException('Named SDB parameters require a managed RC Laravel connection.'); }
                $read = $this->laravelReadOperation && !$this->lock && !$this->laravelRootWritePdo && !$this->conn->useWritePdo;
                [$sql, $bindings] = $this->connect->normalizeRcmakerParameters($sql, $bindings, $read);
                break;
            }
            $query->whereRaw($sql, $bindings, $boolean);
            return;
        }
        $method = $boolean === 'OR' ? 'orWhere' : 'where';
        if (!is_string($condition[0]) && is_callable($condition[0])) {
            $query->{$method}(function ($inner) use ($condition): void {
                $group = clone $this;
                $group->laravelRootWritePdo = $this->laravelRootWritePdo || $this->conn->useWritePdo;
                $group->where = $group->whereExp = $group->bind = null;
                $group->conn = $inner;
                $condition[0]($group);
                if (!$group->where && !$group->whereExp && !$group->bind) { throw new \InvalidArgumentException('Empty WHERE closure groups are not allowed.'); }
                $group->build(['where', 'whereExp', 'bind']);
            });
            return;
        }
        if (count($condition) < 2 || count($condition) > 3 || !is_string($condition[0])) {
            throw new \InvalidArgumentException('Invalid Laravel SDB condition.');
        }
        if (count($condition) === 3) {
            $alias = strtolower((string) $condition[1]);
            if (in_array($alias, ['in', 'wi', 'not in', 'wni', 'notin', 'between', 'wb', 'bt', 'not between', 'wnb', 'nbt'], true)) {
                $native = match ($alias) {
                    'in', 'wi' => 'whereIn', 'not in', 'wni', 'notin' => 'whereNotIn',
                    'between', 'wb', 'bt' => 'whereBetween', default => 'whereNotBetween',
                };
                $query->{$native}($condition[0], $condition[2], strtolower($boolean));
                return;
            }
        }
        if ($this->connect->getDriverName() === 'mongodb') {
            $value = $condition[count($condition) - 1];
            $equals = count($condition) === 2 || (is_string($condition[1]) && in_array(strtolower($condition[1]), ['=', 'eq'], true));
            if ($equals && (is_array($value) || is_object($value)) && !$value instanceof \MongoDB\BSON\Regex) {
                // An equality value is data, even when an input document contains keys such as $ne.
                $query->whereRaw([$condition[0] => ['$eq' => $value]], [], strtolower($boolean));
                return;
            }
        }
        $query->{$method}(...$condition);
    }

    /** Misspelled filters must not widen writes; native equivalents of documented aliases remain available. */
    private function applyLaravelWhereExp(): void
    {
        $map = ['or' => 'orWhere', 'wb' => 'whereBetween', 'wnb' => 'whereNotBetween', 'wc' => 'whereColumn',
            'wt' => 'whereTime', 'wd' => 'whereDay', 'wdt' => 'whereDate', 'wm' => 'whereMonth', 'wy' => 'whereYear',
            'wi' => 'whereIn', 'wni' => 'whereNotIn', 'wn' => 'whereNull', 'wnn' => 'whereNotNull',
            'wl' => 'whereLike', 'wnl' => 'whereNotLike', 'we' => 'whereExists', 'wne' => 'whereNotExists'];
        foreach (array_values($map) as $method) { $map[strtolower($method)] = $method; }
        foreach ($this->whereExp as [$alias, $parameters]) {
            $key = strtolower($alias); $method = $map[$key] ?? null;
            if ($method === null && str_starts_with($key, 'or')) {
                $base = $map[substr($key, 2)] ?? null;
                if ($base !== null && str_starts_with($base, 'where')) { $method = 'or' . ucfirst($base); }
            }
            if ($method === null || !method_exists($this->conn, $method)) { throw new \InvalidArgumentException('Unsupported Laravel whereExp(): ' . $alias); }
            $required = str_contains($method, 'Null') || str_contains($method, 'Exists') ? 1 : 2;
            if (preg_match('/(?:Column|Time|Day|Date|Month|Year)$/', $method) && count($parameters) >= 3
                && is_string($parameters[1]) && in_array(strtolower($parameters[1]), $this->conn->operators, true)) { $required = 3; }
            if (count($parameters) > $required && is_string(end($parameters)) && strtoupper(end($parameters)) === 'OR') {
                array_pop($parameters);
                if (str_starts_with($method, 'where')) { $method = 'or' . ucfirst($method); }
            }
            if ($this->connect->getDriverName() === 'mongodb' && $method === 'orWhere') {
                $this->applyLaravelCondition($this->conn, $parameters, 'OR');
                continue;
            }
            $this->conn->{$method}(...$parameters);
        }
    }

    /** Use native counting/fetching and the existing RC paginator, including simple and explicit-total modes. */
    private function paginateLaravel($field, $listRows, $simple, string $method)
    {
        $config = is_array($listRows) ? $listRows : [];
        $size = (int) ($config['list_rows'] ?? $config['perPage'] ?? (is_array($listRows) ? 15 : $listRows) ?? 15);
        if ($size < 1 || $size >= PHP_INT_MAX) { throw new \InvalidArgumentException('Pagination page size must be positive.'); }
        $pageName = $config['var_page'] ?? $config['pageName'] ?? 'page';
        $page = max(1, (int) ($config['page'] ?? $this->request->{$method}($pageName)));
        if ($page - 1 > intdiv(PHP_INT_MAX, $size)) { throw new \InvalidArgumentException('Pagination offset exceeds integer range.'); }
        $total = is_int($simple) ? $simple : null;
        if ($total !== null && $total < 0) { throw new \InvalidArgumentException('Pagination total cannot be negative.'); }
        if (is_int($simple)) { $simple = false; }
        $columns = $this->laravelFields($field);
        if (!$simple && $total === null) { $total = $this->laravelPaginationCount($columns); }
        $rows = $this->laravelRows($this->conn->offset(($page - 1) * $size)->limit($simple ? $size + 1 : $size)->get($columns));
        $more = $simple ? count($rows) > $size : null;
        if ($simple) { $rows = array_slice($rows, 0, $size); }
        $path = trim($config['path'] ?? '/?' . rawurlencode($pageName) . '=[PAGE]');
        $parts = parse_url($path);
        if ($parts === false || preg_match('/[\x00-\x1f\x7f]/', $path)
            || isset($parts['scheme']) && !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Pagination path must be an HTTP(S) or relative URL.');
        }
        [$path, $existingFragment] = array_pad(explode('#', $path, 2), 2, '');
        $query = $config['query'] ?? [];
        if (!is_array($query)) { throw new \InvalidArgumentException('Pagination query must be an array.'); }
        unset($query[$pageName]);
        $extra = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        if ($extra !== '') { $path .= (str_contains($path, '?') ? '&' : '?') . $extra; }
        if (!str_contains($path, '[PAGE]')) { $path .= (str_contains($path, '?') ? '&' : '?') . rawurlencode($pageName) . '=[PAGE]'; }
        $fragment = array_key_exists('fragment', $config) ? rawurlencode((string) $config['fragment']) : $existingFragment;
        if ($fragment !== '') { $path .= '#' . $fragment; }
        return new \RC\Helper\Paginator($total, $size, $page, $path, $rows, $more);
    }
}
