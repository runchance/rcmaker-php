<?php
/**
 * Original facade derived from webman, copyright walkor <walkor@workerman.net>.
 * Licensed under the MIT License; see LICENSE in this directory.
 */

namespace RC\Helper\Queue\RedisQueue;

use RC\Config;

/** Legacy synchronous producer. Prefer queue(); no dynamic methods are attached to ext-redis objects. */
class Redis
{
    /** Keep queueRedis.php compatibility; absent legacy configuration falls back to queue.php. */
    public static function connection($name = 'default')
    {
        if (!is_string($name) || $name === '') {
            throw new \InvalidArgumentException('Queue connection name must be a nonempty string.');
        }
        $legacy = Config::get('queueRedis', 'connection');
        if (!is_array($legacy) || !isset($legacy[$name])) {
            return Client::connection($name, null);
        }
        return Client::configured($name, $legacy[$name], null, 'legacy');
    }

    /** Forward legacy Redis::send() to the same validated queue envelope and isolated producer. */
    public static function __callStatic($name, $arguments)
    {
        return static::connection()->{$name}(...$arguments);
    }
}
