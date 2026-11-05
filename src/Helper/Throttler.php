<?php

namespace RC\Helper;

use RC\Helper\Throttler\Bucket;
use RuntimeException;

/**
 * Request token buckets using RC cache atomics; pass the concrete store returned by cache().
 * Historical API adapted from CodeIgniter Throttle, copyright CodeIgniter Foundation/BCIT.
 * MIT terms and the stable comparison reference are retained in Throttler/.
 */
class Throttler
{
    protected $cache;
    protected $tokenTime = 0;
    protected $prefix = 'throttler_';
    protected $testTime;

    private const MAX_ATTEMPTS = 64;

    /** Store selection is explicit; validation does not open backend connections or create counters. */
    public function __construct(object $cache)
    {
        foreach (['get', 'delete', 'compareAndSwapWithTtl'] as $method) {
            if (!is_callable([$cache, $method])) { throw new \InvalidArgumentException('Throttler requires get(), delete() and atomic compareAndSwapWithTtl().'); }
        }
        if (!method_exists($cache, 'compareAndSwapWithTtl')) {
            throw new \InvalidArgumentException('Pass a concrete atomic cache store, not a magic facade.');
        }
        $this->cache = $cache;
    }

    /** Reset this bucket only; failed deletion is not reported as a successful administrative reset. */
    public function remove(string $key): self
    {
        if ($this->cache->delete($this->bucketKey($key)) !== true) { throw new RuntimeException('Unable to confirm throttler reset.'); }
        $this->tokenTime = 0;
        return $this;
    }

    /** Keep the historical integer-second test clock; ordinary calls use the system wall clock. */
    private function time(): int
    {
        return $this->testTime ?? time();
    }

    /** Set a non-negative deterministic clock before check(); never change a process-global clock. */
    public function setTestTime(int $time): self
    {
        if ($time < 0) { throw new \InvalidArgumentException('Throttler test time must be non-negative.'); }
        $this->testTime = $time;
        return $this;
    }

    /**
     * Consume cost tokens (default 1), refilling capacity over seconds; denied calls expose getTokenTime().
     * Storage failures throw instead of granting. Cache eviction/clear resets quotas; this is not a durable ledger.
     */
    public function check(string $key, int $capacity, int $seconds, int $cost = 1): bool
    {
        $this->tokenTime = max(1, $seconds);
        if ($capacity <= 0 || $seconds <= 0 || $cost <= 0 || $cost > $capacity) { return false; }
        $name = $this->bucketKey($key);
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; ++$attempt) {
            $now = $this->time();
            if ($now < 0) { throw new RuntimeException('Throttler clock must be non-negative.'); }
            $raw = $this->cache->get($name);
            $state = $raw === null
                ? ['v' => 2, 'capacity' => $capacity, 'seconds' => $seconds, 'tokens' => $capacity, 'remainder' => 0, 'at' => $now]
                : $this->decode($raw, $capacity, $seconds);
            [$allowed, $next, $retry] = Bucket::take($state, $now, $cost);
            if (!$allowed) { $this->tokenTime = $retry; return false; }
            $replacement = json_encode($next, JSON_THROW_ON_ERROR);
            $written = $this->cache->compareAndSwapWithTtl($name, $raw, $replacement, $seconds);
            if (!is_bool($written)) { throw new RuntimeException('Throttler cache atomics must return a confirmed boolean.'); }
            if ($written) { $this->tokenTime = 0; return true; }
        }
        throw new RuntimeException('Throttler could not complete a bounded atomic update.');
    }

    /** Return an integer Retry-After delay, or zero after a confirmed grant/reset. */
    public function getTokenTime(): int
    {
        return $this->tokenTime;
    }

    /** Hash length-delimited fields so prefixes, arbitrary keys and the old "Time" suffix cannot collide. */
    private function bucketKey(string $key): string
    {
        if (!is_string($this->prefix)) { throw new \InvalidArgumentException('Throttler prefix must be a string.'); }
        return 'rc-throttle:v2:' . hash('sha256', serialize([$this->prefix, $key]));
    }

    /** Accept only the versioned scalar schema; incompatible policies require an explicit remove()/new key. */
    private function decode(mixed $raw, int $capacity, int $seconds): array
    {
        if (!is_string($raw) || strlen($raw) > 512) { throw new RuntimeException('Invalid throttler bucket state.'); }
        try { $state = json_decode($raw, true, 8, JSON_THROW_ON_ERROR); }
        catch (\JsonException $error) { throw new RuntimeException('Invalid throttler bucket state.', 0, $error); }
        if (!is_array($state) || count($state) !== 6 || ($state['v'] ?? null) !== 2
            || !is_int($state['capacity'] ?? null) || $state['capacity'] < 1
            || !is_int($state['seconds'] ?? null) || $state['seconds'] < 1
            || !is_int($state['tokens'] ?? null) || $state['tokens'] < 0 || $state['tokens'] > $state['capacity']
            || !is_int($state['remainder'] ?? null) || $state['remainder'] < 0 || $state['remainder'] >= $state['seconds']
            || ($state['tokens'] === $state['capacity'] && $state['remainder'] !== 0)
            || !is_int($state['at'] ?? null) || $state['at'] < 0) { throw new RuntimeException('Invalid throttler bucket state.'); }
        if ($state['capacity'] !== $capacity || $state['seconds'] !== $seconds) {
            throw new \LogicException('Throttler policy changed for an existing bucket; remove it or use a versioned key.');
        }
        return $state;
    }
}
