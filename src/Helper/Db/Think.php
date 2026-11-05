<?php

namespace RC\Helper\Db;

use RC\Bootstrap;
use RC\Config;
use RuntimeException;

/** Optional ThinkORM bootstrap. Enable this class in bootstrap after Composer installation. */
class Think implements Bootstrap
{
    public static $_version = '4.x (Composer)';
    public static $support = ['mysql', 'sqlite', 'pgsql', 'sqlsrv', 'mongodb', 'oracle'];
    private static ?array $config = null;

    /** Fail at startup, not at the first business query, when the optional ORM is absent. */
    public static function requirePackage(): void
    {
        if (!class_exists(\think\DbManager::class)) {
            throw new RuntimeException('ThinkORM is optional. Run composer require topthink/think-orm:^4.0.51, then restart rcmaker.');
        }
    }

    /** Map framework connection names while preserving upstream-specific options. */
    private static function createConfig(array $config): ?array
    {
        $default = $config['default'] ?? null;
        if (!$default || !isset($config['driver'][$default])) {
            return null;
        }
        $result = ['default' => $default, 'auto_timestamp' => false, 'connections' => []];
        foreach ($config['driver'] as $name => $settings) {
            $options = $settings['options'] ?? [];
            if (!is_array($options)) {
                throw new \InvalidArgumentException('ThinkORM connection options must be an array: ' . $name);
            }
            unset($settings['options']);
            $settings = array_replace($settings, $options);
            foreach (['host' => 'hostname', 'port' => 'hostport'] as $legacy => $native) {
                if (array_key_exists($legacy, $settings) && !array_key_exists($native, $settings)) {
                    $settings[$native] = $settings[$legacy];
                }
                unset($settings[$legacy]);
            }
            $settings['type'] = $settings['type'] ?? ($name === 'mongodb' ? 'mongo' : $name);
            if ($settings['type'] === 'mysql') {
                $settings['type'] = ThinkOrm\MysqlConnection::class;
                $settings['builder'] ??= ThinkOrm\MysqlBuilder::class;
            }
            if ($settings['type'] === 'pgsql') {
                $settings['type'] = ThinkOrm\PgsqlConnection::class;
                $settings['builder'] ??= ThinkOrm\PgsqlBuilder::class;
            }
            if ($settings['type'] === 'sqlite') {
                $settings['type'] = ThinkOrm\SqliteConnection::class;
                if (empty($settings['builder'])) { $settings['builder'] = ThinkOrm\SqliteBuilder::class; }
                if (empty($settings['query'])) { $settings['query'] = ThinkOrm\Query::class; }
            }
            if (isset($settings['params']) && !is_array($settings['params'])) {
                throw new \InvalidArgumentException('ThinkORM params must contain PDO options: ' . $name);
            }
            $settings['params'][\PDO::ATTR_ERRMODE] = \PDO::ERRMODE_EXCEPTION;
            if (($settings['charset'] ?? null) === '') {
                unset($settings['charset']);
            }
            $result['connections'][$name] = $settings;
        }
        return $result;
    }

    /** Framework-owned initialization; do not call this from models or controllers. */
    public static function start()
    {
        self::requirePackage();
        self::$config ??= self::createConfig(Config::get('db') ?? []);
        if (self::$config === null) {
            return;
        }
        // One manager initializes upstream models once; connection IDs never change its default.
        \think\facade\Db::setConfig(self::$config);
        \database('think', '', 1, self::class, self::$config, self::$support);
    }

    /** Resolve the selected native connection without changing the global model default. */
    public static function connection(array $config, string $type, int|string $id): \think\db\ConnectionInterface
    {
        return self::resolveConnection($config, $type, $id, false);
    }

    /** Replace one explicitly selected native connection for the legacy manager's connect(force: true). */
    public static function reconnect(array $config, string $type, int|string $id): \think\db\ConnectionInterface
    {
        return self::resolveConnection($config, $type, $id, true);
    }

    /** Share native identity rules between normal and explicitly forced connection resolution. */
    private static function resolveConnection(array $config, string $type, int|string $id, bool $force): \think\db\ConnectionInterface
    {
        if ((string)$id === '1') {
            return \think\facade\Db::connect($type, $force);
        }
        $settings = $config['connections'][$type];
        // Upstream hashes the full config as JSON; opaque PHP string IDs must remain valid JSON text.
        $settings['rcmaker_connection_id'] = 'id:' . bin2hex((string)$id);
        return \think\facade\Db::connect($settings, $force);
    }
}
