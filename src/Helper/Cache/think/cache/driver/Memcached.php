<?php

declare(strict_types=1);

namespace RC\Helper\Cache\think\cache\driver;

use RC\Helper\Cache\MemcacheStore;

/** Native ext-memcached transport; configure type=memcached through the RC cache manager. */
class Memcached extends MemcacheStore
{
    /** Configure the native client while keeping key prefixes and serialization owned by RC. */
    public function __construct(array $options = [])
    {
        if (!extension_loaded('memcached')) {
            throw new \BadFunctionCallException('The memcached cache driver requires stable ext-memcached for PHP 8.5.');
        }
        $this->configure($options);
        $this->handler = $this->createHandler();
    }

    /** Configure a fresh native client after master-side release; retain existing non-persistent pool semantics. */
    protected function createHandler(): object
    {
        $handler = new \Memcached();
        if ($this->options['option'] && !$handler->setOptions($this->options['option'])) {
            throw new \InvalidArgumentException('Invalid Memcached client options.');
        }
        $required = [\Memcached::OPT_PREFIX_KEY => '', \Memcached::OPT_COMPRESSION => false,
            \Memcached::OPT_BUFFER_WRITES => false, \Memcached::OPT_NOREPLY => false];
        if ($this->options['timeout'] > 0) {
            $required[\Memcached::OPT_CONNECT_TIMEOUT] = (int) $this->options['timeout'];
        }
        if (!$handler->setOptions($required) || !$handler->addServers($this->servers())) {
            throw new \RuntimeException('Cannot configure Memcached servers.');
        }
        if ($this->options['username'] !== '') {
            if (!$handler->setOption(\Memcached::OPT_BINARY_PROTOCOL, true)
                || !$handler->setSaslAuthData($this->options['username'], $this->options['password'])) {
                throw new \RuntimeException('Cannot configure Memcached SASL credentials.');
            }
        }
        return $handler;
    }

    /** Throw for transport/protocol errors; do not collapse outages into cache misses. */
    private function checkResult(array $allowed): int
    {
        $code = $this->handler->getResultCode();
        if (!in_array($code, $allowed, true)) {
            throw new \RuntimeException('Memcached operation failed: ' . $this->handler->getResultMessage());
        }
        return $code;
    }

    /** Fetch values with one multi-get per server, preserving missing-key behavior. */
    protected function fetchMany(array $keys): array
    {
        $values = $this->handler()->getMulti($keys);
        $this->checkResult([\Memcached::RES_SUCCESS, \Memcached::RES_NOTFOUND]);
        return $values === false ? [] : $values;
    }

    /** Use GET_EXTENDED multi-get for both values and CAS tokens in one request. */
    protected function fetchCasMany(array $keys): array
    {
        $entries = $this->handler()->getMulti($keys, \Memcached::GET_EXTENDED);
        $this->checkResult([\Memcached::RES_SUCCESS, \Memcached::RES_NOTFOUND]);
        if ($entries === false) {
            return [];
        }
        foreach ($entries as $entry) {
            if (!is_array($entry) || empty($entry['cas'])) {
                throw new \RuntimeException('Memcached server CAS support must be enabled.');
            }
        }
        return $entries;
    }

    /** Retry only normal CAS/add conflicts; payload and connection failures are actionable errors. */
    protected function storeRaw(string $key, string $value, int $expires, string $mode, mixed $cas = null): bool
    {
        $handler = $this->handler();
        $result = $mode === 'cas'
            ? $handler->cas($cas, $key, $value, $expires)
            : $handler->{$mode}($key, $value, $expires);
        $allowed = [\Memcached::RES_SUCCESS];
        if ($mode !== 'set') {
            $allowed = [...$allowed, \Memcached::RES_DATA_EXISTS, \Memcached::RES_NOTSTORED, \Memcached::RES_NOTFOUND];
        }
        $this->checkResult($allowed);
        return $result;
    }

    /** Report successful deletion even if the key has already expired. */
    protected function deleteRaw(string $key): bool
    {
        $this->handler()->delete($key);
        $this->checkResult([\Memcached::RES_SUCCESS, \Memcached::RES_NOTFOUND]);
        return true;
    }
}
