<?php

declare(strict_types=1);

namespace RC\Helper\Db\ThinkOrm;

use think\db\exception\DbException;

/** Preserve native ThinkORM APIs while keeping grouped count() SQL parameterized. */
class Query extends \think\db\Query
{
    /** Count groups, not rows; retain native GROUP/HAVING/LIMIT semantics and execution-time binding consumption. */
    protected function countWithGroup(string $field): int
    {
        if (!preg_match('/^[\w\.\*]+$/', $field)) {
            throw new DbException('Not supported data: ' . $field);
        }

        $inner = new GroupQuery($this->connection);
        $options = $this->getOptions();
        // Native scope entries retain their receiver in argument zero; a copied entry must target this compiler.
        foreach ($options['scope'] ?? [] as $name => [$callback, $arguments]) {
            if (($arguments[0] ?? null) === $this) { $arguments[0] = $inner; }
            $options['scope'][$name] = [$callback, $arguments];
        }
        $inner->options($options)->table($this->getTable())->bind($this->getBind(false));
        if ($this->getModel() !== null) { $inner->model($this->getModel()); }
        $inner->removeOption('cache')->field('count(' . $field . ') AS think_count');
        $inner->parseOptions();
        $sql = $this->connection->getBuilder()->select($inner);
        $bindings = $inner->getBind();

        $outer = $this->newQuery()->removeOption();
        $compiledOptions = $inner->getOptions();
        foreach (['master', 'force_cache', 'cache_always'] as $option) {
            if (array_key_exists($option, $compiledOptions)) { $outer->setOption($option, $compiledOptions[$option]); }
        }
        $cache = $this->getOption('cache');
        if ($cache && $cache[0] === true && method_exists($this->connection,'allowsAutomaticQueryCache')
            && !$this->connection->allowsAutomaticQueryCache()) { $cache = null; }
        if ($cache) {
            if ($cache[0] === true) {
                $signature = BoundSubqueries::signature($sql, $bindings);
                $identity = [];
                foreach (['type', 'hostname', 'hostport', 'database', 'dsn', 'charset', 'prefix', 'username', 'rcmaker_connection_id'] as $key) {
                    $identity[$key] = $this->connection->getConfig($key);
                }
                $cache = $signature === null ? null : ['rc_group_' . hash('sha256', serialize([
                    $identity, $outer->getOption('master'), $signature,
                ])), $cache[1], $cache[2]];
            }
            if ($cache) { $outer->setOption('cache', $cache); }
        }

        if ($this->connection instanceof MysqlConnection || $this->connection instanceof PgsqlConnection) {
            $sql = BoundSubqueries::attach($outer, $sql, $bindings);
        } else {
            $outer->bind($bindings);
        }
        $outer->table(['( ' . $sql . ' )' => '_group_count_']);
        // Native paginate() restores its saved binds after count(); consume once, never append them to themselves.
        $this->getBind();
        return (int) $outer->aggregate('COUNT', '*');
    }
}
