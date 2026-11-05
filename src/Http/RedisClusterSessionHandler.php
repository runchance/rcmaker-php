<?php

declare(strict_types=1);

namespace RC\Http;

/** Redis Cluster session storage using the shared RC lifecycle adapter and the native cluster client. */
class RedisClusterSessionHandler extends RedisSessionHandler
{
    /** Keep legacy host seed arrays, while avoiding obsolete Workerman 4.x dynamic properties. */
    protected function isCluster(): bool { return true; }
}
