<?php

namespace RC\Helper\Db\ThinkOrm;

/** Native MySQL grammar with bound, query-scoped closure subqueries. */
class MysqlBuilder extends \think\db\builder\Mysql
{
    use BoundClosureBuilder;
}
