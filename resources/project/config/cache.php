<?php

return [
    'default_frame' => 'raw',
    'default' => rcEnv('cache.default', 'file'),
    'driver' => [
        'file' => [
            'type' => 'File',
            'path' => runtime_path() . '/cache/',
            'prefix' => 'RCCache_',
            'expire' => 0,
        ],
        'redis' => [
            'type' => 'redis',
            'host' => rcEnv('redis.host', '127.0.0.1'),
            'port' => (int) rcEnv('redis.port', 6379),
            'password' => rcEnv('redis.password', ''),
            'prefix' => 'RCCache_',
            'expire' => 0,
        ],
    ],
];
