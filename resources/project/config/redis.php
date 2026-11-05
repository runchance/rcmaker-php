<?php

// 按需在 bootstrap 中启用 RC\Helper\Redis\Raw；默认启动不连接 Redis。
return [
    'default_frame' => rcEnv('redis.default_frame', 'raw'),
    'default' => [
        'type' => rcEnv('redis.type', 'common'),
        'host' => rcEnv('redis.host', '127.0.0.1'),
        'port' => (int) rcEnv('redis.port', 6379),
        'password' => rcEnv('redis.password', ''),
        'database' => (int) rcEnv('redis.database', 0),
        'timeout' => (float) rcEnv('redis.timeout', 5),
        'retryInterval' => (int) rcEnv('redis.retryInterval', 0),
        'readTimeout' => (float) rcEnv('redis.readTimeout', 5),
        'persistent' => (bool) rcEnv('redis.persistent', false),
        'select' => rcEnv('redis.select', 'x'),
        'prefix' => rcEnv('redis.prefix', ''),
    ],
];
