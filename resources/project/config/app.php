<?php

return [
    'start_app' => (bool) rcEnv('app.start_app', true),
    'cli_frame' => 'workerman',
    'cli_log' => (bool) rcEnv('app.cli_log', false),
    // Banner 由开发者设定，不交给部署者的 .env 修改。
    'cli_banner' => true,
    'default_timezone' => rcEnv('app.default_timezone', 'Asia/Shanghai'),
    'count' => (bool) rcEnv('app.count', false),
    'debug' => (bool) rcEnv('app.debug', false),
    'error_types' => E_ALL & ~E_NOTICE & ~E_DEPRECATED,
    'error_msg' => rcEnv('app.error_msg', 'Server error'),
    'route' => (bool) rcEnv('app.route', true),
    'with_custom_route' => (bool) rcEnv('app.with_custom_route', false),
    'index' => rcEnv('app.index', ['index', 'index']),
    'default_app' => rcEnv('app.default_app', 'index'),
    'route_cache_limit' => rcEnv('app.route_cache_limit', 1024),
    'app_name' => 'app',
    'public_path' => rcEnv('app.public_path', null),
    'runtime_path' => rcEnv('app.runtime_path', null),
    'ssl_path' => rcEnv('app.ssl_path', null),
    'apps_path' => rcEnv('app.apps_path', null),
    'view_path' => rcEnv('app.view_path', null),
    'trusted_proxies' => rcEnv('app.trusted_proxies', []),
    'trusted_proxy_header' => rcEnv('app.trusted_proxy_header', 'x-forwarded-for'),
    // 多应用及 bind_process 示例见在线“应用进程组”手册。
    'app' => [],
];
