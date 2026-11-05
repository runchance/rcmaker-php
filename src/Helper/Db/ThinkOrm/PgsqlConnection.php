<?php

namespace RC\Helper\Db\ThinkOrm;

use think\db\BaseQuery;

/** Bind PostgreSQL identity retrieval to the inserted table, not a trigger's last sequence. */
class PgsqlConnection extends \think\db\connector\Pgsql
{
    use BoundQueryConnection;
    use CachedQueryConnection;
    use ManagedCursor;

    protected const RC_BOUND_DIALECT = 'pgsql';
    protected const RC_CACHE_DIALECT = 'pgsql';

    private array $sequences = [];

    /** Keep custom query classes opt-in while adapting the default grouped-count path. */
    public function getQueryClass(): string
    {
        return $this->getConfig('query') ?: Query::class;
    }

    /** Select the upstream-compatible PostgreSQL builder when no custom builder is configured. */
    public function getBuilderClass(): string
    {
        return $this->getConfig('builder') ?: PgsqlBuilder::class;
    }

    /** Resolve sequence metadata once per table/key and retain the insert SQL for diagnostics. */
    public function getLastInsID(BaseQuery $query, ?string $sequence = null)
    {
        $table = $query->getTable();
        $primaryKey = $query->getAutoInc();
        // PDO's sequence-less lastInsertId() issues LASTVAL(); even a caught error aborts a PostgreSQL transaction.
        if ($sequence === null && (!is_string($table) || !is_string($primaryKey) || $primaryKey === '')) {
            return '';
        }
        if ($sequence === null && is_string($table) && is_string($primaryKey) && $primaryKey !== '') {
            $key = $table . "\0" . $primaryKey;
            if (!array_key_exists($key, $this->sequences)) {
                $sql = $this->queryStr;
                $bindings = $this->bind;
                try {
                    $rows = $this->query('SELECT pg_get_serial_sequence(:table_name, :column_name) AS sequence_name',
                        ['table_name' => $table, 'column_name' => $primaryKey], true);
                    $this->sequences[$key] = $rows[0]['sequence_name'] ?? null;
                } finally {
                    $this->queryStr = $sql;
                    $this->bind = $bindings;
                }
            }
            $sequence = $this->sequences[$key];
            if ($sequence === null || $sequence === '') {
                return '';
            }
        }
        return parent::getLastInsID($query, $sequence);
    }
}
