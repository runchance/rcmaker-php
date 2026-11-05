<?php

namespace RC\Helper\Db\mix\ObjectPool;

require_once dirname(__DIR__) . '/bootstrap.php';

/** Historical Swoole pool only; this adapter does not introduce Workerman coroutines. */
abstract class AbstractObjectPool extends \Mix\ObjectPool\AbstractObjectPool
{
    /** Validate before construction so unavailable pool startup cannot consume a synchronous driver. */
    public function __construct(DialerInterface $dialer, int $maxOpen = -1, int $maxIdle = -1, int $maxLifetime = 0, float $waitTimeout = 0.0)
    {
        if ($maxOpen < -1 || $maxIdle < -1 || $maxIdle === 0 || $maxLifetime < 0 || !is_finite($waitTimeout) || $waitTimeout < 0) {
            throw new \InvalidArgumentException('Invalid Mix pool size, lifetime or wait timeout.');
        }
        if (!class_exists(\Swoole\Coroutine\Channel::class) || !class_exists(\Swoole\Coroutine::class)
            || \Swoole\Coroutine::getCid() < 0) {
            throw new \LogicException('Mix pools require the legacy Swoole coroutine runtime; use synchronous connections in Workerman.');
        }
        parent::__construct($dialer, $maxOpen, $maxIdle, $maxLifetime, $waitTimeout);
    }

    /** Preserve the legacy RC exception while keeping upstream timeout behavior. */
    protected function pop(): object
    {
        try { return parent::pop(); }
        catch (\Mix\ObjectPool\Exception\WaitTimeoutException $error) {
            throw new \RC\Helper\Db\mix\ObjectPool\Exception\WaitTimeoutException($error->getMessage(), 0, $error);
        }
    }
}
