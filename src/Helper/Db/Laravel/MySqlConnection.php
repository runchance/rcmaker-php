<?php
namespace RC\Helper\Db\Laravel;

/** Native MySQL API with RC query ownership; created by the framework factory, not application models. */
class MySqlConnection extends \Illuminate\Database\MySqlConnection
{
    use ManagedConnection;
}
