<?php

return [
    // 需要时配置消费者与 Redis；默认不创建队列进程。
    'enable' => false,
    'connection' => [
        'default' => [
            'type' => 'redis',
            'host' => rcEnv('redis.host', '127.0.0.1'),
            'port' => (int) rcEnv('redis.port', 6379),
            'queue' => ['prefix' => '', 'max_attempts' => 5, 'retry_seconds' => 5],
        ],
    ],
    'consumer_process' => [],
];
