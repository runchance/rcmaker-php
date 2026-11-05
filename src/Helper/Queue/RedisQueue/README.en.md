## Redis Queue

[中文](README.md)

Keep using `queue()->send('mail', $data, $delay)` and configure consumers in `config/queue.php`. Application code must not initialize database engines, the global Redis bootstrap, or event loops.

### Sending

```php
queue()->send('mail', ['to' => 'user@example.com']);
queue()->send('mail', ['to' => 'user@example.com'], 30);
queue('other')->send('mail', ['to' => 'user@example.com']);
queue()->send('mail', ['to' => 'user@example.com'], function (bool $ok): void {
    // Record or report a failed write; an unconfirmed message is not a successful delivery.
});
```

`send()` retains its void/null contract. A running Workerman loop uses asynchronous callback-mode sockets. FPM, ordinary CLI, and `Client::connection('default', null)` use synchronous producers requiring ext-redis or stable Composer-installed Predis. Synchronous failures throw without a callback, or call the callback with false. Unobserved asynchronous failures are logged.

Payloads must be JSON-encodable; false, zero and null are preserved. Invalid UTF-8 or cyclic data throws before writing. Delays are nonnegative integer seconds.

### Connections

The `connection` section below does not replace your existing `enable` or `consumer_process` settings:

```php
'connection' => [
    'default' => [
        'type' => 'redis',
        'host' => '127.0.0.1',
        'port' => 6379,
        'database' => 0,
        'password' => '',
        'timeout' => 5,
        'read_timeout' => 5,
        'queue' => ['prefix' => 'my-product:', 'max_attempts' => 5, 'retry_seconds' => 5],
    ],
],
```

Connections, databases and processes do not share the global redis() helper's fixed client names. ACL supports username/password or `queue.auth => ['user', 'password']`; explicit queue.auth/db overrides connection password/database. Prefixes are applied exactly once and must match on producer and consumer. Timeouts must be positive and finite; readTimeout=-1 is rejected. TLS uses scheme=rediss and context.ssl; do not disable certificate/hostname validation.

ACL must permit the required queue commands and Lua. Redis 7+ preflights the later ZREM permission before delayed promotion; Lua atomic execution does not roll back earlier writes after an error.

TLS uses the same queue configuration; RC converts client-specific option formats:

```php
'scheme' => 'rediss',
'context' => [
    'ssl' => [
        'cafile' => phar_path() . '/data/tls/redis-ca.pem',
        'verify_peer' => true,
        'verify_peer_name' => true,
    ],
],
```

Place the actual Redis CA in the project's data/tls directory; after packaging, this is data/tls beside the executable, located through phar_path() independently of CWD. Certificates are not downloaded/generated automatically. The host/IP must match the server certificate; do not disable validation to hide a mismatch. If Redis requires mutual TLS, add local_cert and local_pk to the same ssl array for the client certificate/private key. Restrict private-key permissions and keep keys out of public distribution files.

Cluster retains type=redisCluster with a host array of `host:port` seeds, database 0 only. Queue keys default to the same `{redis-queue}` slot. A custom nonempty prefix tag such as orders{orders}: can partition separate business connections across slots. An initial empty {} is rejected because Redis then hashes full key names. More cluster nodes do not automatically split queues sharing one tag.

The async adapter follows MOVED/ASK and can try another seed after a definitive pre-connect TCP failure. Redirects are bounded to 5; at most 32 destination nodes are cached. Recreate a connection if exceptionally frequent topology changes reach that bound. Writes are never automatically replayed after an established socket is lost: Redis may already have executed them.

During slot migration Redis may reject multi-key Lua with TRYAGAIN. Delayed members remain in the set for later scans; complete the migration rather than treating deferred promotion as lost delivery.

### Consumers

```php
namespace support\queue\mail;

class SendMail
{
    public string $queue = 'mail';
    public string $connection = 'default';
    public int $worker_id = 0;

    /** Handle a mail task; use the task's business ID to make repeated execution idempotent. */
    public function handle(mixed $data): void
    {
        // Call application models or existing components. Throw on failure; false is not a retry signal.
    }
}
```

`consumer_dir` selects a directory inside the project. Shared consumers may live in `support/queue`; application-specific consumers should normally live in `apps/{app}/model/queue`:

| File | Namespace |
| --- | --- |
| `support/queue/mail/SendMail.php` | `support\queue\mail` |
| `apps/orders/model/queue/UpdateOrder.php` | `app\orders\model\queue` |

`apps/` maps to `app\` by default, not `apps\`. Custom `app.apps_path` and `app.app_name` select the corresponding application root/namespace; `support/` stays global. Composer autoload configuration must match these classes. Run `composer dump-autoload -o` after adding classes, particularly with authoritative classmaps.

For example, `apps/orders/model/queue/UpdateOrder.php`:

```php
namespace app\orders\model\queue;

/** Application-local order consumer discovered and initialized by the framework. */
class UpdateOrder
{
    public string $queue = 'orders';
    public string $connection = 'default';

    /** Process an order task; this example assumes an existing orders table and state column. */
    public function handle(array $data): void
    {
        $id = validator()->check($data['order_id'] ?? null, ['rule' => 'pint', 'range' => [1, PHP_INT_MAX]]);
        if ($id === false) { throw new \InvalidArgumentException('Invalid order ID.'); }
        if (SDB(null)->table('orders')->where('id', $id)->update(['state' => 'processed']) === false) {
            throw new \RuntimeException('Order update failed.');
        }
    }
}
```

Add/adjust these entries in the existing `config/queue.php` return array, retaining `connection`:

```php
'enable' => true,
'consumer_process' => [
    'QUEUE_ORDERS' => [
        'handler' => RC\Helper\Process\QueueConsumer::class,
        'count' => 2,
        'bootstrap' => [RC\Helper\Db\Medoo::class],
        'constructor' => ['consumer_dir' => BASE_PATH . '/apps/orders/model/queue'],
    ],
],
```

The framework initializes Medoo during consumer-process startup; do not call `Medoo::start()` in business consumers. `SDB(null)` serves processes without an HTTP Request. SQLite/MySQL/PostgreSQL choices remain database configuration, but the runtime must supply the corresponding PDO driver; packaging does not add extensions to an SFX. A different database engine requires matching process bootstraps. Business operations can also delegate to the application's existing models.

```php
queue()->send('orders', ['order_id' => 123]);
```

Every scanned PHP file must be a valid consumer with public `handle($data)` and a nonempty queue name or array; keep model base classes/utilities elsewhere. Duplicate handlers for one connection/queue in one process are rejected. Declare writable public `worker_id` only when needed; RC does not create dynamic properties.

These are independent queue processes, not `type=app` HTTP groups; `bind_process` does not start/stop consumers automatically. Application directories define code ownership, not queue isolation. Separate business queues by name or connection/prefix. Multiple processes on one queue compete for messages. Use business-key idempotency; the example's state update does not imply arbitrary business actions execute exactly once.

All consumer definitions are validated before subscription. Repeated start() is idempotent; worker shutdown cancels that handler's subscriptions. Discovery supports Windows and PHAR paths independently of CWD. Windows development must not hardcode database/cache choices that prevent Linux production deployment.

### Failures

max_attempts counts retries **after** the first execution: 5 permits 6 total executions; 0 fails immediately. Retry N waits retry_seconds*N seconds. Delayed scans run once per second, subject to loop load.

The native UnretryableException and RC's subclass both bypass retries. `onConsumeFailure(function (Throwable $error, array $package): array {...})` may modify data, attempts, max_attempts and string error. Null data is supported. Invalid modifications or a throwing hook are logged and ignored.

The hook receives a context copy; only those four returned fields take effect. Do not modify the message by reference or change id, queue, time or delay: those edits do not change the original envelope. The framework finalizes retry metadata/destination before encoding the final envelope once, so prevalidation no longer repeats data's JsonSerializable behavior. An object appearing multiple times within JSON still follows PHP's per-occurrence encoding semantics.

At the largest accepted attempt integer, retain a decodable counter and store a terminal failure with `retry attempts limit reached` instead of emitting a record the framework cannot read. This is an extreme-value guard, not an application retry policy; ordinary max_attempts still means retries after the initial execution.

Existing key names and JSON envelopes remain compatible: prefix+{redis-queue}-waiting+name, prefix+{redis-queue}-delayed, prefix+{redis-queue}-failed. No old data is cleared automatically. Malformed envelopes or mismatched queue names are quarantined unchanged.

New message IDs are random hexadecimal opaque strings, not timestamps; read the time field instead. Close and recreate clients after intentionally changing runtime connection configuration; existing sockets do not automatically change databases.

### Capacity and Large Jobs

These per-client options belong inside the connection's queue array:

| Option | Default | Meaning |
| --- | --- | --- |
| max_pending_sends | 1024 | Unconfirmed public asynchronous sends. |
| max_pending_send_bytes | 16 MiB | JSON-envelope plus Redis-key bytes for those sends. |
| max_message_bytes | 512 MiB | One complete public-send JSON envelope, not data alone. |
| delayed_batch_bytes | 8 MiB | Delayed-scan reply member budget; still at most 128 members. |
| max_response_bytes | 1 GiB + 64 KiB | Queue-only async RESP frame ceiling; HTTP/global limits are unchanged. |

Saturation immediately calls the send callback with false, or throws OverflowException without a callback. Locally rejected jobs are not dispatched or logged one by one. Capacity is released before confirmation/failure callbacks, allowing callback reentry. Synchronous producers finish before returning and do not track an async outbox. Public messages exceeding max_message_bytes throw LengthException before dispatch.

For existing large jobs, one message above the pending-byte budget but within the message limit may occupy the outbox exclusively; further public sends are rejected until it settles. A delayed member exceeding its batch budget is returned alone when first, avoiding permanent starvation. Retry/failure writes do not compete for public-send quota; they remain constrained by single-job processing and delayed-batch state.

The frame ceiling neither preallocates RAM nor caps the PHP heap. Its default covers Redis' default maximum key/value plus framing, but actual capacity also depends on Redis settings, PHP memory and JSON copies. delayed_batch_bytes must be positive and below the frame ceiling; its omitted default is at most 8 MiB and automatically shrinks with a smaller frame setting. Do not lower the ceiling below existing jobs' required reply size: BRPOP has no ACK, so a rejected reply may already have removed its job. Prefer business IDs with large data/files in durable storage.

Callback false can also represent a remote fault, not proof that Redis wrote nothing. Combine backpressure/network uncertainty with idempotency and reconciliation; do not endlessly resend immediately from failure callbacks.

### Worker Shutdown

RC-managed Workerman main/custom APP workers and ordinary custom processes bind queue shutdown automatically. Both direct async RedisClient instances and queue() clients are tracked per process. Workers that never use queues do not load the queue runtime.

On Linux reload -g / stop -g, existing user stop callbacks run first, then new consumption stops. A pending finite BRPOP may complete; a popped reply with no remaining handler is returned unchanged to its original list and its write is confirmed. Active requests may continue producing, including closing and recreating a client. Queue sockets close after pending commands and other TCP connections finish. The drain timer exists only during shutdown, never during steady-state operation.

close() and Client::closeAll() are **immediate aborts**, not substitutes for graceful draining. They do not wait for outstanding commands; unconfirmed writes may have unknown outcomes. They affect only owned queue resources, not ordinary Redis/cache or foreign timers. Native Workerman programs that bypass RC startup must manage their own client lifecycle. Do not overwrite framework-bound callbacks when adding custom callbacks.

Graceful shutdown may still wait for long-lived foreign connections; forceful stops/crashes can interrupt jobs. Facade caches recreate clients after fork based on PID. Do not continue using manually retained underlying connections across parent/child processes.

### Object Lifetime

Queue clients are process-local runtime objects. `clone`, `serialize()` and `unserialize()` throw `LogicException`. Configure another connection name or create another RedisClient for independent ownership; do not copy live connections, subscriptions or callbacks. Persist configuration/job data, not client instances.

The facade caches queue() connections, so unsetting a local variable does not close them. A directly created one-shot producer closes its transports/timers when no references remain after pending callbacks settle. Pending confirmations retain the client; unset is neither cancellation nor graceful draining. Subscriptions and application closures may also retain it.

Call close() for deterministic immediate abort; in-flight writes are not guaranteed successful. Destruction is a non-throwing fallback without forced GC or task replay, not a replacement for managed worker shutdown. That fallback runs only in the creating process and does not promise active async-client transfer across fork.

### Reliability Limits

Delayed promotion uses bounded scans and atomic Lua. It checks that a member is still due, pushes the destination first, then removes the source member. Competing scanners do not duplicate the move; WRONGTYPE leaves the source intact. Redis persistence/failover durability is a separate concern.

Consumption still uses upstream BRPOP, **without ACK or visibility timeout**. A crash after pop may lose a job. A missing network reply cannot prove whether a command executed. Do not claim at-least-once, exactly-once, or loss-free delivery. Critical business operations need durable task records, idempotency and reconciliation.

Synchronous producers cannot subscribe. Workerman consumption is nonblocking; the retiring Swoole timer bridge retains RPOP polling with synchronous network round trips. No new coroutine integration is enabled.

### Upstream

Pristine [workerman/redis 2.1.0](https://github.com/workerman-php/redis/releases/tag/v2.1.0) and [workerman/redis-queue 1.2.2](https://github.com/workerman-php/redis-queue/releases/tag/v1.2.2) plus byte manifests are bundled under src/ThirdParty. Compatible exact Composer versions take precedence. RC adaptation stays separate.

The legacy Redis facade retains queueRedis.php configuration and falls back to queue.php. New code should use queue(). It no longer attaches dynamic send/execCommand properties to ext-redis objects. Protected RedisClient fields and retry internals are not stable extension points; review custom subclasses.
