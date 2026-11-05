<?php

declare(strict_types=1);

namespace RC\Helper\Curl;

/** Internal option guard for Schannel configurations which cannot enforce a requested TLS 1.3 minimum. */
final class TlsOptions
{
    /** Called only for explicit TLS-version options on Windows; do not replace native TLS validation or negotiation. */
    public static function assertSupportedVersion(mixed $value): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return;
        }
        if (!is_scalar($value) && $value !== null) {
            throw new \InvalidArgumentException('TLS version options require integer-compatible scalar values.');
        }
        // PHP cURL also converts strings with a numeric prefix, such as "7suffix", to a native long.
        if (((int) $value & 0xffff) !== CURL_SSLVERSION_TLSv1_3) { return; }
        if (PHP_WINDOWS_VERSION_MAJOR > 10 || (PHP_WINDOWS_VERSION_MAJOR === 10 && PHP_WINDOWS_VERSION_BUILD >= 20348)) {
            return;
        }
        static $schannel;
        $schannel ??= str_contains(curl_version()['ssl_version'], 'Schannel');
        if ($schannel) {
            throw new \InvalidArgumentException('TLS 1.3 requires Windows 11 or Windows Server 2022 or newer with Schannel; the requested minimum cannot be enforced on this system.');
        }
    }
}
