<?php

namespace RC\Helper\Cache\think;

require_once dirname(__DIR__) . '/bootstrap.php';

/** Upstream DI support with a cache-local singleton, isolated from optional ThinkORM. */
class Container extends \think\Container
{
    protected static $instance;
}
