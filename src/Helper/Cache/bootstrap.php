<?php

// Composer is tried first. Bundled sources are unmodified, PHAR-safe fallbacks.
spl_autoload_register(static function (string $class): void {
    $roots = [
        'Psr\\SimpleCache\\' => ['psr-simple-cache/src/'],
        'Psr\\Container\\' => ['psr-container/src/'],
        'think\\' => ['think-cache/src/', 'think-container/src/', 'think-helper/src/'],
    ];
    foreach ($roots as $prefix => $directories) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (str_contains($relative, '..')) {
            return;
        }
        foreach ($directories as $directory) {
            $file = dirname(__DIR__, 2) . '/ThirdParty/' . $directory . $relative;
            if (is_file($file)) {
                require_once $file;
                return;
            }
        }
    }
});

// Do not load think-cache's global helper: RC owns the global cache() contract.
if (!class_exists('RC\\Helper\\Cache\\think\\cache\\Driver', false)) {
    class_alias(\think\cache\Driver::class, 'RC\\Helper\\Cache\\think\\cache\\Driver');
}
foreach (['InvalidArgumentException', 'ClassNotFoundException', 'FuncNotFoundException'] as $exception) {
    $alias = 'RC\\Helper\\Cache\\think\\exception\\' . $exception;
    if (!class_exists($alias, false)) {
        class_alias('think\\exception\\' . $exception, $alias);
    }
}
unset($exception, $alias);
