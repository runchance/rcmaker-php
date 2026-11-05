<?php

// Composer takes precedence; the bundled stable source is the zero-install fallback.
if (!class_exists(\Firebase\JWT\JWT::class)) {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Firebase\\JWT\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $name = substr($class, strlen($prefix));
        if (in_array($name, [
            'JWT', 'Key', 'JWK', 'CachedKeySet', 'BeforeValidException',
            'ExpiredException', 'SignatureInvalidException', 'JWTExceptionWithPayloadInterface',
        ], true)) {
            require_once dirname(__DIR__, 2) . '/ThirdParty/php-jwt/src/' . $name . '.php';
        }
    });
}

// Load aliases before exceptions are thrown: catch clauses do not autoload aliases.
foreach (['Key', 'BeforeValidException', 'ExpiredException', 'SignatureInvalidException'] as $exception) {
    $alias = 'RC\\Helper\\Jwt\\' . $exception;
    if (!class_exists($alias, false)) {
        class_alias('Firebase\\JWT\\' . $exception, $alias);
    }
}
unset($exception, $alias);
