<?php

namespace RC\Helper\Jwt;

require_once __DIR__ . '/bootstrap.php';

/**
 * Backward-compatible JWT API backed by the unmodified firebase/php-jwt library.
 * Use decodeForRequest() for Token verification with request-local clock settings.
 */
class JWT extends \Firebase\JWT\JWT
{
    public static $leeway = 0;
    public static $timestamp = null;

    /**
     * Verify with a concrete key, without carrying clock overrides between requests.
     * A concrete Key avoids network/cache callbacks while static settings are scoped.
     */
    public static function decodeForRequest(string $token, \Firebase\JWT\Key $key, int $leeway): \stdClass
    {
        $previousLeeway = static::$leeway;
        $previousTimestamp = static::$timestamp;
        try {
            static::$leeway = $leeway;
            static::$timestamp = null;
            return parent::decode($token, $key);
        } finally {
            static::$leeway = $previousLeeway;
            static::$timestamp = $previousTimestamp;
        }
    }
}
