<?php

declare(strict_types=1);

namespace RC\Helper\Db\ThinkOrm;

/** Preserve the pristine SQLite connector while adapting automatic query caching and native grouped counts. */
class SqliteConnection extends \think\db\connector\Sqlite
{
    use CachedQueryConnection;
    use ManagedCursor;

    protected const RC_CACHE_DIALECT = 'sqlite';

    /** Respect a developer-selected query class instead of silently replacing it. */
    public function getQueryClass(): string
    {
        return $this->getConfig('query') ?: Query::class;
    }

    /** Keep RC SQLite UNION/closure grammar as the default, with explicit custom builders preserved. */
    public function getBuilderClass(): string
    {
        return $this->getConfig('builder') ?: SqliteBuilder::class;
    }
}
