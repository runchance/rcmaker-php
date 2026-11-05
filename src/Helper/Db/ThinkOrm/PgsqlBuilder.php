<?php

namespace RC\Helper\Db\ThinkOrm;

use InvalidArgumentException;
use think\db\BaseQuery;

/** PostgreSQL corrections around the unchanged Composer ThinkORM SQL builder. */
class PgsqlBuilder extends \think\db\builder\Pgsql
{
    use BoundClosureBuilder;

    /** Use VALUES tuples so PostgreSQL infers parameter types from target columns. */
    public function insertAll(BaseQuery $query, array $dataSet): string
    {
        if (!$dataSet) {
            return '';
        }
        $options = $query->getOptions();
        $bind = $query->getFieldsBindType();
        $allowed = empty($options['field']) || $options['field'] === '*' ? array_keys($bind) : $options['field'];
        $fields = null;
        $values = [];
        foreach ($dataSet as $row) {
            $data = $this->parseData($query, $row, $allowed, $bind);
            $fields ??= array_keys($data);
            if (!$fields || count($data) !== count($fields) || array_diff($fields, array_keys($data))) {
                throw new InvalidArgumentException('All bulk insert rows must contain the same fields.');
            }
            $ordered = [];
            foreach ($fields as $field) {
                $ordered[] = $data[$field];
            }
            $values[] = '(' . implode(', ', $ordered) . ')';
        }
        return 'INSERT INTO ' . $this->parseTable($query, $options['table'])
            . ' (' . implode(', ', $fields) . ') VALUES ' . implode(', ', $values)
            . $this->parseComment($query, $options['comment']);
    }
}
