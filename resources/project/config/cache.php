<?php

return [
    'default_frame' => rcEnv('cache.default_frame', 'raw'),
    'default' => rcEnv('cache.default', 'file'),
    'driver' => [
        'file' => [
            'type' => 'File',
            'path' => rcEnv('cache.file_path', runtime_path() . '/cache/'),
            'prefix' => rcEnv('cache.prefix', 'RCCache_'),
            'expire' => (int) rcEnv('cache.expire', 0),
        ],
        'redis' => [
            'type' => 'redis',
            'host' => rcEnv('redis.host', '127.0.0.1'),
            'port' => (int) rcEnv('redis.port', 6379),
            'password' => rcEnv('redis.password', ''),
            'select' => (int) rcEnv('cache.redis_database', 0),
            'prefix' => rcEnv('cache.prefix', 'RCCache_'),
            'expire' => (int) rcEnv('cache.expire', 0),
        ],
    ],
];
