<?php

namespace RC\Helper\Redis;

/** Legacy class name with the same validated native connection factory as standalone Mix Redis. */
class MixClusterDriver extends mix\Driver
{
    /** Cluster credentials/TLS/prefix settings survive reconnects rather than relying on a one-off constructor. */
    public function __construct(array $config)
    {
        parent::__construct('', 6379, '', 0, 5.0, 0, null, ['type' => 'cluster'] + $config);
    }
}
