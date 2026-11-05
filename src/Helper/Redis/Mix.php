<?php

namespace RC\Helper\Redis;

use RC\Bootstrap;
use RC\Config;

/** Register the legacy Mix API without opening sockets during framework bootstrap. */
class Mix implements Bootstrap
{
    public static $_version = '3.0.11';
    private static $_config = null;

    /** Preserve extended connection options rather than silently filtering ACL/TLS/prefix fields. */
    private static function creat_config($config)
    {
        if (!$config) { return false; }
        unset($config['default_frame']);
        $connections = [];
        foreach ($config as $name => $connection) {
            if (!is_array($connection)) { throw new \InvalidArgumentException('Redis connection configuration must be an array.'); }
            $connections[$name] = $connection + ['type' => 'common', 'host' => '127.0.0.1', 'port' => 6379,
                'password' => '', 'database' => 0, 'timeout' => 5, 'retryInterval' => null, 'readTimeout' => null,
                'persistent' => false, 'prefix' => ''];
        }
        return ['connections' => $connections];
    }

    /** Enable with config/bootstrap.php; application code should not initialize Redis itself. */
    public static function start()
    {
        static::$_config ??= static::creat_config(Config::get('redis') ?? []);
        if (!static::$_config) { return; }
        if (!extension_loaded('redis')) { throw new \RuntimeException('Mix Redis requires ext-redis; use redis("raw") for Predis.'); }
        \redis('mix', '', 1, mix\Redis::class, MixCluster::class, static::$_config);
    }
}
