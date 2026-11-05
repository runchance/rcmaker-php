<?php

// Composer sources take precedence; bundled upstream files remain unmodified and PHAR-local.
spl_autoload_register(static function (string $class): void {
    foreach (['Workerman\\Redis\\' => 'workerman-redis', 'Workerman\\RedisQueue\\' => 'workerman-redis-queue'] as $prefix => $package) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        if (str_contains($relative, '..')) {
            return;
        }
        $file = dirname(__DIR__, 3) . '/ThirdParty/' . $package . '/src/' . $relative . '.php';
        if (is_file($file)) {
            require_once $file;
        }
        return;
    }
});
