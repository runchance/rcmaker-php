<?php
namespace RC\Helper\Db\Laravel;

/** Native SQLite connection for development and standalone deployments; no driver SQL is reimplemented. */
class SQLiteConnection extends \Illuminate\Database\SQLiteConnection
{
    use ManagedConnection;
}
