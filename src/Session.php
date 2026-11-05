<?php

declare(strict_types=1);

namespace RC;

use RC\Http\Workerman\Session as RuntimeSession;
use Workerman\Protocols\Http\Request as NativeRequest;
use Workerman\Protocols\Http\Response as NativeResponse;

/** Framework bootstrap and SAPI cookie adapter; business code uses Request::session(), not session_start(). */
class Session implements Bootstrap
{
    protected static $_sessionName = 'PHPSID';
    protected array $_data = [];
    public $session = null;
    protected $id;
    protected $request;
    protected $connection;
    private static bool $started = false;
    private bool $failed = false;

    /** Keep the existing FPM and legacy sequential Swoole constructor contract. */
    public function __construct($id, $request = null, $connection = null)
    {
        $this->id = $id; $this->request = $request; $this->connection = $connection;
    }

    /** Delegate secure SID generation to PHP through the original Workerman API. */
    protected static function createSessionId() { return NativeRequest::createSessionId(); }

    /** Cookie values are untrusted; reuse only fully valid, optionally DQUOTE-wrapped identifiers. */
    public static function normalizeId(mixed $id): ?string
    {
        if (is_string($id) && strlen($id) >= 2 && $id[0] === '"' && $id[-1] === '"') { $id = substr($id, 1, -1); }
        return RuntimeSession::isValidId($id) ? $id : null;
    }

    /** Read one cookie and queue a replacement; never create a second native PHP session engine. */
    private function sessionId($engine = null): string|false
    {
        if (!isset($this->_data['sid'])) {
            static::ensureStarted();
            $name = static::sessionName();
            $cookie = $engine === 'swoole' ? ($this->request->cookie[$name] ?? null) : ($_COOKIE[$name] ?? null);
            $sid = static::normalizeId($cookie);
            $this->_data['from_cookie'] = $sid !== null;
            if ($sid === null) {
                $sid = static::createSessionId();
                $header = static::cookieHeader($name, $sid, RuntimeSession::getCookieParams());
                if ($engine === 'swoole' && !$this->connection) { return false; }
                if ($engine !== 'swoole' && headers_sent()) { throw new \RuntimeException('Cannot create a session after response headers were sent.'); }
                $this->_data['cookie_header'] = $header; $this->_data['engine'] = $engine;
            }
            $this->_data['sid'] = $sid;
        }
        return $this->_data['sid'];
    }

    /** Return one object per manager; keep the legacy $eng name for named-argument callers. */
    public function session($eng = null)
    {
        if ($this->failed) { throw new \RuntimeException('Session initialization has already failed.'); }
        if ($this->session === null) {
            try {
                $sid = $this->sessionId($eng);
                if ($sid === false) { return false; }
                $session = new RuntimeSession($sid);
                if (RuntimeSession::$useStrictMode && ($this->_data['from_cookie'] ?? false) && !$session->hasStoredData()) {
                    $session->abandon();
                    $sid = static::createSessionId();
                    $line = static::cookieHeader(static::sessionName(), $sid, RuntimeSession::getCookieParams());
                    if ($eng !== 'swoole' && headers_sent()) { throw new \RuntimeException('Cannot replace an unknown session after response headers were sent.'); }
                    $this->_data['cookie_header'] = $line; $this->_data['engine'] = $eng;
                    $session = new RuntimeSession($sid); $this->_data['sid'] = $sid;
                }
                $this->session = $session;
            } catch (\Throwable $error) { $this->failed = true; unset($this->_data['cookie_header']); throw $error; }
        }
        return $this->session;
    }

    /** Rotate through the same persistence policy on FPM and Workerman; callers receive the new SID. */
    public function regenerateId(bool $deleteOldSession = false, $engine = null): string
    {
        if ($engine === 'swoole' ? !$this->connection : headers_sent()) {
            throw new \RuntimeException('Cannot regenerate a session after response headers were sent.');
        }
        $name = static::sessionName(); $params = RuntimeSession::getCookieParams();
        $session = static::rotate($this->session($engine), $deleteOldSession, function (string $sid) use ($name, $params, $engine): void {
            $line = static::cookieHeader($name, $sid, $params);
            $this->_data['cookie_header'] = $line; $this->_data['engine'] = $engine;
        });
        $this->session = $session; $this->_data['sid'] = $session->getId();
        return $session->getId();
    }

    /** Publish a SID cookie only after confirmed persistence, never along with a storage-error response. */
    public function save(): void
    {
        if ($this->failed) { throw new \RuntimeException('Session initialization has already failed.'); }
        if ($this->session !== null) { $this->session->commit(); }
        if (isset($this->_data['cookie_header'])) {
            if (($this->_data['engine'] ?? null) === 'swoole') { $this->connection->header('Set-Cookie', $this->_data['cookie_header']); }
            else {
                if (headers_sent()) { throw new \RuntimeException('Cannot publish a session cookie after response headers were sent.'); }
                header('Set-Cookie: ' . $this->_data['cookie_header'], false);
            }
            unset($this->_data['cookie_header']);
        }
    }

    /** Error cleanup must not publish a new identity or perform destructor IO. */
    public function abandon(): void
    {
        $this->failed = true;
        unset($this->_data['cookie_header']);
        if ($this->session !== null) { $this->session->abandon(); }
    }

    /** No exception object/trace is kept in the request-scoped manager. */
    public function hasFailed(): bool { return $this->failed || ($this->session !== null && $this->session->hasFailed()); }

    /** Persist the replacement before changing old storage/cookies; failures never publish a new identity. */
    public static function rotate(RuntimeSession $old, bool $deleteOldSession, \Closure $emit): RuntimeSession
    {
        $new = new RuntimeSession(static::createSessionId());
        $new->put($old->all()); $new->commit();
        if ($deleteOldSession) { $old->flush(); }
        $old->commit();
        $emit($new->getId());
        return $new;
    }

    /** Synchronize the existing name getter/setter with RC's isolated runtime settings. */
    public static function sessionName($name = null)
    {
        static::ensureStarted();
        if ($name !== null) { static::validateCookieName($name); static::$_sessionName = RuntimeSession::$name = $name; }
        return RuntimeSession::$name;
    }

    /** Custom script entry points may omit bootstrap; initialize once, without per-request config reads. */
    public static function ensureStarted(): void { if (!static::$started) { static::start(); } }

    /** Validate before publishing policy; no backend socket/file is opened until a session is read. */
    public static function start()
    {
        $config = Config::get('session') ?? [];
        if (!is_array($config)) { throw new \InvalidArgumentException('Session configuration must be an array.'); }
        $handler = $config['handler'] ?? \RC\Http\FileSessionHandler::class;
        $handler = match ($handler) {
            \Workerman\Protocols\Http\Session\FileSessionHandler::class => \RC\Http\FileSessionHandler::class,
            \Workerman\Protocols\Http\Session\RedisSessionHandler::class => \RC\Http\RedisSessionHandler::class,
            default => $handler,
        };
        if (!is_string($handler)) { throw new \InvalidArgumentException('Session handler must be a class name.'); }
        $type = $config['type'] ?? (is_a($handler, \RC\Http\RedisClusterSessionHandler::class, true) ? 'redis_cluster'
            : (is_a($handler, \RC\Http\RedisSessionHandler::class, true) ? 'redis' : 'file'));
        if (!is_string($type)) { throw new \InvalidArgumentException('Session type must be a configuration key.'); }
        $settings = static::settings($config);
        $segments = $config['config'] ?? [];
        if (!is_array($segments) || ($segments !== [] && !array_key_exists($type, $segments))) {
            throw new \InvalidArgumentException('Session type does not identify a configured backend.');
        }
        $storage = $segments[$type] ?? [];
        if (!is_array($storage)) { throw new \InvalidArgumentException('Session handler configuration must be an array.'); }
        if (is_a($handler, \RC\Http\FileSessionHandler::class, true) || is_a($handler, \RC\Http\RedisSessionHandler::class, true)) {
            $storage['lifetime'] = $settings['lifetime'];
            $storage['max_data_size'] = $settings['maxDataSize'];
            if (is_a($handler, \RC\Http\FileSessionHandler::class, true)) { $storage['save_path'] ??= runtime_path() . '/sessions'; }
        }
        RuntimeSession::handlerClass($handler, $storage);
        foreach ($settings as $property => $value) { RuntimeSession::${$property} = $value; }
        static::$_sessionName = $settings['name'];
        static::$started = true;
    }

    /** Use the native cookie formatter: Max-Age takes relative seconds; zero means a browser-session cookie. */
    public static function cookieHeader(string $name, string $sid, array $params): string
    {
        static::validateCookieName($name);
        if (!RuntimeSession::isValidId($sid)) { throw new \InvalidArgumentException('Invalid session identifier.'); }
        $validated = static::settings(['session_name' => $name, 'cookie_lifetime' => $params['lifetime'],
            'cookie_path' => $params['path'], 'domain' => $params['domain'], 'secure' => $params['secure'],
            'http_only' => $params['httponly'], 'same_site' => $params['samesite']]);
        $response = new NativeResponse();
        $response->cookie($name, $sid, $validated['cookieLifetime'] === 0 ? null : $validated['cookieLifetime'],
            $validated['cookiePath'], $validated['domain'], $validated['secure'], $validated['httpOnly'], $validated['sameSite']);
        return $response->getHeader('Set-Cookie')[0];
    }

    /** Keep option parsing and unsafe header rejection out of the normal request hot path. */
    private static function settings(array $config): array
    {
        $cookie = session_get_cookie_params();
        $name = $config['session_name'] ?? 'PHPSID'; static::validateCookieName($name);
        $integer = static function (mixed $value, string $key, int $minimum): int {
            if ((!is_int($value) && !is_string($value)) || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < $minimum) {
                throw new \InvalidArgumentException('Invalid session ' . $key . '.');
            }
            return (int) $value;
        };
        $boolean = static function (mixed $value, string $key): bool {
            $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($parsed === null) { throw new \InvalidArgumentException('Invalid session ' . $key . '.'); }
            return $parsed;
        };
        $path = $config['cookie_path'] ?? $cookie['path']; $domain = $config['domain'] ?? $cookie['domain'];
        if (!is_string($path) || ($path !== '' && $path[0] !== '/') || preg_match('/[\x00-\x1f\x7f;]/', $path)
            || !is_string($domain) || $domain !== '' && filter_var($domain[0] === '.' ? substr($domain, 1) : $domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new \InvalidArgumentException('Invalid session cookie path or domain.');
        }
        $site = $config['same_site'] ?? ($cookie['samesite'] ?: 'Lax');
        if (!is_string($site) || !in_array(strtolower($site), ['', 'lax', 'strict', 'none'], true)) { throw new \InvalidArgumentException('Invalid session same_site.'); }
        $site = ucfirst(strtolower($site)); $secure = $boolean($config['secure'] ?? $cookie['secure'], 'secure');
        if ($site === 'None' && !$secure || str_starts_with($name, '__Secure-') && !$secure
            || str_starts_with($name, '__Host-') && (!$secure || $path !== '/' || $domain !== '')) {
            throw new \InvalidArgumentException('Session cookie security attributes conflict with its name or SameSite policy.');
        }
        $gc = $config['gc_probability'] ?? [max(0, (int) ini_get('session.gc_probability')), max(1, (int) ini_get('session.gc_divisor'))];
        if (!is_array($gc) || !array_is_list($gc) || count($gc) !== 2) { throw new \InvalidArgumentException('Session gc_probability requires [probability, divisor].'); }
        $gc = [$integer($gc[0], 'GC probability', 0), $integer($gc[1], 'GC divisor', 1)];
        if ($gc[0] > $gc[1]) { throw new \InvalidArgumentException('Session GC probability exceeds its divisor.'); }
        return ['name' => $name, 'lifetime' => $integer($config['lifetime'] ?? ((int) ini_get('session.gc_maxlifetime') ?: 1440), 'lifetime', 1),
            'cookieLifetime' => $integer($config['cookie_lifetime'] ?? $cookie['lifetime'], 'cookie_lifetime', 0),
            'cookiePath' => $path, 'domain' => $domain, 'secure' => $secure, 'sameSite' => $site,
            'httpOnly' => $boolean($config['http_only'] ?? true, 'http_only'),
            'useStrictMode' => $boolean($config['use_strict_mode'] ?? true, 'use_strict_mode'),
            'autoUpdateTimestamp' => $boolean($config['auto_update_timestamp'] ?? false, 'auto_update_timestamp'),
            'gcProbability' => $gc, 'maxDataSize' => $integer($config['max_data_size'] ?? 1048576, 'max_data_size', 1)];
    }

    /** RFC cookie token names only, excluding header delimiters and control characters. */
    private static function validateCookieName(mixed $name): void
    {
        if (!is_string($name) || !preg_match('/\A[!#$%&\'*+.^_`|~0-9A-Za-z-]{1,128}\z/', $name)) {
            throw new \InvalidArgumentException('Invalid session_name.');
        }
    }
}
