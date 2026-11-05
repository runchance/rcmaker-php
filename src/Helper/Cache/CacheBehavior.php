<?php

declare(strict_types=1);

namespace RC\Helper\Cache;

use Closure;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use RC\Helper\Cache\think\cache\TagSet;
use RC\Helper\Cache\think\Container;
use RuntimeException;
use think\exception\InvalidArgumentException;
use think\exception\InvalidCacheException;

/** Shared RC semantics for bundled cache adapters; upstream sources stay unchanged. */
trait CacheBehavior
{
    /** Convert RC TTL values; an elapsed date must not become an unlimited TTL. */
    protected function getExpireTime($expire): int
    {
        if ($expire instanceof DateInterval) {
            $expire = (new DateTimeImmutable())->add($expire);
        }
        if ($expire instanceof DateTimeInterface) {
            $seconds = $expire->getTimestamp() - time();
            return $seconds > 0 ? $seconds : -1;
        }
        if (is_string($expire) && preg_match('/^-?\d+$/D', $expire)) {
            $expire = filter_var($expire, FILTER_VALIDATE_INT);
        }
        if (!is_int($expire)) {
            throw new InvalidArgumentException('Cache TTL must be an integer, DateInterval or DateTimeInterface.');
        }
        return $expire;
    }

    /** Fetch once, including false/null hits. This is not a distributed mutex. */
    public function remember($name, $value, $expire = null)
    {
        $missing = new \stdClass();
        $cached = $this->get($name, $missing);
        if ($cached !== $missing) {
            return $cached;
        }
        if ($value instanceof Closure) {
            $value = Container::getInstance()->invokeFunction($value);
        }
        if (!$this->set($name, $value, $expire)) {
            throw new RuntimeException('Unable to write remembered cache value.');
        }
        return $value;
    }

    /** Create a short-lived tag view; arbitrary tag names never accumulate in workers. */
    public function tag($name): TagSet
    {
        $tags = (array) $name;
        foreach ($tags as $tag) {
            if (!is_string($tag) || $tag === '') {
                throw new InvalidArgumentException('Cache tags must be non-empty strings.');
            }
        }
        return new TagSet(array_values(array_unique($tags)), $this);
    }

    /** Keep integers native for Redis INCRBY; numeric strings retain their string type. */
    protected function serialize($data): string
    {
        if (isset($this->options['serialize'][0])) {
            return (string) ($this->options['serialize'][0])($data);
        }
        $this->assertCacheValue($data);
        return is_int($data) ? (string) $data : serialize($data);
    }

    /** Decode without activating arbitrary PHP objects from writable cache storage. */
    protected function unserialize($data)
    {
        try {
            if (isset($this->options['serialize'][1])) {
                return ($this->options['serialize'][1])($data);
            }
            $integer = filter_var($data, FILTER_VALIDATE_INT);
            if ($integer !== false) {
                return $integer;
            }
            // Older cache drivers wrote untyped decimal numbers; retain those as strings.
            if (is_numeric($data)) {
                return $data;
            }
            $allowed = $this->options['allowed_classes'] ?? false;
            if ($allowed !== false && !is_array($allowed)) {
                throw new InvalidArgumentException('allowed_classes must be false or an explicit class-name list.');
            }
            $value = @unserialize($data, ['allowed_classes' => $allowed, 'max_depth' => 64]);
            if ($value === false && $data !== 'b:0;') {
                throw new InvalidCacheException('Invalid serialized cache data.');
            }
            $this->assertCacheValue($value);
            return $value;
        } catch (\Throwable $error) {
            throw new InvalidCacheException('Invalid or disallowed cache data.', 0, $error);
        }
    }

    /** Reject resources, recursive arrays and objects not explicitly trusted by configuration. */
    private function assertCacheValue(mixed $value, int $depth = 0): void
    {
        if ($depth > 64 || is_resource($value)) {
            throw new InvalidArgumentException('Cache values must be serializable and no more than 64 levels deep.');
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->assertCacheValue($item, $depth + 1);
            }
        } elseif (is_object($value)) {
            $allowed = $this->options['allowed_classes'] ?? [];
            if (!is_array($allowed) || !in_array($value::class, $allowed, true)
                || $value instanceof \__PHP_Incomplete_Class) {
                throw new InvalidArgumentException('Cache objects require an explicit allowed_classes entry; prefer arrays.');
            }
        }
    }

    /** Append an array member with strict comparison, including nested arrays. */
    public function push($name, $value): void
    {
        $items = $this->get($name, []);
        if (!is_array($items)) {
            throw new InvalidArgumentException('Only an array cache value can be appended.');
        }
        if (!in_array($value, $items, true)) {
            $items[] = $value;
            if (count($items) > 1000) {
                array_shift($items);
            }
        }
        if (!$this->set($name, $items)) {
            throw new RuntimeException('Unable to append cache data.');
        }
    }

    /** Keep missing defaults lazy without unlinking a concurrently replaced cache entry. */
    protected function getDefaultValue($name, $default, $fail = false)
    {
        return $default instanceof Closure ? $default() : $default;
    }
}
