<?php

// Composer keeps precedence. These fallbacks also support the historical standalone helper include.
spl_autoload_register(static function (string $class): void {
    foreach (['Overtrue\\Pinyin\\' => dirname(__DIR__, 2) . '/ThirdParty/overtrue-pinyin/src/',
        'RC\\Helper\\Pinyin\\' => __DIR__ . '/'] as $prefix => $root) {
        if (str_starts_with($class, $prefix)) {
            $path = $root . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($path)) { require_once $path; }
            return;
        }
    }
});
