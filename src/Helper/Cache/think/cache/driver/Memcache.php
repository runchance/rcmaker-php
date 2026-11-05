<?php

declare(strict_types=1);

namespace RC\Helper\Cache\think\cache\driver;

use RC\Helper\Cache\MemcacheStore;

/** Native ext-memcache transport; configure type=memcache through the RC cache manager. */
class Memcache extends MemcacheStore
{
    /** Create a server pool. The legacy timeout option is milliseconds, converted for ext-memcache. */
    public function __construct(array $options = [])
    {
        if (!extension_loaded('memcache') || !method_exists(\Memcache::class, 'cas')) {
            throw new \BadFunctionCallException('The memcache cache driver requires stable ext-memcache 8.2 or newer with CAS.');
        }
        $this->configure($options);
        $this->handler = $this->createHandler();
    }

    /** Build a configured native pool; addServer remains lazy and no data command is sent here. */
    protected function createHandler(): object
    {
        $handler = new \Memcache();
        $timeout = $this->options['timeout'] > 0 ? $this->options['timeout'] / 1000 : 1.0;
        foreach ($this->servers() as [$host, $port, $weight]) {
            if (!$handler->addServer($host, $port, (bool) $this->options['persistent'], $weight, $timeout)) {
                throw new \RuntimeException('Cannot configure Memcache server.');
            }
        }
        // Automatic compression amplifies CAS contention on growing tag arrays.
        $handler->setCompressThreshold(0);
        $handler->setFailureCallback(static function (): never {
            throw new \RuntimeException('Memcache server is unavailable.');
        });
        if ($this->options['username'] !== '') {
            if (!$handler->setSaslAuthData($this->options['username'], $this->options['password'])) {
                throw new \RuntimeException('Cannot configure Memcache SASL credentials.');
            }
        }
        return $handler;
    }

    /** Fetch multiple physical keys in one request per server. */
    protected function fetchMany(array $keys): array
    {
        $values = $this->handler()->get($keys);
        return $values === false ? [] : $values;
    }

    /** Fetch values and per-key CAS tokens together, avoiding a separate metadata round trip. */
    protected function fetchCasMany(array $keys): array
    {
        $flags = $cas = null;
        $values = $this->handler()->get($keys, $flags, $cas);
        if ($values === false) {
            return [];
        }
        $entries = [];
        foreach ($values as $key => $value) {
            if (empty($cas[$key])) {
                throw new \RuntimeException('Memcache server CAS support must be enabled.');
            }
            $entries[$key] = ['value' => $value, 'cas' => $cas[$key]];
        }
        return $entries;
    }

    /** All RC data is sent as strings with no PHP-object serialization flag. */
    protected function storeRaw(string $key, string $value, int $expires, string $mode, mixed $cas = null): bool
    {
        $handler = $this->handler();
        return $mode === 'cas'
            ? $handler->cas($key, $value, 0, $expires, $cas)
            : $handler->{$mode}($key, $value, 0, $expires);
    }

    /** ext-memcache lacks result codes; connection failures throw through its failure callback. */
    protected function deleteRaw(string $key): bool
    {
        $this->handler()->delete($key);
        return true;
    }
}
