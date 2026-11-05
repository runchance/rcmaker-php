<?php

return [
    'default_frame' => rcEnv('db.default_frame', 'medoo'),
    'default' => rcEnv('db.default', 'sqlite'),
    'driver' => [
        'sqlite' => [
            // 持久数据库在程序外部；runtime 可清理，data 不可随意清理。
            'database' => rcEnv('sqlite.database', phar_path() . '/data/database/rcmaker.sqlite'),
            'prefix' => rcEnv('sqlite.prefix', ''),
            'options' => [],
        ],
        'mysql' => [
            'host' => rcEnv('mysql.host', '127.0.0.1'),
            'port' => rcEnv('mysql.port', 3306),
            'database' => rcEnv('mysql.database', ''),
            'username' => rcEnv('mysql.username', ''),
            'password' => rcEnv('mysql.password', ''),
            'charset' => rcEnv('mysql.charset', 'utf8mb4'),
            'prefix' => rcEnv('mysql.prefix', ''),
            'options' => [],
        ],
        'pgsql' => [
            'host' => rcEnv('pgsql.host', '127.0.0.1'),
            'port' => rcEnv('pgsql.port', 5432),
            'database' => rcEnv('pgsql.database', ''),
            'username' => rcEnv('pgsql.username', ''),
            'password' => rcEnv('pgsql.password', ''),
            'prefix' => rcEnv('pgsql.prefix', ''),
            'options' => [],
        ],
    ],
];
