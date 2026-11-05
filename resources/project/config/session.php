<?php

return [
    'handler' => rcEnv('session.handler', RC\Http\FileSessionHandler::class),
    'config' => [
        'file' => ['save_path' => rcEnv('session.save_path', runtime_path() . '/sessions')],
        'redis' => [
            'host' => rcEnv('redis.host', '127.0.0.1'),
            'port' => (int) rcEnv('redis.port', 6379),
            'auth' => rcEnv('redis.password', ''),
            'database' => (int) rcEnv('session.redis_database', 0),
            'timeout' => (float) rcEnv('redis.timeout', 5),
            'prefix' => rcEnv('session.redis_prefix', 'RC:SESSION:'),
        ],
    ],
    'session_name' => rcEnv('session.session_name', 'PHPSID'),
];
