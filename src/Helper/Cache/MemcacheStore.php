<?php

declare(strict_types=1);

namespace RC\Helper\Cache;

use RC\Helper\Cache\think\cache\Driver;
use RuntimeException;
use think\exception\InvalidCacheException;

require_once __DIR__ . '/bootstrap.php';

/**
 * Shared RC semantics for the two native Memcache clients.
 * Use cache configuration, not this base class, to select a concrete driver.
 */
abstract class MemcacheStore extends Driver
{
    use CacheBehavior;

    protected $options = [
        'host' => '127.0.0.1', 'port' => 11211, 'expire' => 0, 'timeout' => 1000,
        'persistent' => false, 'prefix' => '', 'tag_prefix' => 'tag:', 'serialize' => [],
        'username' => '', 'password' => '', 'option' => [],
    ];

    private string $namespaceKey;
    private string $keyPrefix;
    private ?string $generation = null;
    private const DELETED = 'RCM1:deleted';
    private static ?\WeakMap $forkHandlers = null;
    private static bool $persistentMemcacheUsed = false;
    private bool $handlerActive = false;
    private ?int $handlerPid = null;

    /** Configure one logical namespace; client connections are created by the subclass. */
    protected function configure(array $options): void
    {
        $this->options = array_replace($this->options, $options);
        if (!is_string($this->options['prefix'])) {
            throw new \InvalidArgumentException('Memcache prefix must be a string unique to this application.');
        }
        $persistent = filter_var($this->options['persistent'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($persistent === null) {
            throw new \InvalidArgumentException('Memcache persistent must be a boolean or a valid boolean string.');
        }
        $this->options['persistent'] = $persistent;
        $this->keyPrefix = 'rcm1:' . hash('sha256', $this->options['prefix']) . ':';
        $this->namespaceKey = $this->keyPrefix . 'namespace';
        $this->handlerActive = false;
        $this->handlerPid = null;
    }

    /** Recreate a released native client on first worker use; healthy operations do not probe the network or PID. */
    public function handler()
    {
        $this->handler ??= $this->createHandler();
        if (!$this->handlerActive) {
            $this->handlerPid = getmypid();
            $this->handlerActive = true;
            if ($this->handler instanceof \Memcache || $this->handler instanceof \Memcached) {
                self::$forkHandlers ??= new \WeakMap();
                self::$forkHandlers[$this->handler] = ['store' => \WeakReference::create($this), 'pid' => $this->handlerPid];
                // Native persistent streams outlive both the RC store and its PHP client object.
                if ($this->handler instanceof \Memcache && $this->options['persistent']) {
                    self::$persistentMemcacheUsed = true;
                }
            }
        }
        return $this->handler;
    }

    /** Concrete built-in transports supply their native configuration without changing cache data. */
    protected function createHandler(): object
    {
        throw new \LogicException('This custom Memcache store must implement native handler recreation.');
    }

    /** Forward native extensions through the same lifecycle-aware accessor as regular cache operations. */
    public function __call($method, $args)
    {
        return call_user_func_array([$this->handler(), $method], $args);
    }

    /** @internal Release owned native sockets in the master immediately before every framework-managed fork. */
    public static function prepareForFork(): int
    {
        $pid = getmypid();
        if (self::$persistentMemcacheUsed) {
            throw new \LogicException('Cannot fork with an opened persistent Memcache pool; initialize it inside workers or set persistent=false before opening it.');
        }
        $active = [];
        foreach (self::$forkHandlers ?? [] as $handler => $entry) {
            if ($entry['pid'] !== $pid) {
                throw new \LogicException('Prepare cache connections for fork in their owning process, not in an inherited child.');
            }
            $active[] = [$handler, $entry['store']->get()];
        }
        // Validate every store first: an unsupported persistent pool must not partially close other clients.
        foreach ($active as [$handler, $store]) {
            $closed = $handler instanceof \Memcache ? $handler->close() : $handler->quit();
            if (!$closed) {
                throw new RuntimeException('Cannot release a Memcache connection before fork.');
            }
            if ($store !== null && $store->handler === $handler) {
                $store->handler = null;
                $store->handlerActive = false;
                $store->handlerPid = null;
                $store->generation = null;
            }
            unset(self::$forkHandlers[$handler]);
        }
        return count($active);
    }

    /** Expand the existing host/port configuration into native client server tuples. */
    protected function servers(): array
    {
        $hosts = array_values((array) $this->options['host']);
        $ports = array_values((array) $this->options['port']);
        if (!$hosts) {
            throw new \InvalidArgumentException('Memcache requires at least one host.');
        }
        $servers = [];
        foreach ($hosts as $index => $host) {
            $port = $ports[$index] ?? $ports[0] ?? 11211;
            if (!is_string($host) || $host === '' || !is_numeric($port) || $port < 1 || $port > 65535) {
                throw new \InvalidArgumentException('Invalid Memcache host or port.');
            }
            $servers[] = [$host, (int) $port, 1];
        }
        return $servers;
    }

    /** Read one or more raw string values; transport failures must not masquerade as misses. */
    abstract protected function fetchMany(array $keys): array;

    /** Return raw values and CAS tokens indexed by key, omitting absent entries. */
    abstract protected function fetchCasMany(array $keys): array;

    /** Store a string with set/add/CAS; a false result means a conflict or failed write. */
    abstract protected function storeRaw(string $key, string $value, int $expires, string $mode, mixed $cas = null): bool;

    /** Delete one physical key; an already absent key counts as success. */
    abstract protected function deleteRaw(string $key): bool;

    /** Validate persisted generation metadata rather than allowing it to select arbitrary keys. */
    private function validGeneration(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{32}$/D', $value) === 1;
    }

    /** Resolve namespace changes across workers; random generations prevent eviction resurrection. */
    private function refreshGeneration(?array $values = null): string
    {
        $values ??= $this->fetchMany([$this->namespaceKey]);
        $value = $values[$this->namespaceKey] ?? null;
        for ($attempt = 0; !$this->validGeneration($value) && $attempt < 8; $attempt++) {
            if ($value !== null) {
                throw new RuntimeException('Invalid Memcache namespace metadata.');
            }
            $candidate = bin2hex(random_bytes(16));
            if ($this->storeRaw($this->namespaceKey, $candidate, 0, 'add')) {
                return $this->generation = $candidate;
            }
            $value = $this->fetchMany([$this->namespaceKey])[$this->namespaceKey] ?? null;
        }
        if (!$this->validGeneration($value)) {
            throw new RuntimeException('Cannot initialize Memcache namespace.');
        }
        return $this->generation = $value;
    }

    /** Hash logical names to support Unicode, spaces and keys beyond the protocol length limit. */
    private function physicalKey(string $name, string $generation): string
    {
        if ($name === '' || str_contains($name, "\0")) {
            throw new \InvalidArgumentException('Memcache key must be non-empty and contain no NUL.');
        }
        return $this->keyPrefix . $generation . ':' . hash('sha256', $name);
    }

    /** Return the current physical key, e.g. for the framework tag implementation. */
    public function getCacheKey(string $name): string
    {
        return $this->physicalKey($name, $this->refreshGeneration());
    }

    /** A warm read fetches metadata and data together, keeping cross-worker clear checks to one trip. */
    private function readCurrent(string $name, bool $cas = false): array
    {
        $previous = $this->generation;
        $key = $previous === null ? null : $this->physicalKey($name, $previous);
        $keys = $key === null ? [$this->namespaceKey] : [$this->namespaceKey, $key];
        $values = $cas ? $this->fetchCasMany($keys) : $this->fetchMany($keys);
        $metadata = $cas ? [$this->namespaceKey => $values[$this->namespaceKey]['value'] ?? null] : $values;
        $current = $this->refreshGeneration($metadata);
        if ($key === null || $current !== $previous) {
            $key = $this->physicalKey($name, $current);
            $values = $cas ? $this->fetchCasMany([$key]) : $this->fetchMany([$key]);
        }
        return [$key, $values[$key] ?? null];
    }

    /** Use absolute deadlines so CAS updates retain TTL and durations above 30 days work correctly. */
    private function deadline(mixed $expire): int
    {
        $ttl = $this->getExpireTime($expire ?? $this->options['expire']);
        if ($ttl < 0) {
            return -1;
        }
        if ($ttl === 0) {
            return 0;
        }
        $now = time();
        if ($ttl > 4294967295 - $now) {
            throw new \InvalidArgumentException('Memcache expiration exceeds the server timestamp range.');
        }
        return $now + $ttl;
    }

    /** Store the deadline with the value because Memcache does not return remaining TTL. */
    private function encode(mixed $value, int $deadline): string
    {
        return 'RCM1:' . $deadline . "\n" . $this->serialize($value);
    }

    /** Return [value, deadline], or null for missing, expired or corrupt data without deleting a replacement. */
    private function decode(mixed $raw): ?array
    {
        if (!is_string($raw) || $raw === self::DELETED || !str_starts_with($raw, 'RCM1:')) {
            return null;
        }
        $separator = strpos($raw, "\n", 5);
        if ($separator === false || $separator > 15) {
            return null;
        }
        $deadline = filter_var(substr($raw, 5, $separator - 5), FILTER_VALIDATE_INT);
        if ($deadline === false || $deadline < 0 || ($deadline !== 0 && $deadline <= time())) {
            return null;
        }
        try {
            return [$this->unserialize(substr($raw, $separator + 1)), $deadline];
        } catch (InvalidCacheException) {
            return null;
        }
    }

    /** Test value presence, including false, null and zero. */
    public function has($name): bool
    {
        $this->readTimes++;
        return $this->decode($this->readCurrent((string) $name)[1]) !== null;
    }

    /** Read a logical key and evaluate a fallback closure only on a miss. */
    public function get($name, $default = null): mixed
    {
        $this->readTimes++;
        $entry = $this->decode($this->readCurrent((string) $name)[1]);
        return $entry !== null ? $entry[0] : $this->getDefaultValue($name, $default);
    }

    /** Write a cache value; a negative TTL removes it immediately. */
    public function set($name, $value, $expire = null): bool
    {
        $deadline = $this->deadline($expire);
        if ($deadline < 0) {
            return $this->delete($name);
        }
        $encoded = $this->encode($value, $deadline);
        $this->writeTimes++;
        return $this->storeRaw($this->getCacheKey((string) $name), $encoded, $deadline, 'set');
    }

    /** Apply one atomic update; callbacks here are internal, side-effect-free operations only. */
    private function mutate(string $name, \Closure $change, mixed $initial, mixed $expire = null, bool $consume = false, ?\Closure $matches = null): mixed
    {
        $this->writeTimes++;
        [$key, $raw] = $this->readCurrent($name, true);
        for ($attempt = 0; $attempt < 128; $attempt++) {
            $entry = $this->decode($raw['value'] ?? null);
            if (($consume || $matches !== null) && $entry === null) {
                return null;
            }
            if ($matches !== null && !$matches($entry[0])) { return null; }
            $deadline = $entry[1] ?? $this->deadline($expire);
            if ($deadline < 0) {
                throw new \InvalidArgumentException('Cannot mutate an already expired cache value.');
            }
            $value = $change($entry !== null ? $entry[0] : $initial);
            $encoded = $consume ? self::DELETED : $this->encode($value, $deadline);
            // Do not delete after successful consumption: that could delete a concurrent new writer.
            if ($this->storeRaw($key, $encoded, $consume ? time() + 1 : $deadline,
                $raw === null ? 'add' : 'cas', $raw['cas'] ?? null)) {
                return $consume ? $entry : $value;
            }
            if ($attempt >= 8) {
                $window = min(8000, 250 * (1 << min(5, intdiv($attempt, 8))));
                usleep(random_int(100, $window));
            }
            $next = $this->fetchCasMany([$key])[$key] ?? null;
            if (($next['cas'] ?? null) === ($raw['cas'] ?? null)) {
                throw new RuntimeException('Memcache rejected an unchanged entry; check the server item-size limit.');
            }
            $raw = $next;
        }
        throw new RuntimeException('Memcache CAS update failed after bounded retries.');
    }

    /** Add a non-negative integer step atomically, retaining signed values and the existing deadline. */
    public function inc($name, $step = 1, $expire = null)
    {
        if ($expire !== null && $this->getExpireTime($expire) <= 0) {
            throw new \InvalidArgumentException('An initial counter TTL must be positive.');
        }
        return $this->counter((string) $name, $step, false, $expire);
    }

    /** Subtract atomically without the native protocol's unsigned zero clamp. */
    public function dec($name, $step = 1)
    {
        return $this->counter((string) $name, $step, true);
    }

    /** Reject fractional steps, non-integer values and overflow instead of losing numeric precision. */
    private function counter(string $name, mixed $step, bool $subtract, mixed $expire = null): int
    {
        if (!is_int($step) || $step < 0) {
            throw new \InvalidArgumentException('Memcache counter step must be a non-negative integer.');
        }
        return $this->mutate($name, static function ($value) use ($step, $subtract): int {
            if (!is_int($value)) {
                throw new \InvalidArgumentException('Memcache counters require an integer cache value.');
            }
            $result = $subtract ? $value - $step : $value + $step;
            if (!is_int($result)) {
                throw new \OverflowException('Memcache counter overflow.');
            }
            return $result;
        }, 0, $expire);
    }

    /** Consume a value with CAS; concurrent callers cannot both return the same entry. */
    public function pull($name, $default = null)
    {
        $entry = $this->mutate((string) $name, static fn ($value) => $value, null, null, true);
        return $entry !== null ? $entry[0] : $this->getDefaultValue($name, $default);
    }

    /** Conditional string replacement/consumption shares the native CAS loop and preserves the original deadline. */
    public function compareAndSwap($name, string $expected, ?string $replacement): bool
    {
        return $this->mutate((string) $name, static fn($value) => $replacement, null, null, $replacement === null,
            static fn($value): bool => $value === $expected) !== null;
    }

    /** Native add/CAS distinguishes missing entries and refreshes value/expiry together; conflicts never grant a write. */
    public function compareAndSwapWithTtl($name, ?string $expected, string $replacement, int $ttl): bool
    {
        if ($ttl < 1) { throw new \InvalidArgumentException('Conditional cache TTL must be positive.'); }
        $deadline = $this->deadline($ttl);
        $this->readTimes++;
        $this->writeTimes++;
        [$key, $raw] = $this->readCurrent((string) $name, true);
        $entry = $this->decode($raw['value'] ?? null);
        if ($expected === null ? $entry !== null : ($entry === null || $entry[0] !== $expected)) { return false; }
        return $this->storeRaw($key, $this->encode($replacement, $deadline), $deadline, $raw === null ? 'add' : 'cas', $raw['cas'] ?? null);
    }

    /** Append one ordinary array entry while retaining the legacy 1000-entry bound. */
    public function push($name, $value): void
    {
        $this->appendArray((string) $name, $value, true);
    }

    /** Return the usual tag API with backend-specific bucket cleanup. */
    public function tag($name): MemcacheTagSet
    {
        $tags = (array) $name;
        foreach ($tags as $tag) {
            if (!is_string($tag) || $tag === '') {
                throw new \InvalidArgumentException('Cache tags must be non-empty strings.');
            }
        }
        return new MemcacheTagSet(array_values(array_unique($tags)), $this);
    }

    /** Spread tag CAS updates over 16 buckets, preserving deduplication without a growing hot key. */
    public function append($name, $value): void
    {
        if (!is_string($value) || !str_starts_with($value, $this->keyPrefix)) {
            throw new \InvalidArgumentException('Memcache tag members must be physical keys from this store.');
        }
        $bucket = hash('sha256', $value)[0];
        $this->appendArray($name . ':bucket:' . $bucket, $value, false);
    }

    /** Resolve all physical bucket keys under a single current generation. */
    private function tagBuckets(string $tag): array
    {
        $generation = $this->refreshGeneration();
        $base = $this->getTagKey($tag);
        $keys = [];
        foreach (str_split('0123456789abcdef') as $bucket) {
            $keys[] = $this->physicalKey($base . ':bucket:' . $bucket, $generation);
        }
        return $keys;
    }

    /** Read all tag members in a single native multi-get, without silently capping membership. */
    private function readTagBuckets(array $keys): array
    {
        $items = [];
        foreach ($this->fetchMany($keys) as $raw) {
            $entry = $this->decode($raw);
            if ($entry !== null && is_array($entry[0])) {
                $items = array_merge($items, $entry[0]);
            }
        }
        return $items;
    }

    /** Support the upstream public tag-inspection API with bucketed storage. */
    public function getTagItems(string $tag): array
    {
        return $this->readTagBuckets($this->tagBuckets($tag));
    }

    /** Remove a tag's members and captured buckets; concurrent clear/write is not a multi-key transaction. */
    public function clearTagged(string $tag): void
    {
        $buckets = $this->tagBuckets($tag);
        $this->clearTag($this->readTagBuckets($buckets));
        $this->clearTag($buckets);
    }

    /** CAS retries recompute the array against the latest server value. Oversize items fail explicitly. */
    private function appendArray(string $name, mixed $value, bool $bounded): void
    {
        $this->mutate($name, static function ($items) use ($value, $bounded): array {
            if (!is_array($items)) {
                throw new \InvalidArgumentException('Only array cache values can be appended.');
            }
            if (!in_array($value, $items, true)) {
                $items[] = $value;
                if ($bounded && count($items) > 1000) {
                    array_shift($items);
                }
            }
            return $items;
        }, [], $bounded ? null : 0);
    }

    /** Delete immediately; delayed deletion is not safe for values recreated by another worker. */
    public function delete($name, $ttl = false): bool
    {
        if ($ttl !== false && $ttl !== 0 && $ttl !== null) {
            throw new \InvalidArgumentException('Delayed Memcache deletion is not supported.');
        }
        $this->writeTimes++;
        return $this->deleteRaw($this->getCacheKey((string) $name));
    }

    /** Invalidate this prefix in O(1); old generations are reclaimed by normal expiration/LRU eviction. */
    public function clear(): bool
    {
        if ($this->options['prefix'] === '') {
            throw new \LogicException('Configure a unique non-empty Memcache prefix before clearing a store.');
        }
        $this->writeTimes++;
        $generation = bin2hex(random_bytes(16));
        if (!$this->storeRaw($this->namespaceKey, $generation, 0, 'set')) {
            return false;
        }
        $this->generation = $generation;
        return true;
    }

    /** Validate all physical tag members before deleting any; never trust another store's keys. */
    public function clearTag($keys): void
    {
        if ($this->options['prefix'] === '') {
            throw new \LogicException('Configure a unique non-empty Memcache prefix before clearing tags.');
        }
        foreach ($keys as $key) {
            if (!is_string($key) || !str_starts_with($key, $this->keyPrefix)
                || !preg_match('/^[a-f0-9]{32}:[a-f0-9]{64}$/D', substr($key, strlen($this->keyPrefix)))) {
                throw new \InvalidArgumentException('Tag member does not belong to this Memcache store.');
            }
        }
        foreach ($keys as $key) {
            if (!$this->deleteRaw($key)) {
                throw new RuntimeException('Cannot delete Memcache tag member.');
            }
        }
    }
}
