<?php

declare(strict_types=1);

namespace RC\Helper\Queue\RedisQueue;

/** Normalize the existing queue connection array once, without changing global redis() state. */
final class ConnectionSettings
{
    // A BRPOP reply contains a key and value, each up to Redis' default 512 MiB, plus RESP framing.
    public const DEFAULT_MAX_RESPONSE_BYTES = 1073807360;

    public readonly array $addresses;
    public readonly bool $cluster;
    public readonly array $options;

    /** Accept the documented RC array or a Redis URI; queue auth/db explicitly override connection settings. */
    public function __construct(array|string $address, array $queueOptions)
    {
        if (is_string($address)) {
            $uri = str_starts_with($address, 'unix:///')
                ? ['scheme' => 'unix', 'path' => substr($address, 7)] : parse_url($address);
            if ($uri === false || !isset($uri['scheme'])) {
                throw new \InvalidArgumentException('Queue Redis address must be a URI or a connection array.');
            }
            if (isset($uri['query']) || isset($uri['fragment'])) {
                throw new \InvalidArgumentException('Queue Redis URI query/fragment options are not supported; use the connection array.');
            }
            $address = $uri + ['host' => '127.0.0.1', 'port' => 6379];
            $address['password'] = isset($uri['pass']) ? rawurldecode($uri['pass']) : '';
            $address['username'] = isset($uri['user']) ? rawurldecode($uri['user']) : '';
            $database = isset($uri['path']) && $uri['scheme'] !== 'unix' ? ltrim($uri['path'], '/') : '';
            $address['database'] = $database !== '' ? $database : 0;
        }
        $kind = $address['type'] ?? 'redis';
        if (!in_array($kind, ['redis', 'common', 'cluster', 'redisCluster'], true)) {
            throw new \InvalidArgumentException('Unsupported queue Redis connection type.');
        }
        $this->cluster = in_array($kind, ['cluster', 'redisCluster'], true) || is_array($address['host'] ?? null);
        $db = $queueOptions['db'] ?? $address['database'] ?? $address['db'] ?? 0;
        $db = self::integer($db, 'database');
        if ($this->cluster && $db !== 0) {
            throw new \InvalidArgumentException('Redis Cluster queues support database 0 only.');
        }
        $auth = $queueOptions['auth'] ?? $address['password'] ?? '';
        if ($auth === null) {
            $auth = '';
        }
        if (!is_array($auth) && !is_string($auth)) {
            throw new \InvalidArgumentException('Queue Redis credentials must be a password or [username, password].');
        }
        if (!is_array($auth) && ($address['username'] ?? '') !== '') {
            $auth = [$address['username'], $auth];
        }
        if (is_array($auth) && (count($auth) !== 2 || !array_is_list($auth) || !is_string($auth[0]) || !is_string($auth[1]))) {
            throw new \InvalidArgumentException('Queue Redis ACL credentials require [username, password].');
        }
        $timeout = $address['timeout'] ?? 5;
        $readTimeout = $address['readTimeout'] ?? $address['read_timeout'] ?? $timeout;
        foreach ([$timeout, $readTimeout] as $value) {
            if (!is_numeric($value) || !is_finite((float) $value) || (float) $value <= 0) {
                throw new \InvalidArgumentException('Queue Redis timeouts must be positive and finite.');
            }
        }
        $maxResponse = self::integer($queueOptions['max_response_bytes'] ?? $address['max_response_bytes']
            ?? self::DEFAULT_MAX_RESPONSE_BYTES, 'max_response_bytes');
        if ($maxResponse === 0) {
            throw new \InvalidArgumentException('Queue max_response_bytes must be positive.');
        }
        $scheme = $address['scheme'] ?? 'redis';
        if (!in_array($scheme, ['redis', 'tcp', 'rediss', 'tls', 'unix'], true)) {
            throw new \InvalidArgumentException('Unsupported queue Redis URI scheme.');
        }
        $hosts = $this->cluster ? ($address['host'] ?? []) : [$address['host'] ?? '127.0.0.1'];
        if (!is_array($hosts) || $hosts === []) {
            throw new \InvalidArgumentException('Queue Redis requires at least one host.');
        }
        $addresses = [];
        foreach ($hosts as $host) {
            if (!is_string($host) || $host === '' || preg_match('/[\x00-\x20]/', $host)) {
                throw new \InvalidArgumentException('Invalid queue Redis host.');
            }
            if ($scheme === 'unix') {
                if ($this->cluster || !isset($address['path']) || !str_starts_with($address['path'], '/')) {
                    throw new \InvalidArgumentException('Unix queue Redis connections require an absolute socket path.');
                }
                $addresses[] = 'unix://' . $address['path'];
            } elseif ($this->cluster) {
                $addresses[] = self::endpoint($host);
            } else {
                $port = self::integer($address['port'] ?? 6379, 'port');
                if ($port < 1 || $port > 65535) {
                    throw new \InvalidArgumentException('Invalid queue Redis port.');
                }
                $addresses[] = 'redis://' . (str_contains($host, ':') && $host[0] !== '[' ? '[' . $host . ']' : $host) . ':' . $port;
            }
        }
        $this->addresses = array_values(array_unique($addresses));
        $this->options = [
            'auth' => $auth, 'db' => $db, 'connect_timeout' => (float) $timeout,
            'wait_timeout' => (float) $readTimeout,
            'max_response_bytes' => $maxResponse,
            'ssl' => in_array($scheme, ['rediss', 'tls'], true) || ($address['ssl'] ?? false) === true,
            'context' => $address['context'] ?? [], 'client' => $address['client'] ?? 'phpredis',
        ];
        if (!is_array($this->options['context']) || isset($this->options['context']['ssl']) && !is_array($this->options['context']['ssl'])
            || !in_array($this->options['client'], ['phpredis', 'predis'], true)) {
            throw new \InvalidArgumentException('Invalid queue Redis context or synchronous client selection.');
        }
    }

    /** Validate a MOVED/ASK destination without accepting a new URI, credentials or control characters. */
    public static function endpoint(string $address): string
    {
        if (preg_match('/[\x00-\x20]/', $address)) {
            throw new \InvalidArgumentException('Invalid queue Redis cluster endpoint.');
        }
        $parts = parse_url('redis://' . $address);
        if ($parts === false || !isset($parts['host'], $parts['port']) || $parts['port'] < 1
            || array_intersect_key($parts, array_flip(['user', 'pass', 'path', 'query', 'fragment'])) !== []) {
            throw new \InvalidArgumentException('Invalid queue Redis cluster endpoint.');
        }
        $host = $parts['host'];
        if (str_contains($host, ':')) {
            $host = trim($host, '[]');
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new \InvalidArgumentException('Invalid queue Redis cluster IPv6 address.');
            }
            $host = '[' . $host . ']';
        } elseif (!preg_match('/^[a-zA-Z0-9_.-]+$/D', $host)) {
            throw new \InvalidArgumentException('Invalid queue Redis cluster hostname.');
        }
        return 'redis://' . $host . ':' . $parts['port'];
    }

    /** Reject fractional/negative/overflow values instead of silently casting configuration. */
    public static function integer(mixed $value, string $name): int
    {
        if ((!is_int($value) && !is_string($value)) || filter_var($value, FILTER_VALIDATE_INT) === false
            || (int) $value < 0 || (int) $value === PHP_INT_MAX) {
            throw new \InvalidArgumentException('Queue ' . $name . ' must be a non-negative integer below PHP_INT_MAX.');
        }
        return (int) $value;
    }
}
