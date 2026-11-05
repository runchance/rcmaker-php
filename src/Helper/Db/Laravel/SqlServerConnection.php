<?php
namespace RC\Helper\Db\Laravel;

/** Native SQL Server grammar with RC query ownership; real-server validation is recorded separately. */
class SqlServerConnection extends \Illuminate\Database\SqlServerConnection
{
    use ManagedConnection;
}
