<?php

declare(strict_types=1);

namespace RC\Helper\Queue\RedisQueue;

/** Private queue transport: upstream async sockets in Workerman, isolated synchronous producers elsewhere. */
final class Transport
{
    private array $clients = [];
    private array $askingClients = [];
    private ?object $syncClient = null;
    private string $owner;
    private bool $closed = false;

    /** One instance belongs to one queue connection and one role (send or consume). */
    public function __construct(
        private readonly ConnectionSettings $settings,
        public readonly bool $async,
        private readonly string $routingKey,
    ) {
        $this->owner = $settings->addresses[0];
    }

    /** Dispatch once; retry only a server-confirmed MOVED/ASK, never an uncertain socket failure. */
    public function command(array $arguments, callable $callback): void
    {
        if ($this->closed) {
            throw new \LogicException('Queue Redis transport is closed.');
        }
        if ($this->async) {
            $this->dispatch($this->owner, $arguments, $callback, 0);
            return;
        }
        $error = null;
        try {
            $client = $this->synchronous();
            if ($client instanceof \RedisCluster) {
                $client->clearLastError();
                $result = $client->rawCommand($this->routingKey, ...$arguments);
            } elseif ($client instanceof \Redis) {
                $client->clearLastError();
                $result = $client->rawCommand(...$arguments);
            } else {
                $result = $client->executeRaw($arguments, $isError);
                if ($isError) {
                    throw new \RuntimeException((string) $result);
                }
            }
            if ($result === false) {
                $error = method_exists($client, 'getLastError') ? $client->getLastError() : null;
                if (!$error && strtoupper($arguments[0]) === 'RPOP') {
                    $result = null;
                } else {
                    $error = $error ?: 'Queue Redis command failed.';
                }
            }
        } catch (\Throwable $failure) {
            $result = false;
            $error = $failure->getMessage();
        }
        $callback($result, $error);
    }

    /** Use native RESP/command handling unchanged; cluster routing adds only bounded explicit redirections. */
    private function dispatch(string $address, array $arguments, callable $callback, int $redirects, bool $asking = false, array $tried = []): void
    {
        try {
            if ($this->closed) {
                throw new \LogicException('Queue Redis transport is closed.');
            }
            if (!$asking && !isset($this->clients[$address]) && count($this->clients) >= 32) {
                throw new \RuntimeException('Queue Redis cluster endpoint limit reached; recreate the queue connection.');
            }
            $client = $asking ? new AsyncConnection($address, $this->settings->options)
                : ($this->clients[$address] ??= new AsyncConnection($address, $this->settings->options));
            if ($asking) {
                $this->askingClients[spl_object_id($client)] = $client;
                $client->rawCommand('ASKING', static function (): void {});
            }
            $tried[] = $address;
            $client->rawCommand(...[...$arguments, function ($result, $native) use ($address, $arguments, $callback, $redirects, $asking, $client, $tried): void {
                $error = $result === false ? ($native->error() ?: 'Queue Redis command failed.') : null;
                if ($asking) {
                    unset($this->askingClients[spl_object_id($client)]);
                    $client->close();
                }
                if (!$this->closed && $this->settings->cluster && $error !== null && $redirects < 5
                    && preg_match('/^(MOVED|ASK) [0-9]+ ([^\s]+)$/D', $error, $matches)) {
                    try {
                        $target = ConnectionSettings::endpoint($matches[2]);
                    } catch (\InvalidArgumentException $invalid) {
                        $callback(false, $invalid->getMessage());
                        return;
                    }
                    if ($matches[1] === 'MOVED') {
                        $this->owner = $target;
                    }
                    $this->dispatch($target, $arguments, $callback, $redirects + 1, $matches[1] === 'ASK', $tried);
                    return;
                }
                if (!$this->closed && $this->settings->cluster && $error !== null && $redirects < 5
                    && $native->failedBeforeConnect()) {
                    foreach ($this->settings->addresses as $seed) {
                        if (!in_array($seed, $tried, true)) {
                            unset($this->clients[$address]);
                            $native->close();
                            $this->owner = $seed;
                            $this->dispatch($seed, $arguments, $callback, $redirects + 1, false, $tried);
                            return;
                        }
                    }
                }
                $callback($result, $error);
            }]);
        } catch (\Throwable $failure) {
            $callback(false, $failure->getMessage());
        }
    }

    /** Open a nonpersistent, no-prefix/no-serializer producer independent of the global Redis bootstrap. */
    private function synchronous(): object
    {
        if ($this->syncClient !== null) {
            return $this->syncClient;
        }
        $options = $this->settings->options;
        $auth = $options['auth'];
        if (extension_loaded('redis') && $options['client'] !== 'predis') {
            if ($this->settings->cluster) {
                $hosts = array_map(static fn(string $uri): string => substr($uri, strlen('redis://')), $this->settings->addresses);
                if ($options['ssl']) {
                    $hosts = array_map(static fn(string $host): string => 'tls://' . $host, $hosts);
                }
                $client = new \RedisCluster(null, $hosts, $options['connect_timeout'], $options['wait_timeout'], false,
                    $auth === '' ? null : $auth, $options['ssl'] ? ($options['context']['ssl'] ?? []) : null);
            } else {
                $uri = str_starts_with($this->owner, 'unix:///')
                    ? ['scheme' => 'unix', 'path' => substr($this->owner, 7)] : parse_url($this->owner);
                $host = $uri['scheme'] === 'unix' ? $uri['path'] : ($options['ssl'] ? 'tls://' : '') . trim($uri['host'], '[]');
                $client = new \Redis();
                if (!$client->connect($host, $uri['port'] ?? 0, $options['connect_timeout'], null, 0,
                    $options['wait_timeout'], $options['ssl'] ? ['stream' => $options['context']['ssl'] ?? []] : null)) {
                    throw new \RuntimeException('Unable to connect to queue Redis.');
                }
                if ($auth !== '' && !$client->auth($auth)) {
                    throw new \RuntimeException('Queue Redis authentication failed.');
                }
                if (!$client->select($options['db'])) {
                    throw new \RuntimeException('Unable to select queue Redis database.');
                }
            }
        } elseif (class_exists(\Predis\Client::class)) {
            $parameters = [
                'timeout' => $options['connect_timeout'], 'read_write_timeout' => $options['wait_timeout'],
                'database' => $options['db'],
                'scheme' => $options['ssl'] ? 'tls' : 'tcp',
                'retry' => new \Predis\Retry\Retry(new \Predis\Retry\Strategy\NoBackoff(), 0),
            ];
            if ($options['ssl']) {
                $parameters['ssl'] = $options['context']['ssl'] ?? [];
            }
            if ($auth !== '') {
                if (is_array($auth)) {
                    [$parameters['username'], $parameters['password']] = $auth;
                } else {
                    $parameters['password'] = $auth;
                }
            }
            $hosts = array_map(static function (string $uri) use ($options): array {
                $parts = str_starts_with($uri, 'unix:///')
                    ? ['scheme' => 'unix', 'path' => substr($uri, 7)] : parse_url($uri);
                return ['scheme' => $parts['scheme'] === 'unix' ? 'unix' : ($options['ssl'] ? 'tls' : 'tcp')]
                    + array_intersect_key($parts, array_flip(['host', 'port', 'path']))
                    + ($options['context']['ssl'] ?? [] ? ['ssl' => $options['context']['ssl']] : []);
            }, $this->settings->addresses);
            $client = $this->settings->cluster
                ? new \Predis\Client($hosts, ['cluster' => 'redis', 'parameters' => $parameters,
                    'connections' => new \RC\Helper\Redis\PredisFactory($parameters)])
                : new \Predis\Client($hosts[0] + $parameters, ['connections' => new \RC\Helper\Redis\PredisFactory()]);
            if ($this->settings->cluster) {
                // Aggregate connect() opens EVERY seed, so one refused seed would abort healthy clusters.
                // Topology reads may try another seed; application writes use the explicit zero-retry policy.
                $client->getConnection()->askSlotMap();
            } else {
                $client->connect();
            }
        } else {
            throw new \RuntimeException('Synchronous queue producers require ext-redis or stable predis/predis.');
        }
        return $this->syncClient = $client;
    }

    /** Internal shutdown metric; synchronous commands cannot remain in flight after their call returns. */
    public function pendingCount(): int
    {
        $count = 0;
        foreach ([...$this->clients, ...$this->askingClients] as $client) {
            $count += $client->pendingCount();
        }
        return $count;
    }

    /** Internal ownership list for shutdown; includes temporary ASKING connections. */
    public function sockets(): array
    {
        $sockets = [];
        foreach ([...$this->clients, ...$this->askingClients] as $client) {
            if (($socket = $client->socket()) !== null) {
                $sockets[] = $socket;
            }
        }
        return $sockets;
    }

    /** Close only sockets owned by this role, not another connection or application Redis client. */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        foreach ($this->clients as $client) {
            $client->close();
        }
        foreach ($this->askingClients as $client) {
            $client->close();
        }
        $this->askingClients = [];
        $this->clients = [];
        if ($this->syncClient instanceof \Redis || $this->syncClient instanceof \RedisCluster) {
            $this->syncClient->close();
        } elseif ($this->syncClient !== null) {
            $this->syncClient->disconnect();
        }
        $this->syncClient = null;
    }
}
