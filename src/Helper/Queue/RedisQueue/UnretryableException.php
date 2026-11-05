<?php

namespace RC\Helper\Queue\RedisQueue;

require_once __DIR__ . '/bootstrap.php';

/** Throw from handle() for permanent failures; an actual class also works with authoritative Composer maps. */
class UnretryableException extends \Workerman\RedisQueue\UnretryableException
{
}
