<?php

namespace RC\Helper\Db;

use Illuminate\Database\ConnectionInterface;
use RC\Bootstrap;
use RC\Config;
use RC\Helper\Db\Laravel\Capsule;

/** Optional Illuminate bootstrap. Enable in config/bootstrap.php; models/controllers must not initialize it. */
class Laravel implements Bootstrap
{
    public static $dbs = [];
    public static $_version = '13.x (Composer)';
    public static $support = ['mysql', 'mariadb', 'sqlite', 'pgsql', 'sqlsrv', 'oracle', 'mongodb'];
    private static ?Capsule $capsule = null;
    private static ?array $configuration = null;

    /** Fail clearly when an optional engine is missing, without requiring MongoDB for SQL-only projects. */
    public static function requirePackage(?string $driver = null): void
    {
        if (!class_exists(\Illuminate\Database\Capsule\Manager::class)) {
            throw new \RuntimeException('Illuminate is optional. Run composer require illuminate/database:13.40.0, then enable RC\Helper\Db\Laravel in bootstrap.');
        }
        if ($driver === 'mongodb' && (!extension_loaded('mongodb') || !class_exists(\MongoDB\Laravel\Connection::class))) {
            throw new \RuntimeException('MongoDB requires ext-mongodb and composer require mongodb/laravel-mongodb:5.12.0. The Jenssegers namespace is no longer used.');
        }
    }

    /** Preserve native settings; string-key RC options are configuration, integer keys are PDO options. */
    private static function createConfig(array $config): ?array
    {
        $connections = $config['driver'] ?? $config['connections'] ?? [];
        $default = $config['default'] ?? null;
        if ($default === null || !is_array($connections) || !array_key_exists($default, $connections)) {
            return null;
        }
        $result = ['default' => $default, 'connections' => []];
        foreach ($connections as $name => $settings) {
            if (!is_string($name) || $name === '' || str_contains($name, '.') || str_contains($name, '::') || str_starts_with($name, '__rcmaker_')
                || !is_array($settings)) {
                throw new \InvalidArgumentException('Invalid Laravel connection name or configuration.');
            }
            $driver = $settings['driver'] ?? $settings['type'] ?? $name;
            if (!is_string($driver) || !in_array($driver, self::$support, true)) {
                throw new \InvalidArgumentException('Unsupported Laravel driver: ' . (is_string($driver) ? $driver : get_debug_type($driver)));
            }
            if ($driver !== 'mongodb') {
                $options = $settings['options'] ?? [];
                if (!is_array($options)) {
                    throw new \InvalidArgumentException('Laravel connection options must be an array: ' . $name);
                }
                unset($settings['options']);
                $pdoOptions = [];
                foreach ($options as $key => $value) {
                    if (is_int($key)) { $pdoOptions[$key] = $value; }
                    elseif ($key === 'option' || $key === 'params' || $key === 'options') {
                        if (!is_array($value)) { throw new \InvalidArgumentException('Laravel PDO options must be an array.'); }
                        $pdoOptions = array_replace($pdoOptions, $value);
                    } else { $settings[$key] = $value; }
                }
                if (!empty($pdoOptions[\PDO::ATTR_PERSISTENT])) {
                    throw new \InvalidArgumentException('Persistent PDO is not supported by managed RC Laravel connections; keep resident connections non-persistent.');
                }
                $pdoOptions[\PDO::ATTR_ERRMODE] = \PDO::ERRMODE_EXCEPTION;
                $pdoOptions[\PDO::ATTR_PERSISTENT] = false;
                $settings['options'] = $pdoOptions;
                foreach (['hostname' => 'host', 'hostport' => 'port'] as $legacy => $native) {
                    if (array_key_exists($legacy, $settings) && !array_key_exists($native, $settings)) { $settings[$native] = $settings[$legacy]; }
                    unset($settings[$legacy]);
                }
                if (isset($settings['socket'])) {
                    $native = $driver === 'pgsql' ? 'host' : 'unix_socket';
                    $settings[$native] ??= $settings['socket'];
                    unset($settings['socket']);
                }
            }
            $settings['driver'] = $driver;
            unset($settings['type']);
            if (($settings['charset'] ?? null) === '') { unset($settings['charset']); }
            $result['connections'][$name] = $settings;
        }
        return $result;
    }

    /** Initialize one native manager and one RC model resolver. Repeated bootstrap does not change defaults/events. */
    public static function start()
    {
        if (self::$capsule !== null) { return; }
        self::requirePackage();
        $config = self::createConfig(Config::get('db') ?: []);
        if ($config !== null) { self::register($config); }
    }

    /** Internal compatibility registration used by database(..., class, config); not a business initialization API. */
    public static function register(array $config): void
    {
        self::requirePackage();
        if (self::$capsule !== null) {
            if ($config !== self::$configuration) { throw new \LogicException('Laravel configuration is already initialized; restart after changing it.'); }
            return;
        }
        if (!isset($config['default'], $config['connections'][$config['default']])) {
            throw new \InvalidArgumentException('Laravel registration requires a configured default connection.');
        }
        self::requirePackage($config['connections'][$config['default']]['driver']);
        $capsule = new Capsule();
        foreach ($config['connections'] as $name => $settings) { $capsule->addConnection($settings, $name); }
        $container = $capsule->getContainer();
        $container['config']['database.default'] = $config['default'];
        $transactions = new \RC\Helper\Db\Laravel\TransactionsManager();
        $container->instance('db.transactions', $transactions);
        if (class_exists(\Illuminate\Events\Dispatcher::class)) {
            $events = new \Illuminate\Events\Dispatcher($container);
            $events->setTransactionManagerResolver(static fn() => $transactions);
            $capsule->setEventDispatcher($events);
        }
        $capsule->getDatabaseManager()->extend('mongodb', static function (array $settings, string $name) {
            self::requirePackage('mongodb');
            $settings['name'] = $name;
            return new \RC\Helper\Db\Laravel\MongoConnection($settings);
        });
        self::$configuration = $config;
        self::$capsule = $capsule;
        \RC\Model\Laravel::setConnectionResolver($capsule->getDatabaseManager());
        if ($dispatcher = $capsule->getEventDispatcher()) { \RC\Model\Laravel::setEventDispatcher($dispatcher); }
        if (class_exists(\MongoDB\Laravel\Eloquent\Model::class)) {
            \RC\Model\MongoDB::setConnectionResolver($capsule->getDatabaseManager());
            if ($dispatcher) { \RC\Model\MongoDB::setEventDispatcher($dispatcher); }
        }
    }

    /** Native manager accessor for framework bootstraps/optional extensions; it never changes a global Capsule. */
    public static function manager(): \Illuminate\Database\DatabaseManager
    {
        if (self::$capsule === null) {
            throw new \RuntimeException('Enable RC\Helper\Db\Laravel in config/bootstrap.php before using DB or Eloquent models.');
        }
        return self::$capsule->getDatabaseManager();
    }

    /** Preserve the old Capsule object/static entry; native:true and SDB bypass this compatibility view. */
    public static function legacy(?string $type = null, int|string $id = 1): Capsule
    {
        $connection = self::connection($type, $id);
        return \RC\Helper\Db\Laravel\LegacyCapsule::rcmakerBind(self::$capsule, $connection->getName());
    }

    /** Internal rare lifecycle hook; never scan or compare native caches on the ordinary connection fast path. */
    public static function synchronizeConnectionCache(\Illuminate\Database\DatabaseManager $manager): void
    {
        if (self::$capsule === null || self::$capsule->getDatabaseManager() !== $manager) { return; }
        $connections = $manager->getConnections();
        foreach (self::$dbs as $type => $ids) {
            foreach ($ids as $id => $connection) {
                $name = (string) $id === '1' ? $type : '__rcmaker_' . hash('sha256', $type . "\0" . (string) $id);
                if (($connections[$name] ?? null) !== $connection) { unset(self::$dbs[$type][$id]); }
            }
            if (!self::$dbs[$type]) { unset(self::$dbs[$type]); }
        }
    }

    /** Resolve one stable connection identity. Nondefault IDs cannot change the default model connection. */
    public static function connection(?string $type = null, int|string $id = 1): ConnectionInterface
    {
        $manager = self::manager();
        $type = $type === null || $type === '' ? self::$configuration['default'] : $type;
        if (!isset(self::$configuration['connections'][$type])) {
            throw new \InvalidArgumentException('Laravel connection is not configured: ' . $type);
        }
        if (isset(self::$dbs[$type][(string) $id])) { return self::$dbs[$type][(string) $id]; }
        $settings = self::$configuration['connections'][$type];
        self::requirePackage($settings['driver']);
        $name = $type;
        if ((string) $id !== '1') {
            $name = '__rcmaker_' . hash('sha256', $type . "\0" . (string) $id);
            self::$capsule->addConnection($settings, $name);
        }
        $connection = $manager->connection($name);
        return self::$dbs[$type][(string) $id] = $connection;
    }
}
