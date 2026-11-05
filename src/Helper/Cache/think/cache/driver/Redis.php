<?php

declare(strict_types=1);

namespace RC\Helper\Cache\think\cache\driver;

use LogicException;
use RC\Helper\Cache\CacheBehavior;
use RuntimeException;
use think\exception\InvalidArgumentException;
use think\exception\InvalidCacheException;

require_once dirname(__DIR__, 3) . '/bootstrap.php';

/** Lazy Redis adapter with single-prefix keys, standalone/cluster support and scoped cleanup. */
class Redis extends \think\cache\driver\Redis
{
    use CacheBehavior;

    private ?int $handlerPid = null;

    /** Retain the native lazy constructor and record ownership before subclasses can supply a handler. */
    public function __construct(array $options = [])
    {
        parent::__construct(array_replace(['timeout' => 5], $options));
        $this->handlerPid = getmypid();
    }

    /** Open lazily in the current process; inherited sockets must never carry child-process commands. */
    public function handler()
    {
        $pid = getmypid();
        if ($this->handler !== null) {
            // Legacy subclasses may skip the native constructor; their supplied handler is adopted on first use.
            $this->handlerPid ??= $pid;
            if ($this->handlerPid === $pid) {
                return $this->handler;
            }
            try {
                if ($this->handler instanceof \Predis\Client) {
                    $this->handler->disconnect();
                } else {
                    $this->handler->close();
                }
            } finally {
                // Explicit close keeps native persistent pools from recycling this inherited stream.
                $this->handler = null;
                $this->handlerPid = null;
            }
        }
        $options = $this->options;
        $config = $options;
        // Cache driver type/select/prefix have different meanings from the raw connection factory's fields.
        $config['type'] = is_array($options['host']) ? 'cluster' : 'common';
        $config['database'] = $options['database'] ?? $options['select'];
        $config['prefix'] = '';
        $config['read_timeout'] = $options['read_timeout'] ?? $options['readTimeout'] ?? $options['timeout'];
        // Predis historically selects path, not host, when scheme=unix; retain that cache configuration.
        if (($options['scheme'] ?? null) === 'unix' && array_key_exists('path', $options)) {
            if (!is_string($options['path']) || !str_starts_with($options['path'], '/')) {
                throw new InvalidArgumentException('Redis cache Unix socket path must be an absolute filesystem path.');
            }
            $config['host'] = $options['path'];
        }
        if (!is_string($options['prefix']) || !is_numeric($options['timeout']) || !is_finite((float) $options['timeout'])
            || (float) $options['timeout'] <= 0 || !is_numeric($config['read_timeout'])
            || !is_finite((float) $config['read_timeout']) || (float) $config['read_timeout'] <= 0) {
            throw new InvalidArgumentException('Redis cache requires a string prefix and positive finite connection/read timeouts.');
        }
        // Retain older native/Predis SSL arrays, but refuse contradictory trust settings.
        $context = $config['context'] ?? [];
        if (is_array($context)) {
            $ssl = $context['ssl'] ?? $context['stream'] ?? null;
            if ((isset($context['ssl'], $context['stream']) && $context['ssl'] !== $context['stream'])
                || (is_array($options['ssl'] ?? null) && $ssl !== null && $ssl !== $options['ssl'])) {
                throw new InvalidArgumentException('Redis cache TLS settings conflict; use one context.ssl configuration.');
            }
            if (is_array($options['ssl'] ?? null)) {
                $context['ssl'] = $options['ssl'];
                unset($config['ssl']);
            }
            $config['context'] = $context;
        }
        $identity = 'cache:' . hash('sha256', serialize([$options['prefix'], $options['persistent_id'] ?? '']));
        try {
            $client = \RC\Helper\Redis\Connector::open($config, $identity, \Redis::class, \RedisCluster::class);
        } catch (\InvalidArgumentException $error) {
            throw new InvalidArgumentException($error->getMessage(), 0, $error);
        }
        try {
            if (($client instanceof \Redis || $client instanceof \RedisCluster) && defined('Redis::OPT_COMPRESSION')
                && !$client->setOption(\Redis::OPT_COMPRESSION, \Redis::COMPRESSION_NONE)) {
                throw new RuntimeException('Redis cache compression option was rejected.');
            }
        } catch (\Throwable $error) {
            try { $client instanceof \Predis\Client ? $client->disconnect() : $client->close(); } catch (\Throwable) {}
            throw $error;
        }
        $this->handlerPid = $pid;
        return $this->handler = $client;
    }

    /** Keep the legacy read counter while reusing upstream decoding and default handling. */
    public function get($name, $default = null): mixed
    {
        $this->readTimes++;
        return parent::get($name, $default);
    }

    /** Store once and return the backend result; negative TTL removes the value. */
    public function set($name, $value, $expire = null): bool
    {
        $this->writeTimes++;
        $ttl = $this->getExpireTime($expire ?? $this->options['expire']);
        if ($ttl < 0) {
            return $this->delete($name);
        }
        $key = $this->getCacheKey($name);
        $payload = $this->serialize($value);
        $result = $ttl === 0 ? $this->handler()->set($key, $payload) : $this->handler()->setex($key, $ttl, $payload);
        return $result === true || (is_string($result) && $result === 'OK')
            || ($result instanceof \Stringable && (string) $result === 'OK');
    }

    /** Delete is successful even when the key was already absent. */
    public function delete($name): bool
    {
        $this->writeTimes++;
        return $this->handler()->del($this->getCacheKey($name)) !== false;
    }

    /** Atomically fetch and remove using one-key Lua, also compatible with older Redis servers. */
    public function pull($name, $default = null)
    {
        $this->readTimes++;
        $this->writeTimes++;
        $key = $this->getCacheKey($name);
        $script = "local v = redis.call('GET', KEYS[1]); if v then redis.call('DEL', KEYS[1]); end; return v";
        $client = $this->handler();
        $value = $client instanceof \Predis\Client ? $client->eval($script, 1, $key) : $client->eval($script, [$key], 1);
        if ($value === false || $value === null) {
            return $this->getDefaultValue($name, $default);
        }
        try {
            return $this->unserialize($value);
        } catch (InvalidCacheException) {
            return $this->getDefaultValue($name, $default);
        }
    }

    /** Compare one serialized string and replace/delete atomically, retaining the server's millisecond TTL. */
    public function compareAndSwap($name, string $expected, ?string $replacement): bool
    {
        $this->readTimes++;
        $this->writeTimes++;
        $key = $this->getCacheKey($name);
        $script = "if redis.call('GET', KEYS[1]) ~= ARGV[1] then return 0 end; "
            . "if ARGV[2] == 'delete' then return redis.call('DEL', KEYS[1]) end; "
            . "local ttl = redis.call('PTTL', KEYS[1]); if ttl == -2 or ttl == 0 then return 0 end; "
            . "if ttl > 0 then redis.call('SET', KEYS[1], ARGV[3], 'PX', ttl) else redis.call('SET', KEYS[1], ARGV[3]) end; return 1";
        $arguments = [$key, $this->serialize($expected), $replacement === null ? 'delete' : 'replace',
            $replacement === null ? '' : $this->serialize($replacement)];
        $client = $this->handler();
        $result = $client instanceof \Predis\Client ? $client->eval($script, 1, ...$arguments) : $client->eval($script, $arguments, 1);
        if ($result !== 0 && $result !== 1) { throw new RuntimeException('Redis conditional cache update did not return a confirmed result.'); }
        return $result === 1;
    }

    /** One-key Lua creates/replaces only the expected string and sets a fresh positive TTL in the same operation. */
    public function compareAndSwapWithTtl($name, ?string $expected, string $replacement, int $ttl): bool
    {
        if ($ttl < 1) { throw new InvalidArgumentException('Conditional cache TTL must be positive.'); }
        $this->readTimes++;
        $this->writeTimes++;
        $script = "local value = redis.call('GET', KEYS[1]); "
            . "if ARGV[1] == 'missing' then if value then return 0 end "
            . "elseif value ~= ARGV[2] then return 0 end; "
            . "redis.call('SET', KEYS[1], ARGV[3], 'EX', ARGV[4]); return 1";
        $arguments = [$this->getCacheKey($name), $expected === null ? 'missing' : 'value',
            $expected === null ? '' : $this->serialize($expected), $this->serialize($replacement), $ttl];
        $client = $this->handler();
        $result = $client instanceof \Predis\Client ? $client->eval($script, 1, ...$arguments) : $client->eval($script, $arguments, 1);
        if ($result !== 0 && $result !== 1) { throw new RuntimeException('Redis expiring conditional update did not return a confirmed result.'); }
        return $result === 1;
    }

    /** Keep counters atomic in Redis and reject silently truncated PHP steps. */
    public function inc($name, $step = 1, $expire = null)
    {
        if (!is_int($step)) {
            throw new InvalidArgumentException('Cache counter step must be an integer.');
        }
        $this->writeTimes++;
        if ($expire !== null) {
            $ttl = $this->getExpireTime($expire);
            if ($ttl <= 0) {
                throw new InvalidArgumentException('An initial counter TTL must be positive.');
            }
            $key = $this->getCacheKey($name);
            $script = "local found = redis.call('EXISTS', KEYS[1]); local n = redis.call('INCRBY', KEYS[1], ARGV[1]); "
                . "if found == 0 then redis.call('EXPIRE', KEYS[1], ARGV[2]); end; return n";
            $client = $this->handler();
            return $client instanceof \Predis\Client
                ? $client->eval($script, 1, $key, $step, $ttl)
                : $client->eval($script, [$key, $step, $ttl], 1);
        }
        return parent::inc($name, $step);
    }

    /** Decrement using the native single-key operation. */
    public function dec($name, $step = 1)
    {
        if (!is_int($step)) {
            throw new InvalidArgumentException('Cache counter step must be an integer.');
        }
        $this->writeTimes++;
        return parent::dec($name, $step);
    }

    /** Scan only this prefix; never issue FLUSHDB or blocking KEYS against a shared database. */
    public function clear(): bool
    {
        $this->writeTimes++;
        $prefix = $this->clearPrefix();
        $pattern = strtr($prefix, ['\\' => '\\\\', '*' => '\\*', '?' => '\\?', '[' => '\\[', ']' => '\\]']) . '*';
        $client = $this->handler();
        if ($client instanceof \RedisCluster) {
            foreach ($client->_masters() as $node) {
                $this->scanPhpRedis($client, $pattern, $node);
            }
        } elseif ($client instanceof \Redis) {
            $this->scanPhpRedis($client, $pattern);
        } else {
            $nodes = [$client];
            if (is_array($this->options['host'])) {
                $nodes = [];
                foreach ($client->getConnection() as $connection) {
                    $nodes[] = new \Predis\Client($connection);
                }
            }
            foreach ($nodes as $node) {
                $cursor = '0';
                do {
                    [$cursor, $keys] = $node->scan($cursor, ['match' => $pattern, 'count' => 256]);
                    $this->clearTag($keys);
                } while ((string) $cursor !== '0');
            }
        }
        return true;
    }

    /** Delete one key at a time so tags work across Redis Cluster hash slots. */
    public function clearTag($keys): void
    {
        $prefix = $this->clearPrefix();
        foreach ($keys as $key) {
            if (!is_string($key) || !str_starts_with($key, $prefix)) {
                throw new InvalidArgumentException('Cache tag contains a key outside this cache prefix.');
            }
            if ($this->handler()->del($key) === false) {
                throw new RuntimeException('Unable to delete tagged Redis cache value.');
            }
        }
    }

    /** Record membership in the native Redis set, preserving the backend failure signal. */
    public function append($name, $value): void
    {
        if ($this->handler()->sAdd($this->getCacheKey($name), $value) === false) {
            throw new RuntimeException('Unable to append Redis cache tag.');
        }
    }

    /** Global cleanup without a prefix has no safe ownership boundary. */
    private function clearPrefix(): string
    {
        $prefix = (string) $this->options['prefix'];
        if ($prefix === '') {
            throw new LogicException('Configure a non-empty Redis cache prefix before clearing a store or tag.');
        }
        return $prefix;
    }

    /** Iterate one standalone server or cluster master with a bounded SCAN batch. */
    private function scanPhpRedis(object $client, string $pattern, ?array $node = null): void
    {
        $cursor = null;
        do {
            $previous = $cursor;
            $client->clearLastError();
            $keys = $node === null ? $client->scan($cursor, $pattern, 256) : $client->scan($cursor, $node, $pattern, 256);
            if ($keys === false && ($client->getLastError() !== null || $cursor === $previous)) {
                throw new RuntimeException('Redis cache scan failed; no further keys will be deleted.');
            }
            if (is_array($keys)) {
                $this->clearTag($keys);
            }
        } while ($cursor !== 0);
    }
}
