<?php

declare(strict_types=1);

namespace RC\Helper\Queue\RedisQueue;

use Workerman\Connection\ConnectionInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;
use Workerman\Worker;

/** Process-local shutdown registry; its only polling timer exists while a worker is draining. */
final class Lifecycle
{
    private static ?\WeakMap $clients = null;
    private static ?\WeakReference $loop = null;
    private static ?int $pid = null;
    private static ?int $timer = null;
    private static bool $stopping = false;
    private static bool $finished = false;

    /** Track direct and facade async clients without retaining otherwise unused objects. */
    public static function register(RedisClient $client): void
    {
        $pid = getmypid();
        $loop = Worker::$globalEvent;
        if (self::$pid !== $pid) {
            // A fresh worker has its own event loop; never delete an inherited numeric timer ID there.
            self::$clients = new \WeakMap();
            self::$timer = null;
            self::$loop = null;
            self::$stopping = self::$finished = false;
            self::$pid = $pid;
        }
        if (self::$loop?->get() !== $loop) {
            if (count(self::$clients) !== 0) {
                throw new \LogicException('Close queue clients before replacing the Workerman event loop.');
            }
            self::$loop = \WeakReference::create($loop);
            self::$stopping = self::$finished = false;
        }
        // First queue use may occur inside an active request after the lazy stop hook has already run.
        if (Worker::getStatus() === Worker::STATUS_SHUTDOWN) {
            if (!Worker::getGracefulStop()) {
                throw new \LogicException('Cannot open an async queue client during a forced worker stop.');
            }
            self::$stopping = true;
        }
        if (self::$finished) {
            throw new \LogicException('Queue shutdown has completed; no new async client can be opened.');
        }
        self::$clients[$client] = true;
        if (self::$stopping) {
            $client->prepareShutdown();
            // An active request may close the last old client and then create a new producer.
            self::armTimer();
        }
    }

    /** Explicit close removes its registration and only the registry's own obsolete drain timer. */
    public static function forget(RedisClient $client): void
    {
        if (self::$pid !== getmypid() || self::$clients === null) {
            return;
        }
        unset(self::$clients[$client]);
        if (count(self::$clients) === 0) {
            self::cancelTimer();
        }
    }

    /** Called after user onWorkerStop. Force stops abort; graceful stops preserve active foreign I/O. */
    public static function shutdown(bool $graceful): void
    {
        if (self::$pid !== getmypid() || self::$clients === null || count(self::$clients) === 0) {
            return;
        }
        self::$stopping = true;
        if (!$graceful) {
            self::finish();
            return;
        }
        foreach (self::$clients as $client => $_) {
            $client->prepareShutdown();
        }
        self::drain();
        self::armTimer();
    }

    /** Restart draining for late producers too, without ever adding a steady-state timer. */
    private static function armTimer(): void
    {
        if (!self::$finished && self::$timer === null && count(self::$clients) !== 0) {
            try {
                self::$timer = Timer::add(0.01, self::drain(...));
            } catch (\Throwable $error) {
                error_log('Queue shutdown timer failed; aborting remaining queue operations: ' . $error->getMessage());
                self::finish();
            }
        }
    }

    /** Wait for every non-queue connection and all confirmed queue replies before closing idle sockets. */
    private static function drain(): void
    {
        if (self::$finished || self::$clients === null || count(self::$clients) === 0) {
            self::cancelTimer();
            return;
        }
        $owned = [];
        foreach (self::$clients as $client => $_) {
            if (!$client->isIdle()) {
                return;
            }
            foreach ($client->sockets() as $socket) {
                $owned[spl_object_id($socket)] = true;
            }
        }
        // Intersect with the actual live map: a stale closed socket must not hide a foreign connection.
        $liveOwned = 0;
        foreach (TcpConnection::$connections as $socket) {
            if (isset($owned[spl_object_id($socket)])) {
                ++$liveOwned;
            }
        }
        if (ConnectionInterface::$statistics['connection_count'] > $liveOwned) {
            return;
        }
        self::finish();
    }

    /** Reject new async clients before closing, so destructor/cancellation callbacks cannot reopen the pool. */
    private static function finish(): void
    {
        self::$finished = true;
        self::cancelTimer();
        $clients = [];
        foreach (self::$clients as $client => $_) {
            $clients[] = $client;
        }
        foreach ($clients as $client) {
            $client->close();
        }
    }

    /** Never clear the event loop or foreign timers. */
    private static function cancelTimer(): void
    {
        if (self::$timer !== null) {
            $timer = self::$timer;
            self::$timer = null;
            Timer::del($timer);
        }
    }
}
