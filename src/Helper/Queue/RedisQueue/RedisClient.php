<?php
/**
 * Queue API derived from Workerman Redis Queue, copyright walkor <walkor@workerman.net>.
 * Licensed under the MIT License; see LICENSE in this directory.
 */

namespace RC\Helper\Queue\RedisQueue;

require_once __DIR__ . '/bootstrap.php';

/** RC queue facade. Use queue()->send(); consumer processes subscribe via QueueConsumer. */
class RedisClient extends \Workerman\RedisQueue\Client
{
    private ConnectionSettings $settings;
    private int $ownerPid;
    private ?string $driver;
    private bool $async;
    private bool $closed = false;
    private bool $quiescing = false;
    private bool $pulling = false;
    private bool $promoting = false;
    private ?int $pullTimer = null;
    private int $generation = 0;
    private int $pendingSends = 0;
    private int $pendingSendBytes = 0;

    // Read incrementally so 128 large members cannot become one unbounded RESP/PHP allocation.
    // A single legacy member may exceed the batch budget; returning it alone avoids permanent starvation.
    private const DUE_BATCH = <<<'LUA'
local result = {}
local bytes = 0
local budget = tonumber(ARGV[3])
for offset = 0, tonumber(ARGV[2]) - 1 do
    local member = redis.call('ZRANGEBYSCORE', KEYS[1], '-inf', ARGV[1], 'LIMIT', offset, 1)[1]
    if not member then break end
    local size = string.len(member)
    if #result > 0 and bytes + size > budget then break end
    result[#result + 1] = member
    bytes = bytes + size
    if bytes >= budget then break end
end
return result
LUA;

    // Push before removing: a wrong destination type must leave the delayed message intact.
    private const PROMOTE = <<<'LUA'
local score = redis.call('ZSCORE', KEYS[1], ARGV[1])
if not score or tonumber(score) > tonumber(ARGV[2]) then return 0 end
-- Lua errors do not roll back earlier writes; check the later write before LPUSH.
if redis.acl_check_cmd and not redis.acl_check_cmd('ZREM', KEYS[1], ARGV[1]) then
    return redis.error_reply('NOPERM queue promotion requires ZREM permission')
end
redis.call('LPUSH', KEYS[2], ARGV[1])
redis.call('ZREM', KEYS[1], ARGV[1])
return 1
LUA;

    /** Preserve RC connection arrays; null is a producer-only client, not a half-initialized object. */
    public function __construct($address, $options = [], $type = 'workerman')
    {
        $this->ownerPid = getmypid();
        if (!is_array($options) || !in_array($type, ['workerman', 'swoole', null], true)) {
            throw new \InvalidArgumentException('Invalid queue options or timer driver.');
        }
        $this->settings = new ConnectionSettings($address, $options);
        $this->_options = array_merge($this->_options, $options);
        foreach (['retry_seconds', 'max_attempts'] as $key) {
            $this->_options[$key] = ConnectionSettings::integer($this->_options[$key], $key);
        }
        foreach (['max_pending_sends' => 1024, 'max_pending_send_bytes' => 16 * 1024 * 1024,
            'max_message_bytes' => 512 * 1024 * 1024] as $key => $default) {
            $this->_options[$key] = ConnectionSettings::integer($options[$key] ?? $default, $key);
            if ($this->_options[$key] === 0) {
                throw new \InvalidArgumentException('Queue ' . $key . ' must be positive.');
            }
        }
        $this->_options['delayed_batch_bytes'] = ConnectionSettings::integer($options['delayed_batch_bytes']
            ?? min(8 * 1024 * 1024, max(1, intdiv($this->settings->options['max_response_bytes'], 2))), 'delayed_batch_bytes');
        if ($this->_options['delayed_batch_bytes'] === 0
            || $this->_options['delayed_batch_bytes'] >= $this->settings->options['max_response_bytes']) {
            throw new \InvalidArgumentException('Queue delayed_batch_bytes must be positive and below max_response_bytes.');
        }
        if (!is_string($this->_options['prefix'])) {
            throw new \InvalidArgumentException('Queue prefix must be a string.');
        }
        if ($this->settings->cluster) {
            $tags = [];
            foreach ([static::QUEUE_WAITING . 'a', static::QUEUE_WAITING . 'b', static::QUEUE_DELAYED, static::QUEUE_FAILED] as $suffix) {
                $key = $this->_options['prefix'] . $suffix;
                $begin = strpos($key, '{');
                $end = $begin === false ? false : strpos($key, '}', $begin + 1);
                if ($begin === false || $end === false || $end === $begin + 1) {
                    throw new \InvalidArgumentException('Cluster queue keys require a consistent nonempty Redis hash tag.');
                }
                $tags[substr($key, $begin + 1, $end - $begin - 1)] = true;
            }
            if (count($tags) !== 1) {
                throw new \InvalidArgumentException('Cluster queue keys must share one Redis hash tag.');
            }
        }
        $this->driver = $type;
        $this->async = $type === 'workerman' && class_exists(\Workerman\Worker::class)
            && \Workerman\Worker::$globalEvent !== null;
        $this->_redisSend = $this->transport();
        if ($this->async) {
            Lifecycle::register($this);
        }
    }

    /** Send immediately/delayed; optional callback receives confirmed success, otherwise sync failures throw. */
    public function send($queue, $data, $delay = 0, $cb = null)
    {
        $this->assertOpen();
        if (is_callable($delay)) {
            $cb = $delay;
            $delay = 0;
        }
        if (!is_string($queue) || ($cb !== null && !is_callable($cb))) {
            throw new \InvalidArgumentException('Queue name must be a string and send callback must be callable.');
        }
        $delay = ConnectionSettings::integer($delay, 'delay');
        if ($delay > PHP_INT_MAX - time()) {
            throw new \InvalidArgumentException('Queue delay overflows its timestamp.');
        }
        $package = Package::create($queue, $data, $delay);
        if ($this->async && ($this->pendingSends >= $this->_options['max_pending_sends']
            || $this->pendingSends > 0 && $this->pendingSendBytes >= $this->_options['max_pending_send_bytes'])) {
            $this->rejectPendingSend($cb);
            return;
        }
        $json = Package::encode($package);
        // JsonSerializable may submit another job or close this client while the envelope is encoded.
        $this->assertOpen();
        if ($this->async && $this->pendingSends >= $this->_options['max_pending_sends']) {
            $this->rejectPendingSend($cb);
            return;
        }
        if (strlen($json) > $this->_options['max_message_bytes']) {
            throw new \LengthException('Queue encoded message exceeds max_message_bytes.');
        }
        $key = $this->key($delay === 0 ? static::QUEUE_WAITING . $queue : static::QUEUE_DELAYED);
        $arguments = $delay === 0 ? ['LPUSH', $key, $json] : ['ZADD', $key, $package['time'] + $delay, $json];
        if (!$this->async) {
            // A synchronous call cannot leave an outbox behind; keep its original short completion path.
            $this->_redisSend->command($arguments, static function ($result, $error) use ($cb): void {
                if ($cb !== null) { $cb($result !== false); }
                elseif ($result === false) { throw new \RuntimeException($error); }
            });
            return;
        }
        $bytes = strlen($json) + strlen($key);
        // Permit one larger legacy job alone, never an unbounded backlog of such jobs.
        if ($this->pendingSends > 0 && $bytes > $this->_options['max_pending_send_bytes'] - $this->pendingSendBytes) {
            $this->rejectPendingSend($cb);
            return;
        }
        ++$this->pendingSends;
        $this->pendingSendBytes += $bytes;
        $settled = false;
        $complete = function ($result, $error) use ($cb, $bytes, &$settled): void {
            if ($settled) { return; }
            $settled = true;
            // Release before user callbacks so a callback may safely produce the next message.
            --$this->pendingSends;
            $this->pendingSendBytes -= $bytes;
            if ($cb !== null) {
                $cb($result !== false);
            } elseif ($result === false) {
                if (!$this->closed) {
                    $this->log('Queue send failed: ' . $error);
                }
            }
        };
        try {
            $this->_redisSend->command($arguments, $complete);
        } catch (\Throwable $error) {
            if (!$settled) {
                $settled = true;
                --$this->pendingSends;
                $this->pendingSendBytes -= $bytes;
            }
            throw $error;
        }
    }

    /** Local backpressure is immediately observable; no per-rejected-message error-log flood. */
    private function rejectPendingSend(?callable $callback): void
    {
        if ($callback !== null) {
            $callback(false);
            return;
        }
        throw new \OverflowException('Queue producer backpressure: pending send budget reached.');
    }

    /** Register one or more queues; consumption needs a live timer loop, never a blocking BRPOP fallback. */
    public function subscribe($queue, callable $callback)
    {
        $this->assertOpen();
        if ($this->quiescing) {
            throw new \LogicException('Queue consumption is shutting down.');
        }
        if (!$this->async && !($this->driver === 'swoole' && class_exists(\Swoole\Timer::class))) {
            throw new \LogicException('Queue consumption requires a running Workerman event loop or the existing Swoole timer runtime.');
        }
        $queues = $this->queueKeys($queue);
        foreach ($queues as $key) {
            $this->_subscribeQueues[$key] = $callback;
        }
        try {
            $this->_redisSubscribe ??= $this->transport();
            $this->tryToPullDelayQueue();
            $this->pull();
        } catch (\Throwable $error) {
            $this->unsubscribe(array_map(fn(string $key): string => substr($key, strlen($this->key(static::QUEUE_WAITING))), $queues));
            throw $error;
        }
    }

    /** Remove subscriptions; let a pending pop return so an already removed message can be put back. */
    public function unsubscribe($queue)
    {
        foreach ($this->queueKeys($queue) as $key) {
            unset($this->_subscribeQueues[$key]);
        }
        if ($this->_subscribeQueues !== []) {
            return;
        }
        $this->cancel($this->pullTimer);
        $this->cancel($this->_retryTimerId);
        if (!$this->pulling) {
            $this->closeReceiver();
        }
    }

    /** Install exactly one delayed scan timer per queue connection, not a process-global static timer. */
    protected function tryToPullDelayQueue()
    {
        if ($this->_retryTimerId === null) {
            $this->_retryTimerId = $this->schedule(1.0, true, fn() => $this->promoteDelayed());
        }
    }

    /** Use a nonblocking upstream BRPOP socket; the retiring Swoole bridge polls a nonblocking RPOP. */
    public function pull()
    {
        if ($this->closed || $this->pulling || $this->_subscribeQueues === []) {
            return;
        }
        $this->pulling = true;
        $generation = $this->generation;
        $keys = array_keys($this->_subscribeQueues);
        if ($this->async) {
            $arguments = ['BRPOP', ...$keys, 1];
        } else {
            // Rotate keys so a busy first queue cannot starve the other configured consumers.
            $first = array_key_first($this->_subscribeQueues);
            $handler = $this->_subscribeQueues[$first];
            unset($this->_subscribeQueues[$first]);
            $this->_subscribeQueues[$first] = $handler;
            $arguments = ['RPOP', $first];
        }
        $this->_redisSubscribe->command($arguments, function ($result, $error) use ($generation, $arguments): void {
            if ($this->closed || $generation !== $this->generation) {
                return;
            }
            $hasJob = $result !== null && $result !== [] && $result !== false;
            $done = function () use ($generation, $error, $hasJob): void {
                $this->pulling = false;
                if (!$this->closed && $generation === $this->generation && $this->_subscribeQueues !== []) {
                    $delay = $error !== null ? 1.0 : ($this->async ? 0.000001 : ($hasJob ? 0.001 : 0.05));
                    $this->pullTimer = $this->schedule($delay, false, function (): void {
                        $this->pullTimer = null;
                        $this->pull();
                    });
                } elseif ($this->_subscribeQueues === []) {
                    $this->closeReceiver();
                }
            };
            if ($error !== null) {
                $this->log('Queue consume failed: ' . $error);
                $done();
            } elseif ($result === null || $result === []) {
                $done();
            } else {
                $this->consume($this->async ? $result : [$arguments[1], $result], $done);
            }
        });
    }

    /** Validate pulled envelopes before invoking user code; acknowledge retry/failure writes before next pull. */
    private function consume(mixed $reply, callable $done): void
    {
        if (!is_array($reply) || count($reply) !== 2 || !is_string($reply[0]) || !is_string($reply[1])) {
            $this->log('Queue received an invalid Redis pop reply.');
            $done();
            return;
        }
        [$key, $raw] = $reply;
        try {
            $package = Package::decode($raw);
            if ($key !== $this->key(static::QUEUE_WAITING . $package['queue'])) {
                throw new \UnexpectedValueException('Queue message name does not match its Redis key.');
            }
        } catch (\Throwable $invalid) {
            $this->write(['LPUSH', $this->key(static::QUEUE_FAILED), $raw], $done);
            return;
        }
        if (!isset($this->_subscribeQueues[$key])) {
            $this->write(['RPUSH', $key, $raw], $done);
            return;
        }
        try {
            ($this->_subscribeQueues[$key])($package['data']);
        } catch (\Throwable $error) {
            $this->log((string) $error);
            $package['max_attempts'] = $this->_options['max_attempts'];
            $package['error'] = json_decode(json_encode($error->getMessage(), JSON_INVALID_UTF8_SUBSTITUTE), true);
            if ($error instanceof \Workerman\RedisQueue\UnretryableException) {
                $this->storeFailure($package, $done);
                return;
            }
            $fallback = null;
            if ($this->_consumeFailure !== null) {
                try {
                    // A by-reference callback must not corrupt the fallback envelope or its identity.
                    $context = $package;
                    $modified = ($this->_consumeFailure)($error, $context);
                    if (is_array($modified)) {
                        $candidate = array_replace($package, array_intersect_key($modified, array_flip(['data', 'attempts', 'max_attempts', 'error'])));
                        $candidate['attempts'] = ConnectionSettings::integer($candidate['attempts'], 'attempts');
                        $candidate['max_attempts'] = ConnectionSettings::integer($candidate['max_attempts'], 'max_attempts');
                        if (!is_string($candidate['error'])) {
                            throw new \InvalidArgumentException('Queue failure hook error must be a string.');
                        }
                        $fallback = $package;
                        $package = $candidate;
                    }
                } catch (\Throwable $hookError) {
                    $this->log('Queue failure hook ignored: ' . $hookError->getMessage());
                }
            }
            // Encode the final hook result once; if that fails, retry only the untouched local envelope.
            while (true) {
                $due = null;
                if ($package['attempts'] >= PHP_INT_MAX - 1) {
                    $package['error'] .= ' (retry attempts limit reached)';
                } elseif (++$package['attempts'] <= $package['max_attempts']) {
                    $delay = $this->_options['retry_seconds'] * $package['attempts'];
                    $now = time();
                    if (!is_int($delay) || $delay > PHP_INT_MAX - $now) {
                        $package['error'] .= ' (retry timestamp overflow)';
                    } else {
                        $due = $now + $delay;
                    }
                }
                try {
                    $json = Package::encode($package);
                    break;
                } catch (\Throwable $encodeError) {
                    if ($fallback === null) { throw $encodeError; }
                    $this->log('Queue failure hook ignored: ' . $encodeError->getMessage());
                    $package = $fallback;
                    $fallback = null;
                }
            }
            $this->write($due === null ? ['LPUSH', $this->key(static::QUEUE_FAILED), $json]
                : ['ZADD', $this->key(static::QUEUE_DELAYED), $due, $json], $done);
            return;
        }
        $done();
    }

    /** Fetch at most 128 members within the byte budget, or one larger member; moving remains atomic. */
    private function promoteDelayed(): void
    {
        if ($this->closed || $this->promoting || $this->_subscribeQueues === []) {
            return;
        }
        $this->promoting = true;
        $this->_redisSend->command(['EVAL', self::DUE_BATCH, 1, $this->key(static::QUEUE_DELAYED), time(), 128,
            $this->_options['delayed_batch_bytes']],
            function ($items, $error): void {
                if (!is_array($items) || $items === [] || $this->closed) {
                    $this->promoting = false;
                    if ($error !== null && !$this->closed) {
                        $this->log('Queue delayed scan failed: ' . $error);
                    }
                    return;
                }
                $remaining = count($items);
                foreach ($items as $raw) {
                    try {
                        $package = Package::decode($raw);
                        $destination = $this->key(static::QUEUE_WAITING . $package['queue']);
                    } catch (\Throwable $invalid) {
                        $destination = $this->key(static::QUEUE_FAILED);
                    }
                    $this->write(['EVAL', self::PROMOTE, 2, $this->key(static::QUEUE_DELAYED), $destination, $raw, time()],
                        function () use (&$remaining): void {
                            if (--$remaining === 0) {
                                $this->promoting = false;
                            }
                        });
                }
            });
    }

    /** Compatibility wrapper for direct subclass calls; internal completion tracking is RC-owned. */
    protected function retry($package)
    {
        $this->write(['ZADD', $this->key(static::QUEUE_DELAYED), time() + $this->_options['retry_seconds'] * $package['attempts'], Package::encode($package)]);
    }

    /** Store the same native failure envelope and key format. */
    protected function fail($package)
    {
        $this->storeFailure($package, static function (): void {});
    }

    /** Persist a terminal failure and then resume consumption. */
    private function storeFailure(array $package, callable $done): void
    {
        $this->write(['LPUSH', $this->key(static::QUEUE_FAILED), Package::encode($package)], $done);
    }

    /** Observe every internal write failure; do not report a retry as stored before Redis confirms it. */
    private function write(array $arguments, ?callable $done = null): void
    {
        if ($this->closed) {
            $done && $done();
            return;
        }
        $this->_redisSend->command($arguments, function ($result, $error) use ($done): void {
            if ($result === false && !$this->closed) {
                $this->log('Queue storage failed; message outcome requires inspection: ' . $error);
            }
            $done && $done();
        });
    }

    /** Validate all names before mutating the subscription map. */
    private function queueKeys(mixed $queue): array
    {
        $queues = is_array($queue) ? $queue : [$queue];
        if ($queues === []) {
            throw new \InvalidArgumentException('At least one queue name is required.');
        }
        return array_map(function ($name): string {
            if (!is_string($name)) {
                throw new \InvalidArgumentException('Queue name must be a string.');
            }
            Package::queue($name);
            return $this->key(static::QUEUE_WAITING . $name);
        }, $queues);
    }

    /** Construct a lazy, role-local transport with the cluster queue slot as routing key. */
    private function transport(): Transport
    {
        return new Transport($this->settings, $this->async, $this->key(static::QUEUE_DELAYED));
    }

    /** Prefix exactly once; Redis clients themselves use no implicit prefix. */
    private function key(string $suffix): string
    {
        return $this->_options['prefix'] . $suffix;
    }

    /** Bridge existing timer drivers only; no Workerman coroutine adapter is introduced. */
    private function schedule(float $delay, bool $repeat, callable $callback): int
    {
        if ($this->driver === 'swoole') {
            $method = $repeat ? 'tick' : 'after';
            return \Swoole\Timer::$method(max(1, (int) ceil($delay * 1000)), $callback);
        }
        return \Workerman\Timer::add($delay, $callback, [], $repeat);
    }

    /** Delete only the supplied owned timer and clear its handle before callback reentry. */
    private function cancel(?int &$id): void
    {
        if ($id === null) {
            return;
        }
        $timer = $id;
        $id = null;
        if ($this->driver === 'swoole') {
            \Swoole\Timer::clear($timer);
        } else {
            \Workerman\Timer::del($timer);
        }
    }

    /** Safe logger boundary: a broken logger must not strand the consumer after removing a job. */
    protected function log($message)
    {
        try {
            parent::log($message);
        } catch (\Throwable $error) {
            error_log('Queue logger failed: ' . $error->getMessage());
        }
    }

    /** Reject operations after close; Client::connection() can create a fresh instance. */
    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new \LogicException('Queue client is closed.');
        }
    }

    /** Expose lifecycle state to the per-process facade cache. */
    public function isClosed(): bool
    {
        return $this->closed;
    }

    /** Stop taking new jobs but keep producer writes available to already active application requests. */
    public function prepareShutdown(): void
    {
        $this->quiescing = true;
        $queues = array_map(fn(string $key): string => substr($key, strlen($this->key(static::QUEUE_WAITING))), array_keys($this->_subscribeQueues));
        if ($queues !== []) {
            $this->unsubscribe($queues);
        }
    }

    /** Internal drain check; pulled-job requeue and retry/failure storage must also have completed. */
    public function isIdle(): bool
    {
        return !$this->pulling && !$this->promoting && $this->pendingSends === 0 && $this->_redisSend->pendingCount() === 0
            && ($this->_redisSubscribe?->pendingCount() ?? 0) === 0;
    }

    /** Internal socket ownership across send and consume roles. */
    public function sockets(): array
    {
        return [...$this->_redisSend->sockets(), ...($this->_redisSubscribe?->sockets() ?? [])];
    }

    /** Only close after the outstanding pop/requeue has settled, unless explicit close() aborts the client. */
    private function closeReceiver(): void
    {
        $this->_redisSubscribe?->close();
        $this->_redisSubscribe = null;
    }

    /** Explicit immediate abort; normal RC worker shutdown uses deferred Lifecycle draining instead. */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        ++$this->generation;
        $this->_subscribeQueues = [];
        $this->cancel($this->pullTimer);
        $this->cancel($this->_retryTimerId);
        $this->pulling = false;
        try {
            $this->closeReceiver();
            $this->_redisSend->close();
        } finally {
            if ($this->async) {
                Lifecycle::forget($this);
            }
        }
    }

    /** Release an unreferenced producer after its pending callbacks settle; never cancel inherited timers after fork. */
    public function __destruct()
    {
        if ($this->closed || !isset($this->ownerPid) || $this->ownerPid !== getmypid()) {
            return;
        }
        try { $this->close(); }
        catch (\Throwable) { /* Destruction must not replace a business exception or fail process shutdown. */ }
    }

    /** A clone would alias live transports; mark the rejected copy inert so its destructor cannot close the original. */
    public function __clone(): void
    {
        $this->closed = true;
        throw new \LogicException('Queue clients cannot be cloned; create a new configured connection.');
    }

    /** Persist configuration/job data, never a process-local queue transport graph. */
    public function __serialize(): array
    {
        throw new \LogicException('Queue clients cannot be serialized.');
    }

    /** Reject constructor bypass without activating cleanup on an uninitialized instance. */
    public function __unserialize(array $data): void
    {
        $this->closed = true;
        throw new \LogicException('Queue clients cannot be unserialized.');
    }
}
