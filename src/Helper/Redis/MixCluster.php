<?php

namespace RC\Helper\Redis;

/** Native RedisCluster-backed legacy Mix API; node-directed commands keep native routing arguments. */
class MixCluster extends mix\Redis
{
    /** Root helper supplies full cluster configuration, including credentials for discovered nodes. */
    public function __construct(array $config)
    {
        $this->configure(['type' => 'cluster'] + $config);
        $this->driver = new MixClusterDriver($this->configuration);
    }

    /** Cluster SCAN requires an explicit node; it does not pretend to scan the entire cluster in one cursor. */
    public function scan(&$iterator, $node = null, $pattern = null, $count = 0)
    {
        if ($node === null) { throw new \InvalidArgumentException('Cluster SCAN requires a native node/key routing argument.'); }
        return $this->borrow()->__call('scan', [&$iterator, $node, $pattern, $count]);
    }

    /** phpredis Cluster has no pipeline mode; fail before handing off or closing a working session. */
    public function pipeline(): mix\Pipeline
    {
        throw new \LogicException('Native RedisCluster does not support pipelines; use MULTI or standalone Redis.');
    }
}
