<?php

namespace RC\Helper\Queue\RedisQueue;

use RC\Config;

/** Public queue facade; caches are separated by process, timer runtime and producer/consumer mode. */
class Client
{
    protected static $_connections = [];
    private static ?int $pid = null;

    /** Resolve the documented config/queue.php connection; creation itself opens no Redis socket. */
    public static function connection($name = 'default', $type = 'workerman')
    {
        $connections = Config::get('queue', 'connection');
        if (!is_string($name) || !is_array($connections) || !isset($connections[$name]) || !is_array($connections[$name])) {
            throw new \RuntimeException('RedisQueue connection not found: ' . (is_string($name) ? $name : '(invalid name)'));
        }
        return static::configured($name, $connections[$name], $type);
    }

    /** Internal bridge for the legacy queueRedis configuration; never alias unrelated Redis helper clients. */
    public static function configured(string $name, array $config, ?string $type = 'workerman', string $scope = 'queue'): RedisClient
    {
        $pid = getmypid();
        if (static::$pid !== $pid) {
            static::closeAll();
            static::$pid = $pid;
        }
        $async = $type === 'workerman' && class_exists(\Workerman\Worker::class) && \Workerman\Worker::$globalEvent !== null;
        $key = $scope . ':' . $name . ':' . ($type ?? 'producer') . ':' . (int) $async;
        if (!isset(static::$_connections[$key]) || static::$_connections[$key]->isClosed()) {
            $options = $config['queue'] ?? [];
            if (!is_array($options)) {
                throw new \InvalidArgumentException('Queue options must be an array.');
            }
            unset($config['queue']);
            static::$_connections[$key] = new RedisClient($config, $options, $type);
        }
        return static::$_connections[$key];
    }

    /** Stop all queue clients owned by this process; does not modify global Redis or foreign timers. */
    public static function closeAll(): void
    {
        $clients = static::$_connections;
        static::$_connections = [];
        foreach ($clients as $client) {
            $client->close();
        }
    }

    /** Preserve Client::send()/subscribe() shorthand for the default connection. */
    public static function __callStatic($name, $arguments)
    {
        return static::connection()->{$name}(...$arguments);
    }
}
