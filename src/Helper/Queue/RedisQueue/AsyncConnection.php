<?php

declare(strict_types=1);

namespace RC\Helper\Queue\RedisQueue;

require_once __DIR__ . '/bootstrap.php';

/** Callback-only upstream connection: authenticate every socket and never replay an ambiguous write. */
class AsyncConnection extends \Workerman\Redis\Client
{
    private bool $closed = false;
    private bool $disconnecting = false;
    private bool $connected = false;
    private array $setup;

    /** Use through Transport; options contain only normalized authentication, database and timeouts. */
    public function __construct(string $address, array $options)
    {
        $this->setup = $options;
        try {
            parent::__construct($address, $options, static function (): void {});
        } catch (\Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    /** Prepend ACL/SELECT to each new connection before any application command can be dispatched. */
    public function connect()
    {
        if ($this->closed || $this->_connection !== null) {
            return;
        }
        $this->connected = false;
        parent::connect();
        if ($this->_connection === null) {
            return;
        }
        // This is role-local: never raise the global HTTP/other-connection frame limit.
        $this->_connection->maxPackageSize = $this->setup['max_response_bytes'];
        $onConnect = $this->_connection->onConnect;
        $this->_connection->onConnect = function (...$args) use ($onConnect): void {
            $this->connected = true;
            $commands = [];
            $auth = $this->setup['auth'];
            if ($auth !== '') {
                $commands[] = is_array($auth) ? ['AUTH', ...$auth] : ['AUTH', $auth];
            }
            if ($this->setup['db'] !== 0) {
                $commands[] = ['SELECT', $this->setup['db']];
            }
            $entries = [];
            foreach ($commands as $command) {
                $entries[] = [$command, time(), function ($result): void {
                    if ($result === false) {
                        $this->closeConnection();
                    }
                }];
            }
            $this->_queue = array_merge($entries, $this->_queue);
            $onConnect(...$args);
        };
    }

    /** Queue callbacks only; RC does not opt its queue facade into Workerman coroutine behavior. */
    protected function inCoroutineMode(): bool
    {
        return false;
    }

    /** Only an initial TCP failure proves no command was sent; an established socket loss is ambiguous. */
    public function failedBeforeConnect(): bool
    {
        return !$this->connected;
    }

    /** Internal drain metric; includes the currently executing command and authentication setup. */
    public function pendingCount(): int
    {
        return count($this->_queue);
    }

    /** Internal ownership marker; callers must not modify or close this socket directly. */
    public function socket(): ?\Workerman\Connection\AsyncTcpConnection
    {
        return $this->_connection;
    }

    /** Start a fresh socket after an explicit earlier failure; never resurrect a closed client. */
    protected function queueCommand(array $args, $cb = null, $format = null)
    {
        if ($this->closed) {
            throw new \LogicException('Queue Redis connection is closed.');
        }
        if (!$this->disconnecting && $this->_connection === null) {
            $this->connect();
        }
        return parent::queueCommand($args, $cb, $format);
    }

    /** Fail pending callbacks once on disconnect rather than allowing upstream reconnect to replay writes. */
    public function closeConnection()
    {
        if ($this->disconnecting) {
            return;
        }
        $this->disconnecting = true;
        $pending = $this->_queue;
        $this->_queue = [];
        $this->_waiting = true;
        $this->_error = $this->_error ?: 'Redis connection closed before reply; command outcome is unknown.';
        parent::closeConnection();
        try {
            foreach ($pending as $entry) {
                if ($entry[2]) {
                    try {
                        ($entry[2])(false, $this);
                    } catch (\Throwable $error) {
                        // One failing user callback must not strand the remaining pending operations.
                        error_log('Queue Redis disconnect callback failed: ' . $error->getMessage());
                    }
                }
            }
        } finally {
            $this->disconnecting = false;
            if ($this->_queue !== []) {
                // Only commands newly submitted by failure callbacks remain; never replay the old snapshot.
                if ($this->closed) {
                    $this->closeConnection();
                } elseif ($this->_connection === null) {
                    $this->connect();
                }
            }
        }
    }

    /** Cancel this connection and its own upstream timeout/reconnect timers only. */
    public function close()
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->_quitting = true;
        // Upstream close() clears _queue even during disconnect callbacks; RC must notify those new commands first.
        $this->closeConnection();
        // Upstream closeConnection() returns early with no socket; a reconnect timer may still exist.
        foreach (['_connectTimeoutTimer', '_reconnectTimer', '_waitTimeoutTimer'] as $property) {
            if ($this->$property !== null) {
                \Workerman\Timer::del($this->$property);
                $this->$property = null;
            }
        }
    }
}
