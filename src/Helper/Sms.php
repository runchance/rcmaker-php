<?php

declare(strict_types=1);

namespace RC\Helper;

/** Generate/store/check short-lived codes with sms($request)->scene('login')->mobile($mobile). This is not an SMS sender. */
class Sms
{
    protected static $methods = ['post', 'get'];
    protected static $msg = [
        'method_error' => '参数传递方法不被支持', 'no_mobile' => '未传入手机号', 'no_code' => '未传入验证码',
        'not_support_type' => '不支持的验证码类型', 'invalid_code' => '验证码不正确', 'expired_code' => '验证码过期',
        'attempt_limit' => '尝试次数过多，请重新获取验证码',
    ];
    protected $cache = null;
    protected $config = [];
    protected $cachePrefix = 'rcsms';
    protected $character = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    protected $expire = 180;
    protected $length = 6;
    protected $type = 1;
    protected $code = '';
    protected $scene = '';
    protected $error = '';
    protected $mobileKey = 'mobile';
    protected $codeKey = 'code';
    protected $method = 'get';
    protected $ipCheck = true;
    protected $autoDelte = true;
    protected $autoDelete = true;
    protected $maxAttempts = 5;
    protected $_mobile;
    protected $_code;
    protected $request = null;
    private ?array $issued = null;

    /** Configure documented options only; caller-supplied options cannot replace the request or cache object. */
    public function __construct($request, array $config = [], $method = 'get', $cache = null)
    {
        if (!is_object($request) || ($cache !== null && !is_object($cache))) {
            throw new \InvalidArgumentException('SMS requires a request object and an optional cache object.');
        }
        $this->request = $request;
        $this->cache = $cache;
        $this->config = $config;
        $this->method = $method;
        // Literal class defaults are already valid; subclasses and every custom option still take the validation path.
        if ($config === [] && get_class($this) === self::class && ($method === 'get' || $method === 'post')) {
            return;
        }
        $allowed = ['cachePrefix', 'character', 'expire', 'length', 'type', 'scene', 'mobileKey', 'codeKey',
            'method', 'ipCheck', 'autoDelte', 'autoDelete', 'maxAttempts'];
        foreach ($config as $key => $value) {
            if (!in_array($key, $allowed, true)) { throw new \InvalidArgumentException('Unknown SMS option: ' . (string) $key); }
            $this->{$key} = $value;
        }
        foreach (['expire' => [1, 86400], 'length' => [1, 64], 'type' => [1, 7], 'maxAttempts' => [1, 100]] as $key => [$min, $max]) {
            $this->{$key} = self::integer($this->{$key}, $min, $max, $key);
        }
        foreach (['ipCheck', 'autoDelte', 'autoDelete'] as $key) {
            if (!is_bool($this->{$key})) { throw new \InvalidArgumentException('SMS ' . $key . ' must be boolean.'); }
        }
        $this->autoDelte = $this->autoDelete = $config['autoDelete'] ?? $config['autoDelte'] ?? ($this->autoDelete && $this->autoDelte);
        foreach (['cachePrefix', 'scene', 'mobileKey', 'codeKey'] as $key) {
            self::identifier($this->{$key}, $key, $key === 'scene');
        }
        if (!is_string($this->character) || strlen($this->character) < 62 || strlen($this->character) > 4096
            || preg_match('/[^\x21-\x7e]/', $this->character)) {
            throw new \InvalidArgumentException('SMS character pool must contain 62–4096 printable ASCII bytes.');
        }
        if (!is_string($this->method) || !in_array($this->method, static::$methods, true)) {
            throw new \InvalidArgumentException(static::$msg['method_error']);
        }
    }

    /** Select a separate business scene; usage: ->scene('login'). */
    public function scene(string $scene)
    {
        self::identifier($scene, 'scene', true);
        $this->scene = $scene;
        return $this;
    }

    /** Override request input with a caller-validated destination; this component is not a phone-number validator. */
    public function mobile(string $mobile)
    {
        self::identifier($mobile, 'mobile');
        $this->_mobile = $mobile;
        return $this;
    }

    /** Supply the received code as a string, preserving leading zeroes; usage: ->code($input)->check(). */
    public function code(string $code)
    {
        if (strlen($code) > 256) { throw new \InvalidArgumentException('SMS code is too long.'); }
        $this->_code = $code;
        return $this;
    }

    /** Change the next issue's lifetime in seconds; zero must not create a permanent authentication code. */
    public function exp(int $second)
    {
        $this->expire = self::integer($second, 1, 86400, 'expire');
        return $this;
    }

    /** Generate with random_int(), persist a versioned digest record, then return the code to the server-side sender. */
    public function create(): string
    {
        $this->error = '';
        $this->code = '';
        $this->issued = null;
        $mobile = $this->destination();
        $key = $this->key($mobile);
        $binding = $this->binding();
        [$first, $last] = match ($this->type) { 1 => [0, 9], 2 => [10, 35], 3 => [36, 61],
            4, 5 => [0, 35], 6 => [10, 61], 7 => [0, 61] };
        $pool = $this->type === 5 ? strtoupper($this->character) : $this->character;
        $code = '';
        for ($i = 0; $i < $this->length; ++$i) { $code .= $pool[random_int($first, $last)]; }
        $nonce = bin2hex(random_bytes(16));
        $record = ['v' => 2, 'nonce' => $nonce, 'digest' => $this->digest($key, $nonce, $code, $binding),
            'ip' => $binding, 'expires_at' => time() + $this->expire, 'attempts' => 0, 'limit' => $this->maxAttempts];
        if ($this->storage()->set($key, self::encode($record), $this->expire) !== true) {
            throw new \RuntimeException('Unable to persist the SMS verification code.');
        }
        $this->issued = [$key, $nonce];
        return $this->code = $code;
    }

    /** Cancel only this object's last issued challenge after provider failure; never remove a newer issuer's code. */
    public function revoke(): bool
    {
        if ($this->issued === null) { return false; }
        [$key, $nonce] = $this->issued;
        $storage = $this->storage();
        for ($retry = 0; $retry < 16; ++$retry) {
            $raw = $storage->get($key);
            $record = self::decode($raw);
            if ($record === null || $record['nonce'] !== $nonce || $record['expires_at'] <= time()) { return false; }
            $result = $storage->compareAndSwap($key, $raw, null);
            if (!is_bool($result)) { throw new \RuntimeException('SMS cache conditional updates must return a confirmed boolean result.'); }
            if ($result) { $this->issued = null; return true; }
        }
        throw new \RuntimeException('SMS revocation could not complete a bounded conditional cache update.');
    }

    /** Last business-validation failure; a new check resets stale errors, storage/configuration failures throw. */
    public function getErrorMsg(): string { return $this->error; }

    /** Verify once by default; failed guesses update the same record atomically without renewing its TTL. */
    public function check(): bool
    {
        $this->error = '';
        $mobile = $this->destination();
        $code = $this->_code ?? $this->request->{$this->method}($this->codeKey);
        if ($code === null || $code === '') { throw new \InvalidArgumentException(static::$msg['no_code']); }
        if ((!is_string($code) && !is_int($code)) || strlen((string) $code) > 256) { return $this->fail('invalid_code'); }
        $code = (string) $code;
        $key = $this->key($mobile);
        $binding = $this->binding();
        $storage = $this->storage();
        $nonce = null;
        for ($retry = 0; $retry < 16; ++$retry) {
            $raw = $storage->get($key);
            $record = self::decode($raw);
            if ($record === null || $record['expires_at'] <= time()) { return $this->fail('expired_code'); }
            if ($nonce !== null && $nonce !== $record['nonce']) { return $this->fail('invalid_code'); }
            $nonce = $record['nonce'];
            if ($record['ip'] !== $binding) { return $this->fail('invalid_code'); }
            if ($record['attempts'] >= min($record['limit'], $this->maxAttempts)) { return $this->fail('attempt_limit'); }
            $valid = hash_equals($record['digest'], $this->digest($key, $nonce, $code, $binding));
            if ($valid && !$this->autoDelte) { return true; }
            $replacement = null;
            if (!$valid && ++$record['attempts'] < min($record['limit'], $this->maxAttempts)) { $replacement = self::encode($record); }
            $result = $storage->compareAndSwap($key, $raw, $replacement);
            if (!is_bool($result)) { throw new \RuntimeException('SMS cache conditional updates must return a confirmed boolean result.'); }
            if ($result) {
                if ($valid) { return true; }
                return $this->fail($replacement === null ? 'attempt_limit' : 'invalid_code');
            }
        }
        throw new \RuntimeException('SMS verification could not complete a bounded conditional cache update.');
    }

    /** Resolve a destination without coercing arrays/objects or treating the literal "0" as missing. */
    private function destination(): string
    {
        $mobile = $this->_mobile ?? $this->request->{$this->method}($this->mobileKey);
        if ($mobile === null || $mobile === '') { throw new \InvalidArgumentException(static::$msg['no_mobile']); }
        if (!is_string($mobile) && !is_int($mobile)) { throw new \InvalidArgumentException('SMS mobile must be a string or integer.'); }
        $mobile = (string) $mobile;
        self::identifier($mobile, 'mobile');
        return $mobile;
    }

    /** Key fields are length-delimited and app-scoped, so scene/mobile concatenations cannot collide. */
    private function key(string $mobile): string
    {
        $application = $this->request->app['app'] ?? 'index';
        self::identifier($application, 'application');
        return 'rcsms:v2:' . hash('sha256', serialize([$this->cachePrefix, $application, $this->scene, $mobile]));
    }

    /** Packed IPs support canonical IPv6 equality; missing/invalid peers cannot silently disable a requested binding. */
    private function binding(): ?string
    {
        if (!$this->ipCheck) { return null; }
        $ip = $this->request->ip();
        if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new \RuntimeException('IP-bound SMS codes require a valid request client IP.');
        }
        return bin2hex(inet_pton($ip));
    }

    /** Use RC cache atomics; never fall back to unsafe get/set/delete sequences for a custom cache. */
    private function storage(): object
    {
        $this->cache ??= \cache();
        if (!is_object($this->cache)) { throw new \RuntimeException('SMS verification requires a configured cache store.'); }
        foreach (['get', 'set', 'compareAndSwap'] as $method) {
            if (!is_callable([$this->cache, $method])) { throw new \RuntimeException('SMS cache requires get(), set() and atomic compareAndSwap().'); }
        }
        return $this->cache;
    }

    /** Salt/context avoid a plaintext code and cross-record equality; short codes still need protected storage and rate limits. */
    private function digest(string $key, string $nonce, string $code, ?string $binding): string
    {
        return hash('sha256', serialize(['rcmaker-sms-v2', $key, $nonce, $code, $binding]));
    }

    /** Encode only a small scalar schema; the answer and phone number never become cache payload fields. */
    private static function encode(array $record): string { return json_encode($record, JSON_THROW_ON_ERROR); }

    /** Reject legacy/corrupt data instead of guessing a plaintext-code format or activating serialized objects. */
    private static function decode(mixed $raw): ?array
    {
        if (!is_string($raw) || strlen($raw) > 1024) { return null; }
        try { $record = json_decode($raw, true, 8, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { return null; }
        if (!is_array($record) || count($record) !== 7 || ($record['v'] ?? null) !== 2
            || !is_string($record['nonce'] ?? null) || !preg_match('/\A[a-f0-9]{32}\z/D', $record['nonce'])
            || !is_string($record['digest'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D', $record['digest'])
            || !array_key_exists('ip', $record) || ($record['ip'] !== null && (!is_string($record['ip'])
                || !preg_match('/\A(?:[a-f0-9]{8}|[a-f0-9]{32})\z/D', $record['ip'])))
            || !is_int($record['expires_at'] ?? null) || $record['expires_at'] < 1
            || !is_int($record['limit'] ?? null) || $record['limit'] < 1 || $record['limit'] > 100
            || !is_int($record['attempts'] ?? null) || $record['attempts'] < 0 || $record['attempts'] > $record['limit']) {
            return null;
        }
        return $record;
    }

    /** Normalize bounded integer configuration, including ordinary integer strings from environment-backed configuration. */
    private static function integer(mixed $value, int $min, int $max, string $option): int
    {
        if (is_string($value) && strlen($value) <= 10 && ctype_digit($value)) { $value = (int) $value; }
        if (!is_int($value) || $value < $min || $value > $max) { throw new \InvalidArgumentException('Invalid SMS ' . $option . '.'); }
        return $value;
    }

    /** Bound identifiers before storage or hashing; business phone-format validation belongs to validator(). */
    private static function identifier(mixed $value, string $option, bool $allowEmpty = false): void
    {
        if (!is_string($value) || (!$allowEmpty && $value === '') || strlen($value) > 256 || str_contains($value, "\0")) {
            throw new \InvalidArgumentException('Invalid SMS ' . $option . '.');
        }
    }

    /** Set a stable public validation message without exposing code digests or internal paths. */
    private function fail(string $message): bool { $this->error = static::$msg[$message]; return false; }
}
