<?php

return [
    'listen' => rcEnv('workerman.listen', 'http://127.0.0.1:8680'),
    'name' => rcEnv('workerman.name', 'RC_WORKER'),
    'count' => max(1, (int) rcEnv('workerman.count', 1)),
    'windows_count' => max(1, (int) rcEnv('workerman.windows_count', 1)),
    'transport' => 'tcp',
    'context' => [],
    'reusePort' => true,
    'user' => rcEnv('workerman.user', ''),
    'group' => rcEnv('workerman.group', ''),
    'document_root' => public_path(),
    'enable_static_file' => true,
    'enable_static_php' => false,
    'enable_static_preload' => true,
    'max_request' => max(1, (int) rcEnv('workerman.max_request', 1000000)),
    'max_package_size' => 10 * 1024 * 1024,
    'pid_file' => runtime_path() . '/RC_Workerman.pid',
    'log_file' => runtime_path() . '/RC_Workerman_Log.log',
    'status_file' => runtime_path() . '/RC_Workerman_Status.log',
    'stdout_file' => runtime_path() . '/logs/RC_Workerman_Stdout.log',
    'logger_listen' => rcEnv('workerman.logger_listen', 'text://127.0.0.1:8689'),
];
