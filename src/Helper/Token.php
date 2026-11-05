<?php

namespace RC\Helper;

use RC\Config;
use RC\Exception\AuthException;
use RC\Helper\Jwt\JWT;
use RC\Helper\Jwt\Key;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use UnexpectedValueException;

/**
 * Framework JWT authentication. Use token($request, $guard)->set(), get() or reSet().
 * Tokens are bound to their guard, issuer and access/refresh purpose.
 */
class Token
{
    private const ACCESS_TOKEN = 1;
    private const REFRESH_TOKEN = 2;
    private const KEY_FILE_CACHE_LIMIT = 128;

    private const DEFAULTS = [
        'signer' => 'HS256', 'type' => 'Bearer', 'keyName' => 'token',
        'access_secret_key' => null, 'refresh_secret_key' => null,
        'access_expired' => 7200, 'refresh_expired' => 604800,
        'refresh_disable' => false, 'iss' => 'rcmaker.runchance.com', 'leeway' => 60,
        'is_single_device' => false, 'cache_token_time' => 604800,
        'cache_token_prefix' => 'RC:AUTH:TOKEN:',
        'access_private_key' => null, 'access_public_key' => null,
        'refresh_private_key' => null, 'refresh_public_key' => null,
        'cookie_path' => '/', 'cookie_domain' => '', 'cookie_secure' => false,
        'cookie_http_only' => true,
    ];

    protected $request;
    protected array $config;
    protected $cache;
    protected string $guard;
    protected static ?array $msg = null;
    private array $keys = [];
    private static array $keyFiles = [];

    /** Load one configured guard; cache is needed only for single-device authentication. */
    public function __construct($request, $guard, $cache = null)
    {
        if (!is_string($guard) || $guard === '') {
            throw new AuthException('A configured token guard is required.');
        }
        $config = Config::get('token', $guard);
        if (!is_array($config)) {
            throw new AuthException('Token guard is not configured: ' . $guard);
        }
        $this->request = $request;
        $this->guard = $guard;
        $this->cache = $cache;
        $this->config = array_replace(self::DEFAULTS, $config);
        static::$msg ??= Config::get('token', 'msg') ?? [];
        $signer = $this->config['signer'];
        if (!is_string($signer)) {
            throw new AuthException('Token signer must be a supported JWT algorithm.');
        }
        $signer = strtoupper($signer);
        $this->config['signer'] = $signer === 'EDDSA' ? 'EdDSA' : $signer;
        if (!isset(JWT::$supported_algs[$this->config['signer']])) {
            throw new AuthException('Unsupported token signer: ' . $signer);
        }
        if (!is_string($this->config['type']) || !in_array(strtolower($this->config['type']),
            ['bearer', 'header', 'get', 'post', 'cookie', 'session'], true)) {
            throw new AuthException('Unsupported token transport.');
        }
        foreach (['iss', 'keyName', 'cache_token_prefix'] as $field) {
            if (!is_string($this->config[$field]) || $this->config[$field] === '') {
                throw new AuthException('Token configuration requires a non-empty ' . $field . '.');
            }
        }
        foreach (['access_expired', 'refresh_expired', 'cache_token_time', 'leeway'] as $field) {
            $this->config[$field] = self::duration($this->config[$field], $field, $field === 'leeway');
        }
    }

    /** Issue tokens for an account: set(['key' => $userId, ...]). */
    public function set(array $data): array
    {
        return $this->setToken($data);
    }

    /** Sign both tokens before publishing a new single-device login session. */
    public function setToken(array $data): array
    {
        self::validateAccount($data);
        return $this->issue($data, bin2hex(random_bytes(32)), true);
    }

    /** Refresh using the configured request transport. */
    public function reSet(): array
    {
        return $this->refreshToken();
    }

    /** Backward-compatible alias for refreshToken(). */
    public function reSetToken(): array
    {
        return $this->refreshToken();
    }

    /**
     * Renew a valid session without overwriting the latest-login cache record.
     * This prevents a concurrent refresh from resurrecting a superseded login.
     */
    public function refreshToken(): array
    {
        $claims = $this->verify(self::REFRESH_TOKEN);
        return $this->issue($claims['data'], $claims['rc_session'], false);
    }

    /** Return all account data or a scalar/nested field: get('user_id'). */
    public function get($key = null): mixed
    {
        return $this->getData($key);
    }

    /** Read verified account data; missing fields return null. */
    public function getData($key = null): mixed
    {
        $data = $this->verify()['data'];
        return $key === null ? $data : ($data[$key] ?? null);
    }

    /** Read verified claims, such as getToken('exp'). */
    public function getToken($key = null): mixed
    {
        $claims = $this->verify();
        return $key === null ? $claims : ($claims[$key] ?? null);
    }

    /** Verify access (1) or refresh (2), optionally with an explicit encoded token. */
    public function verify(int $tokenType = self::ACCESS_TOKEN, ?string $token = null): array
    {
        try {
            $this->validateTokenType($tokenType);
            $token ??= $this->getClientToken($tokenType);
            $decoded = JWT::decodeForRequest($token, new Key(
                $this->signingKey($tokenType, false), $this->config['signer']
            ), $this->config['leeway']);
            $claims = self::toArray($decoded);
            $this->validateClaims($claims, $tokenType);
            if ($this->config['is_single_device']) {
                $session = $this->sessionCache()->get($this->sessionKey($claims['data']['key']));
                if (!is_string($session) || !hash_equals($session, $claims['rc_session'])) {
                    throw new AuthException(static::$msg['signed_in_on_another_device'] ?? 'Signed in on another device or session expired.');
                }
            }
            return $claims;
        } catch (AuthException $error) {
            throw $error;
        } catch (SignatureInvalidException $error) {
            throw new AuthException(static::$msg[$tokenType === 2 ? 'refresh_token_valid' : 'signature_verification_failed']
                ?? 'Signature verification failed.', 0, $error);
        } catch (BeforeValidException $error) {
            throw new AuthException(static::$msg[$tokenType === 2 ? 'refresh_token_invalid_yet' : 'signature_verification_before_invalid']
                ?? 'Token is not yet valid.', 0, $error);
        } catch (ExpiredException $error) {
            throw new AuthException(static::$msg[$tokenType === 2 ? 'refresh_token_expired' : 'access_expired']
                ?? 'Token expired.', 0, $error);
        } catch (UnexpectedValueException $error) {
            throw new AuthException(static::$msg[$tokenType === 2 ? 'refresh_token_format_error' : 'token_format_error']
                ?? 'Invalid token.', 0, $error);
        } catch (\Throwable $error) {
            throw new AuthException('Token verification failed; check the configured key and cache.', 0, $error);
        }
    }

    /** Build a new access/refresh pair; refresh retains the authenticated login session. */
    private function issue(array $data, string $session, bool $newLogin): array
    {
        $accessTtl = self::duration($data['access_expired'] ?? $this->config['access_expired'], 'access_expired');
        $refreshTtl = self::duration($data['refresh_expired'] ?? $this->config['refresh_expired'], 'refresh_expired');
        $now = time();
        $base = [
            'iss' => $this->config['iss'], 'iat' => $now, 'rc_guard' => $this->guard,
            'rc_session' => $session, 'data' => $data,
        ];
        try {
            $result = [
                'guard' => $this->guard, 'token_type' => $this->config['type'], 'expires_in' => $accessTtl,
                'access_token' => JWT::encode($base + ['exp' => $now + $accessTtl, 'rc_type' => 'access'],
                    $this->signingKey(self::ACCESS_TOKEN, true), $this->config['signer']),
            ];
            if (!$this->config['refresh_disable']) {
                $result['refresh_token'] = JWT::encode($base + ['exp' => $now + $refreshTtl, 'rc_type' => 'refresh'],
                    $this->signingKey(self::REFRESH_TOKEN, true), $this->config['signer']);
            }
            if ($newLogin && $this->config['is_single_device']) {
                if (!$this->sessionCache()->set($this->sessionKey($data['key']), $session, $this->config['cache_token_time'])) {
                    throw new AuthException('Unable to persist the token login session.');
                }
            }
            $this->setClient($result['access_token'], self::ACCESS_TOKEN);
            if (isset($result['refresh_token'])) {
                $this->setClient($result['refresh_token'], self::REFRESH_TOKEN);
            }
            return $result;
        } catch (AuthException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new AuthException('Token creation failed; check the configured key and cache.', 0, $error);
        }
    }

    /** Persist tokens only for Cookie/Session transports; others return tokens to the caller. */
    private function setClient(string $token, int $tokenType): void
    {
        $key = $this->clientKey($tokenType);
        switch (strtolower($this->config['type'])) {
            case 'cookie':
                $this->request->setcookies([$key => $token], 0, $this->config['cookie_path'],
                    $this->config['cookie_domain'], (bool)$this->config['cookie_secure'],
                    (bool)$this->config['cookie_http_only']);
                break;
            case 'session':
                $this->request->session()->set($key, $token);
                break;
        }
    }

    /** Read the configured transport and reject non-string untrusted input before decoding. */
    private function getClientToken(int $tokenType): string
    {
        $key = $this->clientKey($tokenType);
        $type = strtolower($this->config['type']);
        if ($type === 'bearer') {
            $header = $this->request->header('authorization');
            if (!is_string($header) || !preg_match('/^Bearer[ \t]+([^ \t\r\n]+)$/iD', trim($header), $match)) {
                throw new AuthException(static::$msg['request_without_info'] ?? 'A Bearer authorization header is required.');
            }
            return $match[1];
        }
        $token = $type === 'session'
            ? $this->request->session()->get($key, null)
            : $this->request->$type($key, null);
        if (!is_string($token) || $token === '' || $token === 'undefined') {
            throw new AuthException(static::$msg['illegal_info'] ?? 'A token string is required.');
        }
        return $token;
    }

    /** Cookie and Session transports store refresh tokens separately. */
    private function clientKey(int $tokenType): string
    {
        return $this->config['keyName'] . ($tokenType === self::REFRESH_TOKEN
            && in_array(strtolower($this->config['type']), ['cookie', 'session'], true) ? '_refresh' : '');
    }

    /** Reject unknown token kinds and enforce refresh_disable for both verification and renewal. */
    private function validateTokenType(int $tokenType): void
    {
        if (!in_array($tokenType, [self::ACCESS_TOKEN, self::REFRESH_TOKEN], true)) {
            throw new AuthException('Token type must be 1 (access) or 2 (refresh).');
        }
        if ($tokenType === self::REFRESH_TOKEN && $this->config['refresh_disable']) {
            throw new AuthException('Refresh tokens are disabled for this guard.');
        }
    }

    /** Validate framework claims after cryptographic verification; legacy unbound tokens fail closed. */
    private function validateClaims(array $claims, int $tokenType): void
    {
        if (($claims['iss'] ?? null) !== $this->config['iss']
            || ($claims['rc_guard'] ?? null) !== $this->guard
            || ($claims['rc_type'] ?? null) !== ($tokenType === self::ACCESS_TOKEN ? 'access' : 'refresh')
            || !is_int($claims['iat'] ?? null) || !is_int($claims['exp'] ?? null)
            || $claims['exp'] <= $claims['iat']
            || $claims['iat'] > time() + $this->config['leeway']
            || !is_string($claims['rc_session'] ?? null) || strlen($claims['rc_session']) !== 64
            || !is_array($claims['data'] ?? null)) {
            throw new AuthException(static::$msg['token_format_error'] ?? 'Invalid token claims; sign in again.');
        }
        self::validateAccount($claims['data']);
    }

    /** Accept integer/string account IDs, including zero, without array/object coercion. */
    private static function validateAccount(array $data): void
    {
        $key = $data['key'] ?? null;
        if ((!is_string($key) && !is_int($key)) || $key === '') {
            throw new AuthException(static::$msg['key_not_exist'] ?? 'Token data requires a non-empty string or integer key.');
        }
    }

    /** Resolve and validate HMAC secrets or a configured asymmetric key file. */
    private function signingKey(int $tokenType, bool $private): string
    {
        $signer = $this->config['signer'];
        $prefix = $tokenType === self::ACCESS_TOKEN ? 'access_' : 'refresh_';
        $hmac = str_starts_with($signer, 'HS');
        $name = $prefix . ($hmac ? 'secret_key' : ($private ? 'private_key' : 'public_key'));
        if (isset($this->keys[$name])) {
            return $this->keys[$name];
        }
        $value = $this->config[$name];
        if (!is_string($value) || $value === '') {
            throw new AuthException('Configure token.' . $this->guard . '.' . $name . ' before using authentication.');
        }
        if ($hmac) {
            $minimum = match ($signer) { 'HS256' => 32, 'HS384' => 48, 'HS512' => 64 };
            if (strlen($value) < $minimum) {
                throw new AuthException($name . ' must contain at least ' . $minimum . ' bytes of randomly generated secret material.');
            }
            return $this->keys[$name] = $value;
        }
        $fileKey = self::keyFileIdentity($value);
        if ($fileKey !== null && isset(self::$keyFiles[$fileKey])) {
            return $this->keys[$name] = self::$keyFiles[$fileKey];
        }
        if (!is_file($value) || !is_readable($value)) {
            throw new AuthException('The configured ' . $name . ' file is not readable.');
        }
        $contents = file_get_contents($value);
        if ($contents === false || trim($contents) === '') {
            throw new AuthException('The configured ' . $name . ' file is empty.');
        }
        if ($fileKey !== null) {
            if (count(self::$keyFiles) >= self::KEY_FILE_CACHE_LIMIT) {
                unset(self::$keyFiles[array_key_first(self::$keyFiles)]);
            }
            self::$keyFiles[$fileKey] = $contents;
        }
        return $this->keys[$name] = $contents;
    }

    /** Keep relative file/PHAR keys local to their directory without probing absolute cache hits. */
    private static function keyFileIdentity(string $value): ?string
    {
        $path = $value;
        if (str_contains($path, '://')) {
            $scheme = strstr($path, '://', true);
            $path = in_array(strtolower($scheme), ['phar', 'file'], true) ? substr($path, strlen($scheme) + 3) : '';
        }
        if (DIRECTORY_SEPARATOR === '\\') {
            if (preg_match('~\A(?:[a-zA-Z]:[\\\\/]|[\\\\/]{2})~D', $path)) { return $value; }
            // Drive-relative paths also depend on Windows' hidden per-drive directory state.
            if (preg_match('~\A[a-zA-Z]:~D', $path)) { return null; }
        } elseif (str_starts_with($path, '/')) { return $value; }
        $directory = getcwd();
        if ($directory === false) { throw new AuthException('The working directory for a relative token key is unavailable.'); }
        return $directory . "\0" . $value;
    }

    /** Keep account/guard cache keys unambiguous even when IDs contain separators. */
    private function sessionKey(int|string $account): string
    {
        return $this->config['cache_token_prefix'] . 'v2:' . hash('sha256',
            strlen($this->guard) . ':' . $this->guard . ':' . (string)$account);
    }

    /** Fail closed if single-device mode has no working framework-compatible cache. */
    private function sessionCache(): object
    {
        if (!is_object($this->cache)) {
            throw new AuthException('Single-device authentication requires a shared cache.');
        }
        return $this->cache;
    }

    /** Accept integer environment values without accepting floats, booleans or negative lifetimes. */
    private static function duration(mixed $value, string $field, bool $zeroAllowed = false): int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT);
        if ((!is_int($value) && !is_string($value)) || $number === false
            || $number < ($zeroAllowed ? 0 : 1) || $number > PHP_INT_MAX - time()) {
            throw new AuthException($field . ' must be a valid integer duration in seconds.');
        }
        return $number;
    }

    /** Convert verified JSON objects without encoding and parsing the same payload twice. */
    private static function toArray(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $value = (array)$value;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::toArray($item);
            }
        }
        return $value;
    }

    /**
     * Legacy tagged-cache helper, retained for callers storing their own records.
     * It no longer authenticates a device; use set()/refreshToken() for login tokens.
     */
    public function cacheToken(array $arr): void
    {
        $cache = $this->sessionCache();
        $key = $arr['cache_token_prefix'] . $arr['key'];
        $cache->tag($key)->clear();
        if (!$cache->tag($key)->set($key . ':' . $arr['ip'], $arr['data'], $arr['cache_token_time'])) {
            throw new AuthException('Unable to persist the legacy token cache record.');
        }
    }
}
