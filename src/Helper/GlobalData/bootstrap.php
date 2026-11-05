<?php

/** Composer takes precedence; the appended fallback also works inside a PHAR. */
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'GlobalData\\')) {
        return;
    }
    $path = dirname(__DIR__, 2) . '/ThirdParty/workerman-globaldata/src/'
        . str_replace('\\', '/', substr($class, 11)) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});
