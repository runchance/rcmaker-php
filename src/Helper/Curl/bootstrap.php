<?php

declare(strict_types=1);

// Composer wins. Keep the bundled dependency lazy and in its original namespace.
if (class_exists(\Curl\Curl::class)) {
    // The scheduler bridge uses private upstream hooks; do not assume an unreviewed update is compatible.
    if (!defined('Curl\\Curl::VERSION') || \Curl\Curl::VERSION !== '13.0.1') {
        throw new \RuntimeException('RCMAKER requires stable php-curl-class/php-curl-class 13.0.1.');
    }
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Curl\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $name = substr($class, strlen($prefix));
        if (in_array($name, ['ArrayUtil', 'BaseCurl', 'CaseInsensitiveArray', 'CookieArray', 'Curl',
            'Decoder', 'Encoder', 'MultiCurl', 'StringUtil', 'TimeUtil', 'Url'], true)) {
            require_once dirname(__DIR__, 2) . '/ThirdParty/php-curl-class/src/Curl/' . $name . '.php';
        }
    });
}

// PHP object type checks do not autoload an unknown alias. Register these when Curl is first used,
// before native headers/cookies are handed to business callbacks using historical RC type hints.
(static function (): void {
    foreach (['ArrayUtil', 'BaseCurl', 'CaseInsensitiveArray', 'CookieArray', 'Decoder', 'Encoder', 'StringUtil', 'TimeUtil', 'Url'] as $name) {
        $legacy = 'RC\\Helper\\Curl\\' . $name;
        if (!class_exists($legacy, false)) { class_alias('Curl\\' . $name, $legacy); }
    }
})();
