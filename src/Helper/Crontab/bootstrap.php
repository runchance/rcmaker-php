<?php

// Composer owns compatible external Cron classes; the pristine bundled source is a lazy fallback.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Cron\\')) {
        $path = dirname(__DIR__, 2) . '/ThirdParty/cron-expression/src/'
            . str_replace('\\', '/', $class) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});
