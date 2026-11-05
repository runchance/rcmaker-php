<?php
// Workerman-derived lifecycle adapter, MIT; see MIT-LICENSE.txt. Original CRUD remains inherited unchanged.

declare(strict_types=1);

namespace RC\Http\Workerman;

use Workerman\Protocols\Http\Session as NativeSession;
use Workerman\Protocols\Http\Session\SessionHandlerInterface;

/** Request-owned session state; capture storage once and never replay uncertain persistence in destruction. */
class Session extends NativeSession
{
    protected static string $handlerClass = \RC\Http\FileSessionHandler::class;
    protected static mixed $handlerConfig = null;
    protected static ?SessionHandlerInterface $handler = null;
    public static string $name = 'PHPSID';
    public static bool $autoUpdateTimestamp = false;
    public static int $lifetime = 1440;
    public static int $cookieLifetime = 0;
    public static string $cookiePath = '/';
    public static string $domain = '';
    public static bool $secure = false;
    public static bool $httpOnly = true;
    public static string $sameSite = 'Lax';
    public static array $gcProbability = [1, 20000];
    public static int $maxDataSize = 1048576;
    public static bool $useStrictMode = true;

    private ?SessionHandlerInterface $storage = null;
    private bool $failed = false;
    private bool $refreshOnSave = false;
    private bool $refreshed = false;
    private int $storageLifetime = 1440;
    private int $maxBytes = 1048576;
    private array $collectionPolicy = [0, 20000];
    private bool $stored = false;
    private bool $collectionAttempted = false;

    /** Construct through Request::session(); later configuration cannot redirect this object's writes. */
    public function __construct(string $sessionId)
    {
        $this->isSafe = false;
        if (\Fiber::getCurrent() !== null || extension_loaded('swow')
            || extension_loaded('swoole') && \Swoole\Coroutine::getCid() >= 0) {
            throw new \LogicException('RC sessions require sequential APP execution; coroutine sharing is unsupported.');
        }
        if (!self::isValidId($sessionId)) { throw new \InvalidArgumentException('Invalid session identifier.'); }
        if (static::$handler === null) { static::initHandler(); }
        $this->storage = static::$handler;
        $this->sessionId = $sessionId;
        $this->refreshOnSave = static::$autoUpdateTimestamp;
        $this->storageLifetime = static::$lifetime;
        $this->maxBytes = static::$maxDataSize;
        $this->collectionPolicy = static::$gcProbability;
        $raw = $this->storage->read($sessionId);
        if ($raw !== false) { $this->data = $this->safeDeserialize($raw); }
        $this->stored = $raw !== false;
        $this->isSafe = true;
    }

    /** Native SID alphabet with an exact end anchor, also used by cookie/explicit setter adapters. */
    public static function isValidId(mixed $id): bool
    {
        return is_string($id) && preg_match('/\A[a-zA-Z0-9,-]{16,256}\z/', $id) === 1;
    }

    /** Request factories distinguish a known client session from an attacker-chosen, never-persisted SID. */
    public function hasStoredData(): bool { return $this->stored; }

    /** Changed settings affect future handlers only; an explicit empty configuration must clear previous values. */
    public static function handlerClass(mixed $className = null, mixed $config = null): string
    {
        if (func_num_args() === 0) { return static::$handlerClass; }
        $className ??= static::$handlerClass;
        if (!is_string($className) || !is_a($className, SessionHandlerInterface::class, true)) {
            throw new \InvalidArgumentException('Session handlers must implement the Workerman storage interface.');
        }
        $config = func_num_args() >= 2 ? $config : static::$handlerConfig;
        if ($className !== static::$handlerClass || $config !== static::$handlerConfig) {
            static::$handler = null;
            static::$handlerClass = $className;
            static::$handlerConfig = $config;
        }
        return static::$handlerClass;
    }

    /** Initialize RC defaults only; native init() writes native globals and must not be reused here. */
    public static function init(): void
    {
        $cookie = session_get_cookie_params();
        static::$lifetime = max(1, (int) ini_get('session.gc_maxlifetime') ?: 1440);
        static::$cookieLifetime = max(0, (int) $cookie['lifetime']);
        static::$cookiePath = $cookie['path'];
        static::$domain = $cookie['domain'];
        static::$secure = $cookie['secure'];
        static::$sameSite = $cookie['samesite'] ?: 'Lax';
        static::$gcProbability = [max(0, (int) ini_get('session.gc_probability')), max(1, (int) ini_get('session.gc_divisor'))];
    }

    /** Save before output; mark failure before any destructor or caller can attempt another implicit write. */
    public function save(): void
    {
        if (!$this->isSafe || $this->storage === null) { throw new \LogicException('Inactive session object.'); }
        if ($this->failed) { throw new \RuntimeException('Session persistence has already failed; reload a new request.'); }
        if (!$this->needSave && (!$this->refreshOnSave || $this->refreshed || !$this->stored)) { return; }
        $dirty = $this->needSave;
        $this->needSave = false;
        try {
            if ($dirty && $this->data === []) {
                if ($this->storage->destroy($this->sessionId) !== true) { throw new \RuntimeException('Session deletion failed.'); }
                $this->stored = false;
            } elseif ($dirty) {
                $items = 0;
                self::validateValues($this->data, 0, $items);
                $raw = serialize($this->data);
                if (strlen($raw) > $this->maxBytes) { throw new \InvalidArgumentException('Session data exceeds max_data_size.'); }
                if ($this->storage->write($this->sessionId, $raw) !== true) { throw new \RuntimeException('Session persistence failed.'); }
                $this->stored = true;
            } else {
                $this->storage->updateTimestamp($this->sessionId);
            }
            $this->refreshed = true;
        } catch (\Throwable $error) {
            $this->failed = true;
            throw $error;
        }
    }

    /** Refresh the captured backend without creating a missing session or retrying a failed object. */
    public function refresh(): bool
    {
        if (!$this->isSafe || $this->storage === null) { throw new \LogicException('Inactive session object.'); }
        if ($this->failed) { throw new \RuntimeException('Session persistence has already failed.'); }
        try {
            $result = $this->storage->updateTimestamp($this->sessionId);
            $this->refreshed = true;
            return $result;
        } catch (\Throwable $error) { $this->failed = true; throw $error; }
    }

    /** Collection belongs to this session's original backend and lifetime, not a later configuration. */
    public function gc(): void
    {
        if ($this->storage !== null && $this->storage->gc($this->storageLifetime) !== true) {
            throw new \RuntimeException('Session garbage collection failed.');
        }
    }

    /** Complete request persistence/maintenance before output, never first run GC after a successful response. */
    public function commit(): void
    {
        $this->save();
        if ($this->collectionAttempted) { return; }
        $this->collectionAttempted = true;
        try {
            if ($this->collectionPolicy[0] > 0 && random_int(1, $this->collectionPolicy[1]) <= $this->collectionPolicy[0]) { $this->gc(); }
        } catch (\Throwable $error) { $this->failed = true; throw $error; }
    }

    /** Framework error handling may discard a request whose storage/native-engine state is no longer usable. */
    public function abandon(): void { $this->needSave = false; $this->isSafe = false; $this->failed = true; $this->data = []; $this->storage = null; }

    /** Error preparation can preserve the original exception without retaining its request/connection trace. */
    public function hasFailed(): bool { return $this->failed; }

    /** Bound parsing and reject incomplete objects without autoloading or running their serialization hooks. */
    protected function safeDeserialize(string $data): array
    {
        if (strlen($data) > $this->maxBytes) { throw new \UnexpectedValueException('Stored session data exceeds max_data_size.'); }
        set_error_handler(static function (): never { throw new \UnexpectedValueException('Corrupt serialized session data.'); });
        try { $value = unserialize($data, ['allowed_classes' => false, 'max_depth' => 64]); }
        finally { restore_error_handler(); }
        if (!is_array($value)) { throw new \UnexpectedValueException('Stored session data must be an array.'); }
        $items = 0;
        try { self::validateValues($value, 0, $items); }
        catch (\InvalidArgumentException $error) { throw new \UnexpectedValueException('Unsupported stored session value.', 0, $error); }
        return $value;
    }

    /** Scalar/array values only; bound recursive/reference-heavy graphs before invoking serialize(). */
    private static function validateValues(mixed $value, int $depth, int &$items): void
    {
        if (++$items > 65536 || $depth >= 64) { throw new \InvalidArgumentException('Session values are too large or deeply nested.'); }
        if (is_array($value)) {
            foreach ($value as $child) { self::validateValues($child, $depth + 1, $items); }
        } elseif (!is_scalar($value) && $value !== null) {
            throw new \InvalidArgumentException('Session values must be scalars, null or arrays.');
        }
    }

    /** Live request resources cannot be copied into another implicit save lifecycle. */
    public function __clone(): void { $this->isSafe = false; throw new \LogicException('Session objects cannot be cloned.'); }

    /** Persist all() data through the framework, never a live session object and its handler. */
    public function __serialize(): array { throw new \LogicException('Session objects cannot be serialized.'); }

    /** Deserialization must not acquire a backend or perform IO. */
    public function __unserialize(array $data): void { $this->isSafe = false; throw new \LogicException('Session objects cannot be unserialized.'); }

    /** Direct users retain auto-save; failed or partly constructed objects never perform another write. */
    public function __destruct()
    {
        if (!$this->isSafe || $this->failed) { return; }
        $this->commit();
    }
}

Session::init();
