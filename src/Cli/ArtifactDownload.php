<?php

declare(strict_types=1);

namespace RC\Cli;

/** Bounded HTTPS transfer shared by runtime artifacts and AI DevKit, without booting the application. */
final class ArtifactDownload
{
    public const MAX_BYTES = 268435456;
    public const TIMEOUT = 300;
    private const MAX_REDIRECTS = 5;
    private const MAX_HEADERS = 65536;

    /** Stage, validate and publish a download. Options: timeout, max-bytes and optional trusted sha256. */
    public static function run(string $url, string $targetPath, array $options = []): void
    {
        self::validateUrl($url);
        if (array_diff(array_keys($options), ['timeout', 'max-bytes', 'sha256'])) {
            throw new \InvalidArgumentException('Unknown artifact download option.');
        }
        $timeout = $options['timeout'] ?? self::TIMEOUT;
        $maximum = $options['max-bytes'] ?? self::MAX_BYTES;
        if (!is_int($timeout) || $timeout < 1 || $timeout > 3600 || !is_int($maximum) || $maximum < 1 || $maximum > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Download timeout/size must be bounded positive integers.');
        }
        $expected = self::digest($options['sha256'] ?? null);
        $targetPath = Filesystem::fileTarget($targetPath);
        $mode = is_file($targetPath) ? (fileperms($targetPath) & 0777) : 0600;
        [$temporary, $output] = Filesystem::temporaryFile($targetPath, 'rcmaker-download');
        $deadline = hrtime(true) / 1e9 + $timeout;
        try {
            for ($hop = 0; ; ++$hop) {
                $response = function_exists('curl_init')
                    ? self::curl($url, $output, $maximum, $deadline)
                    : self::stream($url, $output, $maximum, $deadline);
                if ($response['status'] >= 200 && $response['status'] < 300) { break; }
                if (!in_array($response['status'], [301, 302, 303, 307, 308], true) || $hop >= self::MAX_REDIRECTS) {
                    throw new \RuntimeException('HTTPS download failed or redirect limit exceeded (HTTP ' . $response['status'] . ').');
                }
                $locations = $response['headers']['location'] ?? [];
                if (count($locations) !== 1 || $locations[0] === '') { throw new \RuntimeException('HTTPS redirect requires exactly one Location.'); }
                self::validateReference($locations[0]);
                require_once dirname(__DIR__) . '/Helper/Curl/bootstrap.php';
                $url = (string) new \Curl\Url($url, $locations[0]);
                self::validateUrl($url);
            }
            if (!fflush($output)) { throw new \RuntimeException('Cannot flush downloaded artifact.'); }
            $size = fstat($output)['size'] ?? 0;
            fclose($output); $output = null;
            if ($size <= 0 || $size > $maximum) { throw new \RuntimeException('Downloaded artifact is empty or exceeds its size limit.'); }
            if ($expected !== null && !hash_equals($expected, hash_file('sha256', $temporary))) { throw new \RuntimeException('Downloaded artifact SHA256 does not match the trusted digest.'); }
            if (!@chmod($temporary, $mode)) { throw new \RuntimeException('Cannot set downloaded artifact permissions.'); }
            Filesystem::publishFile($temporary, $targetPath);
        } finally {
            if (is_resource($output)) { fclose($output); }
            if (is_file($temporary)) { @unlink($temporary); }
        }
    }

    /** Never allow URL credentials, controls, alternate slash forms or HTTPS downgrade on any hop. */
    public static function validateUrl(string $url): void
    {
        self::validateReference($url);
        $parts = parse_url($url);
        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('Artifact downloads require an absolute HTTPS URL without credentials or fragments.');
        }
    }

    /** Pins are optional trusted inputs, not hashes invented from the same untrusted download. */
    public static function digest(mixed $digest): ?string
    {
        if ($digest === null) { return null; }
        if (!is_string($digest) || !preg_match('/\A[0-9a-f]{64}\z/iD', $digest)) { throw new \InvalidArgumentException('Artifact SHA256 must contain 64 hexadecimal characters.'); }
        return strtolower($digest);
    }

    /** Reject parser ambiguities before the upstream RFC-3986 resolver sees a reference. */
    private static function validateReference(string $url): void
    {
        if ($url === '' || strlen($url) > 16384 || preg_match('/[\x00-\x20\x7f\\\\]/', $url) || parse_url($url) === false) { throw new \InvalidArgumentException('Invalid artifact URL or redirect reference.'); }
    }

    /** Use the remaining monotonic budget for every network operation, not a fresh timeout per redirect. */
    private static function remaining(float $deadline): float
    {
        $remaining = $deadline - hrtime(true) / 1e9;
        if ($remaining <= 0) { throw new \RuntimeException('Artifact download timed out.'); }
        return $remaining;
    }

    /** Parse response headers after the transport has handled HTTP framing; interim blocks reset state. */
    private static function header(array &$response, string $line): void
    {
        if (preg_match('/\AHTTP\/[0-9.]+\s+([0-9]{3})(?:\s|\z)/i', $line, $match)) {
            $response = ['status' => (int) $match[1], 'headers' => []];
        } elseif (($colon = strpos($line, ':')) !== false) {
            $name = strtolower(substr($line, 0, $colon));
            if (!preg_match('/\A[a-z0-9!#$%&\x27*+.^_`|~-]+\z/D', $name)) { throw new \RuntimeException('Invalid HTTPS response header.'); }
            $response['headers'][$name][] = trim(substr($line, $colon + 1));
        }
    }

    /** Bound known lengths too, and reject ambiguous transfer framing rather than accepting a truncated ZIP. */
    private static function contentLength(array $headers, int $maximum): ?int
    {
        $lengths = array_unique($headers['content-length'] ?? []);
        if (!$lengths) { return null; }
        if (count($lengths) !== 1 || isset($headers['transfer-encoding'])) { throw new \RuntimeException('Ambiguous HTTPS response length.'); }
        $value = reset($lengths);
        if (!preg_match('/\A[0-9]+\z/D', $value) || strlen(ltrim($value, '0')) > 10 || (int) $value > $maximum) { throw new \RuntimeException('HTTPS response exceeds its size limit or has invalid length.'); }
        return (int) $value;
    }

    /** cURL performs framing/TLS; redirects are explicit so both transports enforce the same URL policy. */
    private static function curl(string $url, $output, int $maximum, float $deadline): array
    {
        $curl = curl_init($url);
        if ($curl === false) { throw new \RuntimeException('Cannot initialize artifact cURL transfer.'); }
        $response = ['status' => 0, 'headers' => []]; $bytes = 0; $headerBytes = 0; $discarded = false;
        $milliseconds = max(1, (int) ceil(self::remaining($deadline) * 1000));
        // CONNECT belongs to the proxy, not the HTTPS origin response or its header budget.
        $options = [CURLOPT_FOLLOWLOCATION => false, CURLOPT_RETURNTRANSFER => false, CURLOPT_SUPPRESS_CONNECT_HEADERS => true,
            CURLOPT_CONNECTTIMEOUT_MS => min(15000, $milliseconds), CURLOPT_TIMEOUT_MS => $milliseconds,
            CURLOPT_LOW_SPEED_LIMIT => 1024, CURLOPT_LOW_SPEED_TIME => 30, CURLOPT_MAXFILESIZE_LARGE => $maximum,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_USERAGENT => 'rcmaker/framework-cli', CURLOPT_HTTPHEADER => ['Accept-Encoding: identity'],
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$response, &$headerBytes): int {
                $headerBytes += strlen($line);
                if ($headerBytes > self::MAX_HEADERS) { throw new \RuntimeException('HTTPS response headers exceed their limit.'); }
                self::header($response, rtrim($line, "\r\n")); return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response, &$bytes, &$discarded, $maximum, $deadline, $output): int {
                if ($response['status'] < 200 || $response['status'] >= 300) { $discarded = true; return 0; }
                self::remaining($deadline); self::contentLength($response['headers'], $maximum);
                $length = strlen($chunk);
                if ($length > $maximum - $bytes) { throw new \RuntimeException('HTTPS body exceeds its size limit.'); }
                Filesystem::writeToStream($output, $chunk); $bytes += $length; return $length;
            }];
        if (defined('CURLOPT_PROTOCOLS_STR')) { $options[CURLOPT_PROTOCOLS_STR] = 'https'; }
        else { $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS; }
        try {
            if (!curl_setopt_array($curl, $options)) { throw new \RuntimeException('Cannot configure secure artifact download.'); }
            $success = curl_exec($curl); $error = curl_errno($curl);
            if ($success !== true && !($discarded && $error === CURLE_WRITE_ERROR && $response['status'] >= 300 && $response['status'] < 400)) {
                $detail = curl_error($curl) ?: curl_strerror($error);
                $detail = preg_replace('~((?:[a-z][a-z0-9+.-]*://)|[\'\"])[^/\'\"]*@~i', '$1[redacted]@', $detail);
                $detail = substr(preg_replace('/[\x00-\x1f\x7f]/', ' ', $detail), 0, 1024);
                $connect = curl_getinfo($curl, CURLINFO_HTTP_CONNECTCODE);
                throw new \RuntimeException('HTTPS download failed (cURL ' . $error . ': ' . $detail . ', HTTP ' . $response['status']
                    . ($connect > 0 ? ', proxy CONNECT ' . $connect : '') . ').');
            }
            if ($response['status'] >= 200 && $response['status'] < 300) {
                $expected = self::contentLength($response['headers'], $maximum);
                if ($expected !== null && $bytes !== $expected) { throw new \RuntimeException('HTTPS response body is incomplete.'); }
            }
            return $response;
        } finally { unset($curl); }
    }

    /** Stream fallback keeps certificate verification and manually checks redirects, timeouts and exact length. */
    private static function stream(string $url, $output, int $maximum, float $deadline): array
    {
        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL)) { throw new \RuntimeException('Artifact download requires ext-curl or allow_url_fopen=1.'); }
        $context = stream_context_create(['http' => ['follow_location' => 0, 'ignore_errors' => true, 'timeout' => min(15, self::remaining($deadline)),
            'protocol_version' => 1.1, 'user_agent' => 'rcmaker/framework-cli', 'header' => "Connection: close\r\nAccept-Encoding: identity\r\n"],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false]]);
        $input = @fopen($url, 'rb', false, $context);
        if (!is_resource($input)) { throw new \RuntimeException('Cannot open verified HTTPS artifact connection.'); }
        try {
            $response = ['status' => 0, 'headers' => []]; $headerBytes = 0;
            foreach (stream_get_meta_data($input)['wrapper_data'] ?? [] as $line) {
                $headerBytes += strlen($line);
                if ($headerBytes > self::MAX_HEADERS) { throw new \RuntimeException('HTTPS response headers exceed their limit.'); }
                self::header($response, $line);
            }
            if ($response['status'] < 200 || $response['status'] >= 300) { return $response; }
            $expected = self::contentLength($response['headers'], $maximum); $bytes = 0;
            while (!feof($input)) {
                $remaining = self::remaining($deadline);
                $seconds = (int) $remaining; $micros = max(1, (int) (($remaining - $seconds) * 1000000));
                stream_set_timeout($input, $seconds, $micros);
                $chunk = @fread($input, 65536);
                if ($chunk === false || stream_get_meta_data($input)['timed_out']) { throw new \RuntimeException('HTTPS artifact read failed or timed out.'); }
                if ($chunk === '' && !feof($input)) { throw new \RuntimeException('HTTPS artifact read made no progress.'); }
                $length = strlen($chunk);
                if ($length > $maximum - $bytes) { throw new \RuntimeException('HTTPS body exceeds its size limit.'); }
                Filesystem::writeToStream($output, $chunk); $bytes += $length;
            }
            if ($expected !== null && $bytes !== $expected) { throw new \RuntimeException('HTTPS response body is incomplete.'); }
            return $response;
        } finally { fclose($input); }
    }
}
