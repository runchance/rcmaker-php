<?php

namespace RC\Helper\Db\ThinkOrm;

use Closure;
use InvalidArgumentException;
use RC\Helper\Db\SqlParameters;
use think\db\BaseQuery;

/** Keep pristine ThinkORM SQL generation while making parenthesized UNION operands valid SQLite derived tables. */
class SqliteBuilder extends \think\db\builder\Sqlite
{
    /** Compile native union()/unionAll() branches; retain each branch's local ORDER/LIMIT and native value handling. */
    protected function parseUnion(BaseQuery $query, array $union): string
    {
        if (!$union) { return ''; }
        $type = $union['type'] ?? null;
        if ($type !== 'UNION' && $type !== 'UNION ALL') {
            throw new InvalidArgumentException('SQLite compound operator must be UNION or UNION ALL.');
        }
        unset($union['type']);
        $parts = [];
        foreach ($union as $branch) {
            if ($branch instanceof Closure) {
                $sql = $this->parseClosure($query, $branch, false);
            } elseif (is_string($branch)) {
                $sql = $branch;
            } else {
                throw new InvalidArgumentException('SQLite UNION operands must be a string or Closure.');
            }
            if (trim($sql) === '') {
                throw new InvalidArgumentException('SQLite UNION operands must contain nonempty SQL.');
            }
            // A newline also prevents a trusted trailing -- comment from consuming the generated closing parenthesis.
            $parts[] = $type . " SELECT * FROM (\n" . $sql . "\n) AS __rcmaker_union_" . count($parts);
        }
        return $parts ? ' ' . implode(' ', $parts) : '';
    }

    /** Retain bindings for SQLite UNION, IN/EXISTS and scalar subqueries instead of executing diagnostic SQL. */
    protected function parseClosure(BaseQuery $query, Closure $call, bool $show = true): string
    {
        $child = $query->newQuery()->removeOption();
        $call($child);
        $child->parseOptions();
        $sql = $child->getConnection()->getBuilder()->select($child);
        $named = $positional = [];
        foreach ($child->getBind() as $key => $value) {
            if (is_array($value)) {
                if (!array_key_exists(0, $value) || !isset($value[1]) || !is_int($value[1])) {
                    throw new InvalidArgumentException('SQLite UNION binding tuples require a value and PDO type.');
                }
                $name = $query->bindValue($value[0], $value[1]);
            } else {
                $name = $query->bindValue($value);
            }
            if (is_int($key)) { $positional[$key] = ':' . $name; }
            else { $named[$key] = ':' . $name; }
        }
        if (!$named && !$positional) { return $show ? '( ' . $sql . ' )' : $sql; }
        $tokens = SqlParameters::opaqueTokens('sqlite');
        $tokens[] = '(?<!:):[A-Za-z0-9_]+';
        $tokens[] = '\\?(?:[1-9][0-9]*)?';
        $slot = 0;
        $namedSlots = [];
        $rewritten = preg_replace_callback('~' . implode('|', $tokens) . '~s', static function (array $match) use ($named, $positional, &$slot, &$namedSlots): string {
            $token = $match[0];
            if ($token[0] === ':' && preg_match('/\A:([A-Za-z0-9_]+)\z/D', $token, $parameter)) {
                $namedSlots[$parameter[1]] ??= ++$slot;
                return $named[$parameter[1]] ?? $token;
            }
            if ($token[0] !== '?') { return $token; }
            $index = strlen($token) > 1 ? (int) substr($token, 1) : $slot + 1;
            $slot = max($slot, $index);
            if (!isset($positional[$index - 1])) {
                throw new InvalidArgumentException('Missing positional SQLite UNION closure binding.');
            }
            return $positional[$index - 1];
        }, $sql);
        if ($rewritten === null) { throw new \RuntimeException('Cannot rewrite SQLite UNION bindings: ' . preg_last_error_msg()); }
        return $show ? '( ' . $rewritten . ' )' : $rewritten;
    }
}
