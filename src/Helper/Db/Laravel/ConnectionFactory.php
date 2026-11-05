<?php

namespace RC\Helper\Db\Laravel;

/** A per-manager factory, never a global Connection::resolverFor() registration. */
class ConnectionFactory extends \Illuminate\Database\Connectors\ConnectionFactory
{
    /** The manager's logical name owns callbacks; optional MongoDB build/connectUsing must not enter PDO construction. */
    public function make(array $config, $name = null)
    {
        if ($name !== null) { $config['name'] = $name; }
        if (($config['driver'] ?? null) === 'mongodb') {
            \RC\Helper\Db\Laravel::requirePackage('mongodb');
            return new MongoConnection($config);
        }
        return parent::make($config, $name);
    }

    /** Validate final merged read/write/direct PDO settings, not just top-level framework options. */
    protected function createPdoResolver(array $config)
    {
        $options = $config['options'] ?? [];
        if (!is_array($options) || array_filter(array_keys($options), static fn($key) => !is_int($key))) {
            throw new \InvalidArgumentException('Laravel PDO options must use integer PDO attribute keys.');
        }
        if (!empty($options[\PDO::ATTR_PERSISTENT])) {
            throw new \InvalidArgumentException('Persistent PDO is not supported by managed RC Laravel connections.');
        }
        $options[\PDO::ATTR_ERRMODE] = \PDO::ERRMODE_EXCEPTION;
        $options[\PDO::ATTR_PERSISTENT] = false;
        $config['options'] = $options;
        return parent::createPdoResolver($config);
    }
    /** Retain native connectors except the framework-relative SQLite path adapter. */
    public function createConnector(array $config)
    {
        return ($config['driver'] ?? null) === 'sqlite' ? new SQLiteConnector() : parent::createConnector($config);
    }
    /** Retain native PDO creation, read/write/direct setup, grammars and processors. */
    protected function createConnection($driver, $connection, $database, $prefix = '', array $config = [])
    {
        $class = match ($driver) {
            'mysql' => MySqlConnection::class,
            'mariadb' => MariaDbConnection::class,
            'pgsql' => PostgresConnection::class,
            'sqlite' => SQLiteConnection::class,
            'sqlsrv' => SqlServerConnection::class,
            default => null,
        };
        if ($class === null) { return parent::createConnection($driver, $connection, $database, $prefix, $config); }
        if (\Illuminate\Database\Connection::getResolver($driver)) {
            throw new \LogicException('A global Illuminate connection resolver conflicts with the managed RC driver: ' . $driver);
        }
        return new $class($connection, $database, $prefix, $config);
    }
}
