<?php

return [
    // 需要时配置消费者与 Redis；默认不创建队列进程。
    'enable' => (bool) rcEnv('queue.enable', false),
    'connection' => [
        'default' => [
            'type' => 'redis',
            'host' => rcEnv('redis.host', '127.0.0.1'),
            'port' => (int) rcEnv('redis.port', 6379),
            'password' => rcEnv('redis.password', ''),
            'database' => (int) rcEnv('queue.redis_database', 0),
            'queue' => [
                'prefix' => rcEnv('queue.prefix', ''),
                'max_attempts' => (int) rcEnv('queue.max_attempts', 5),
                'retry_seconds' => (int) rcEnv('queue.retry_seconds', 5),
            ],
        ],
    ],
    'consumer_process' => [],
];
