<?php

declare(strict_types=1);

// Composer has precedence. Register only immutable fallbacks; do not load any template engine on JSON requests.
spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'Smarty\\' => 'smarty/src/',
        'think\\template\\' => 'think-template/src/template/',
        'Symfony\\Polyfill\\Mbstring\\' => 'symfony-polyfill-mbstring/',
        'Psr\\SimpleCache\\' => 'psr-simple-cache/src/',
    ];
    if ($class === 'think\\Template') {
        require_once dirname(__DIR__, 2) . '/ThirdParty/think-template/src/Template.php';
        return;
    }
    foreach ($prefixes as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $relative = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (str_contains($relative, '..')) { return; }
            $file = dirname(__DIR__, 2) . '/ThirdParty/' . $directory . $relative;
            if (is_file($file)) { require_once $file; }
            return;
        }
    }
});

if (!function_exists('mb_strlen')) {
    require_once dirname(__DIR__, 2) . '/ThirdParty/symfony-polyfill-mbstring/bootstrap.php';
}
