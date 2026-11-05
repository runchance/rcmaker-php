<?php

declare(strict_types=1);

/** Prefer compatible Composer packages, otherwise load byte-identical Mix primitives from the bundle. */
spl_autoload_register(static function (string $class): void {
    foreach (['Mix\\Database\\' => 'mix-database', 'Mix\\ObjectPool\\' => 'mix-object-pool',
        'Mix\\Redis\\' => 'mix-redis'] as $prefix => $package) {
        if (str_starts_with($class, $prefix)) {
            $file = dirname(__DIR__, 3) . '/ThirdParty/' . $package . '/src/'
                . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) { require_once $file; }
            return;
        }
    }
});
