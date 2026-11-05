<?php

declare(strict_types=1);

namespace RC\Helper\Redis;

/** Creates native Redis clients; redis() owns caching and never publishes a partially configured connection. */
final class Connector
{
    /** Internal factory; credentials, TLS and prefixes are configured once, without background timers. */
    public static function open(array $config, string $identity, string $class, ?string $clusterClass): object
    {
        $options = self::settings($config);
        if (extension_loaded('redis') && $options['client'] !== 'predis') {
            $client = null;
            try {
                if ($options['cluster']) {
                    if ($clusterClass === null || !is_a($clusterClass, \RedisCluster::class, true)) {
                        throw new \InvalidArgumentException('Raw Redis cluster class must extend RedisCluster.');
                    }
                    $client = new $clusterClass(null, $options['seeds'], $options['timeout'], $options['read_timeout'],
                        $options['persistent'], $options['auth'] === '' ? null : $options['auth'],
                        $options['tls'] ? $options['ssl'] : null);
                } else {
                    if (!is_a($class, \Redis::class, true)) {
                        throw new \InvalidArgumentException('Raw Redis class must extend Redis.');
                    }
                    $client = new $class();
                    $persistentId = 'rc-redis-' . hash('sha256', serialize([$identity, getmypid(), $options]));
                    $method = $options['persistent'] ? 'pconnect' : 'connect';
                    if (!$client->$method($options['host'], $options['port'], $options['timeout'],
                        $options['persistent'] ? $persistentId : null, $options['retry_interval'],
                        $options['read_timeout'], $options['tls'] ? ['stream' => $options['ssl']] : null)) {
                        throw new \RuntimeException('Raw Redis connection failed.');
                    }
                    if ($options['auth'] !== '' && !$client->auth($options['auth'])) {
                        throw new \RuntimeException('Raw Redis authentication failed.');
                    }
                    if (!$client->select($options['database'])) {
                        throw new \RuntimeException('Raw Redis database selection failed.');
                    }
                }
                foreach ([\Redis::OPT_PREFIX => $options['prefix'], \Redis::OPT_SERIALIZER => \Redis::SERIALIZER_NONE,
                    \Redis::OPT_MAX_RETRIES => 0] as $option => $value) {
                    if (!$client->setOption($option, $value)) {
                        throw new \RuntimeException('Raw Redis connection option was rejected.');
                    }
                }
                return $client;
            } catch (\Throwable $error) {
                if ($client !== null) { try { $client->close(); } catch (\Throwable) {} }
                throw $error;
            }
        }
        if (!class_exists(\Predis\Client::class)) {
            throw new \RuntimeException('Raw Redis requires ext-redis or the stable predis/predis Composer package.');
        }
        if ($options['unix'] && $options['persistent']) {
            throw new \InvalidArgumentException('Predis Unix sockets require persistent=false; isolated persistent connection IDs are not supported.');
        }
        $parameters = ['timeout' => $options['timeout'], 'read_write_timeout' => $options['read_timeout'],
            'scheme' => $options['unix'] ? 'unix' : ($options['tls'] ? 'tls' : 'tcp'),
            'database' => $options['database'], 'persistent' => $options['persistent']
                ? 'rc-redis-' . hash('sha256', serialize([$identity, getmypid(), $options])) : false,
            'retry' => new \Predis\Retry\Retry(new \Predis\Retry\Strategy\NoBackoff(), 0)];
        if ($options['auth'] !== '') {
            if (is_array($options['auth'])) { [$parameters['username'], $parameters['password']] = $options['auth']; }
            else { $parameters['password'] = $options['auth']; }
        }
        if ($options['tls']) { $parameters['ssl'] = $options['ssl']; }
        $clientOptions = ['exceptions' => true, 'prefix' => $options['prefix'], 'connections' => new PredisFactory($parameters)];
        if ($options['cluster']) {
            $clientOptions += ['cluster' => 'redis', 'parameters' => $parameters];
            $seeds = array_map(static fn(string $seed): string => $options['tls'] ? $seed : 'tcp://' . $seed, $options['seeds']);
            $client = new \Predis\Client($seeds, $clientOptions);
        } else {
            $parameters += $options['unix'] ? ['scheme' => 'unix', 'path' => $options['host']]
                : ['scheme' => $options['tls'] ? 'tls' : 'tcp', 'host' => $options['plain_host'], 'port' => $options['port']];
            $client = new \Predis\Client($parameters, $clientOptions);
        }
        try {
            // Aggregate connect() opens every seed. Only read-only topology discovery may try another seed.
            if ($options['cluster']) { $client->getConnection()->askSlotMap(); }
            else { $client->connect(); }
            return $client;
        } catch (\Throwable $error) {
            try { $client->disconnect(); } catch (\Throwable) {}
            throw $error;
        }
    }

    /** Validate before opening sockets; retain legacy readTimeout/retryInterval and ACL/password-zero values. */
    private static function settings(array $config): array
    {
        $type = $config['type'] ?? 'common';
        if (!in_array($type, ['common', 'redis', 'cluster', 'redisCluster'], true)) {
            throw new \InvalidArgumentException('Unsupported raw Redis connection type.');
        }
        $host = $config['host'] ?? '127.0.0.1';
        $cluster = is_array($host) || in_array($type, ['cluster', 'redisCluster'], true);
        if (is_string($host) && preg_match('/[\x00-\x1f\x7f]/', $host)) {
            throw new \InvalidArgumentException('Raw Redis host cannot contain control characters.');
        }
        if ($cluster && !isset($config['scheme']) && is_array($host) && isset($host[0]) && is_string($host[0])
            && preg_match('~^(?:tls|rediss)://~', $host[0])) {
            $config['scheme'] = 'tls';
        }
        // Native phpredis configurations have historically accepted tls:// hosts and Unix paths.
        if (!$cluster && is_string($host) && str_contains($host, '://')) {
            $uri = str_starts_with($host, 'unix:///') ? ['scheme' => 'unix', 'path' => substr($host, 7)] : parse_url($host);
            if ($uri === false || !isset($uri['scheme'])
                || array_intersect_key($uri, array_flip(['user', 'pass', 'query', 'fragment'])) !== []
                || isset($uri['path']) && $uri['scheme'] !== 'unix'
                || isset($config['scheme']) && $config['scheme'] !== $uri['scheme']) {
                throw new \InvalidArgumentException('Invalid raw Redis host URI; configure credentials and database separately.');
            }
            $config['scheme'] = $uri['scheme'];
            $host = $uri['host'] ?? $uri['path'] ?? '';
            if (isset($uri['port'])) { $config['port'] = $uri['port']; }
        } elseif (!$cluster && is_string($host) && str_starts_with($host, '/')) {
            $config['scheme'] ??= 'unix';
        }
        $database = self::integer($config['database'] ?? 0, 'database');
        $port = self::integer($config['port'] ?? 6379, 'port');
        if ($cluster && $database !== 0) { throw new \InvalidArgumentException('Redis Cluster supports database 0 only.'); }
        $timeout = $config['timeout'] ?? 5;
        $readTimeout = $config['read_timeout'] ?? $config['readTimeout'] ?? $timeout;
        if (!is_numeric($timeout) || !is_finite((float) $timeout) || (float) $timeout <= 0
            || !is_numeric($readTimeout) || !is_finite((float) $readTimeout) || (float) $readTimeout < -1) {
            throw new \InvalidArgumentException('Raw Redis requires a positive finite connect timeout and read timeout >= -1.');
        }
        $auth = $config['password'] ?? '';
        if (!is_array($auth) && ($config['username'] ?? '') !== '') { $auth = [$config['username'], $auth]; }
        if ((!is_array($auth) && !is_string($auth)) || is_array($auth)
            && (!array_is_list($auth) || count($auth) !== 2 || !is_string($auth[0]) || !is_string($auth[1]))) {
            throw new \InvalidArgumentException('Raw Redis credentials require a password or [username, password].');
        }
        $prefix = $config['prefix'] ?? '';
        $client = $config['client'] ?? 'auto';
        $scheme = $config['scheme'] ?? 'tcp';
        $context = $config['context'] ?? [];
        $persistent = filter_var($config['persistent'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if (!is_string($prefix) || !in_array($client, ['auto', 'phpredis', 'predis'], true)
            || !in_array($scheme, ['tcp', 'redis', 'tls', 'rediss', 'unix'], true) || !is_array($context)
            || $persistent === null || !is_array($context['ssl'] ?? $context['stream'] ?? [])) {
            throw new \InvalidArgumentException('Invalid raw Redis prefix, client, scheme, persistent or TLS configuration.');
        }
        $tls = in_array($scheme, ['tls', 'rediss'], true) || ($config['ssl'] ?? false) === true;
        $ssl = $context['ssl'] ?? $context['stream'] ?? [];
        $unix = $scheme === 'unix';
        if ($unix && $tls) { throw new \InvalidArgumentException('Redis Unix sockets do not use TLS.'); }
        $seeds = [];
        if ($cluster) {
            if (!is_array($host) || $host === [] || $unix) { throw new \InvalidArgumentException('Redis Cluster requires a nonempty seed array.'); }
            foreach ($host as $seed) {
                if (!is_string($seed) || preg_match('/[\x00-\x20]/', $seed)) { throw new \InvalidArgumentException('Invalid Redis seed.'); }
                $parts = parse_url(str_contains($seed, '://') ? $seed : ($tls ? 'tls://' : 'tcp://') . $seed);
                if ($parts === false || !isset($parts['host'], $parts['port']) || $parts['port'] < 1 || $parts['port'] > 65535
                    || array_intersect_key($parts, array_flip(['user', 'pass', 'path', 'query', 'fragment'])) !== []
                    || !in_array($parts['scheme'], $tls ? ['tls', 'rediss'] : ['tcp', 'redis'], true)) {
                    throw new \InvalidArgumentException('Redis seeds must use host:port and the configured TLS mode.');
                }
                $seeds[] = ($tls ? 'tls://' : '') . $parts['host'] . ':' . $parts['port'];
            }
            $seeds = array_values(array_unique($seeds));
        } else {
            if (!is_string($host) || $host === '' || preg_match($unix ? '/[\x00-\x1f\x7f]/' : '/[\x00-\x20\x7f]/', $host)
                || str_contains($host, '://')) {
                throw new \InvalidArgumentException('Use a plain raw Redis host and the separate scheme option.');
            }
            if ($unix) {
                if (!str_starts_with($host, '/')) { throw new \InvalidArgumentException('Redis Unix socket path must be absolute.'); }
                $port = 0;
            } elseif ($port < 1 || $port > 65535) { throw new \InvalidArgumentException('Invalid Redis port.'); }
        }
        $plainHost = $cluster ? '' : trim($host, '[]');
        if (!$cluster && !$unix && (strpbrk($plainHost, '/?#@') !== false
            || str_contains($plainHost, ':') && filter_var($plainHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false)) {
            throw new \InvalidArgumentException('Invalid raw Redis hostname or IPv6 address.');
        }
        if ($tls && !$cluster) { $host = 'tls://' . (str_contains($plainHost, ':') ? '[' . $plainHost . ']' : $plainHost); }
        return ['cluster' => $cluster, 'seeds' => $seeds, 'database' => $database, 'host' => $host,
            'plain_host' => $plainHost, 'port' => $port, 'timeout' => (float) $timeout, 'read_timeout' => (float) $readTimeout,
            'retry_interval' => self::integer($config['retryInterval'] ?? $config['retry_interval'] ?? 0, 'retryInterval'),
            'auth' => $auth, 'prefix' => $prefix, 'client' => $client, 'tls' => $tls, 'ssl' => $ssl,
            'unix' => $unix, 'persistent' => $persistent, 'persistent_id' => $config['persistent_id'] ?? $config['select'] ?? ''];
    }

    /** Reject booleans/fractional/negative configuration rather than silently truncating IDs or ports. */
    private static function integer(mixed $value, string $name): int
    {
        if ((!is_string($value) && !is_int($value)) || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0) {
            throw new \InvalidArgumentException('Raw Redis ' . $name . ' must be a nonnegative integer.');
        }
        return (int) $value;
    }
}
