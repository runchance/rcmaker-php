<?php
namespace RC\Helper\Db\Laravel;

/** Preserve the native MariaDB-specific grammar and APIs while using RC query ownership. */
class MariaDbConnection extends \Illuminate\Database\MariaDbConnection
{
    use ManagedConnection;
}
