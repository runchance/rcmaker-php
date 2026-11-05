<?php

declare(strict_types=1);

namespace RC\Helper\Cache\think;

use RC\Helper\Cache\think\cache\CacheItem;
use think\cache\Driver;

require_once dirname(__DIR__) . '/bootstrap.php';

/** Stable upstream manager with RC driver selection and legacy CacheItem helpers. */
class CacheManager extends \think\CacheManager
{
    protected array $deferred = [];

    /** Replace configuration and discard cached driver selections. */
    public function init(array $config = []): static
    {
        $this->config = $config;
        $this->instance = [];
        return $this;
    }

    /** Resolve RC adapters, while continuing to accept a fully qualified custom driver. */
    public function connect(array $options, string $name = ''): Driver
    {
        if ($name !== '' && isset($this->instance[$name])) {
            return $this->instance[$name];
        }
        $type = !empty($options['type']) ? $options['type'] : 'File';
        $handler = Container::factory($type, 'RC\\Helper\\Cache\\think\\cache\\driver\\', $options);
        if ($name !== '') {
            $this->instance[$name] = $handler;
        }
        return $handler;
    }

    /** Fetch a fresh item rather than keeping stale values across worker requests. */
    public function getItem($key): CacheItem
    {
        $item = new CacheItem($key);
        $missing = new \stdClass();
        $value = $this->get($key, $missing);
        if ($value !== $missing) {
            $item->set($value);
        }
        return $item;
    }

    /** Return the legacy list of cache items for the supplied keys. */
    public function getItems(array $keys = []): array
    {
        return array_map($this->getItem(...), $keys);
    }

    /** Test the backing store without using a process-local item cache. */
    public function hasItem($key): bool
    {
        return $this->has($key);
    }

    /** Remove one legacy item from the default store. */
    public function deleteItem($key): bool
    {
        return $this->delete($key);
    }

    /** Propagate failures from deleting a group of legacy items. */
    public function deleteItems(array $keys): bool
    {
        return $this->deleteMultiple($keys);
    }

    /** Persist a legacy item, including the valid key "0" and optional tag. */
    public function save($item): bool
    {
        if ($item->getKey() === null || $item->getKey() === '') {
            return false;
        }
        $store = $this->store();
        if ($item->getTag() !== null) {
            $store = $store->tag($item->getTag());
        }
        return $store->set($item->getKey(), $item->get(), $item->getExpire());
    }

    /** Queue an item for an explicit commit; avoid using this as request-local worker state. */
    public function saveDeferred($item): bool
    {
        $this->deferred[$item->getKey()] = $item;
        return true;
    }

    /** Flush queued items and retain any failed entry for an explicit retry. */
    public function commit(): bool
    {
        foreach ($this->deferred as $key => $item) {
            if (!$this->save($item)) {
                return false;
            }
            unset($this->deferred[$key]);
        }
        return true;
    }

    /** Preserve the old best-effort final flush; callers should commit before leaving their request. */
    public function __destruct()
    {
        if ($this->deferred !== []) {
            $this->commit();
        }
    }
}
