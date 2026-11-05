<?php

// 按需在 bootstrap 中启用 RC\Helper\Redis\Raw；默认启动不连接 Redis。
return [
    'default_frame' => 'raw',
    'default' => [
        'host' => rcEnv('redis.host', '127.0.0.1'),
        'port' => (int) rcEnv('redis.port', 6379),
        'password' => rcEnv('redis.password', ''),
        'database' => (int) rcEnv('redis.database', 0),
        'timeout' => 5,
        'persistent' => false,
        'prefix' => rcEnv('redis.prefix', ''),
    ],
];
