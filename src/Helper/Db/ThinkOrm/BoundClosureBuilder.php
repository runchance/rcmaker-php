<?php

declare(strict_types=1);

namespace RC\Helper\Db\ThinkOrm;

use Closure;
use think\db\BaseQuery;

/** MySQL/PostgreSQL builders defer child binding transport, not SQL grammar, to the executing RC connection. */
trait BoundClosureBuilder
{
    /** Compile the unchanged native query builder; never replay buildSql()/fetchSql() diagnostic interpolation. */
    protected function parseClosure(BaseQuery $query, Closure $call, bool $show = true): string
    {
        $child = $query->newQuery()->removeOption();
        $call($child);
        $child->parseOptions();
        $sql = $child->getConnection()->getBuilder()->select($child);
        $marker = BoundSubqueries::attach($query, $sql, $child->getBind());
        return $show ? '( ' . $marker . ' )' : $marker;
    }
}
