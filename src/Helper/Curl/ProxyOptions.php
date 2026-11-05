<?php

declare(strict_types=1);

namespace RC\Helper\Curl;

/** Internal setOpt() checks for native SOCKS capabilities, not an authentication implementation. */
final class ProxyOptions
{
    private const AUTH_ONLY_VERSION = 0x081700;
    private static ?bool $supportsAuthOnly = null;

    /** Reject invalid masks and unavailable strict authentication before the single/group option store changes. */
    public static function assertAuthentication(mixed $value): void
    {
        if ($value !== null && !is_scalar($value)) {
            throw new \InvalidArgumentException('SOCKS5 authentication must be an integer-compatible scalar or null.');
        }
        $mask = (int) $value;
        $methods = CURLAUTH_BASIC | CURLAUTH_GSSAPI;
        if (($mask & ~($methods | CURLAUTH_ONLY)) !== 0 || ($mask & CURLAUTH_ONLY) !== 0 && ($mask & $methods) === 0) {
            throw new \InvalidArgumentException('Invalid SOCKS5 authentication mask; CURLAUTH_ONLY requires BASIC or GSSAPI.');
        }
        if (($mask & CURLAUTH_ONLY) === 0) { return; }
        self::$supportsAuthOnly ??= \curl_version()['version_number'] >= self::AUTH_ONLY_VERSION;
        if (!self::$supportsAuthOnly) {
            throw new \InvalidArgumentException('SOCKS5 strict authentication requires libcurl 8.23.0 or later; the requested policy cannot be enforced.');
        }
    }
}
