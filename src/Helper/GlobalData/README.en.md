# GlobalData

[中文](README.md)

GlobalData is a separate in-memory key/value service, not one PHP heap shared by APP workers. Clients communicate over TCP or Unix sockets; restarting the server loses its data. Prefer the existing framework cache for ordinary caching and Redis when persistence, expiration or richer structures are needed.

## Basic Usage

Stable `workerman/globaldata 1.0.6` is bundled without upstream source edits. `RC\Helper\GlobalData` adds separate decoding, ownership and timeout adapters. An optional Composer copy must match this exact version and takes precedence. Application code must use the **RC classes**, not bypass these protections through native classes.

An independent source-service entry may live at `data/tools/globaldata-server.php`. Define the framework root before loading Composer. Do not put this inside an HTTP request or use it as an ordinary process handler: it creates its own Workerman Worker. This does not replace project process configuration, and `data/` is excluded from normal application binaries:

```php
define('ROOT_PATH', dirname(__DIR__, 2));
define('IS_SCRIPT', true);
require ROOT_PATH . '/vendor/autoload.php';

new RC\Helper\GlobalData\Server('127.0.0.1', 2207);
Workerman\Worker::runAll();
```

Client:

```php
$shared = new RC\Helper\GlobalData\Client('127.0.0.1:2207');
$shared->timeout = 0.5;
$shared->counter = 0;
$added = $shared->add('settings', ['enabled' => true]);
$count = $shared->increment('counter', 1);
$changed = $shared->cas('counter', $count, 10);
$settings = $shared->settings;
unset($shared->settings);
$shared->close();
```

`add()` consumes its acknowledgement and returns the actual server boolean. CAS compares native serialized values and makes a single-key operation atomic; it is not a multi-key transaction. A separate get followed by set is not atomic.

## Ownership And Limits

| Setting | Contract |
| --- | --- |
| Default server address | `127.0.0.1:2207`, no longer all interfaces |
| Values | Scalars, `null`, arrays and binary strings; no objects, enums, resources or recursive references |
| Frame limit | Default 1 MiB including the four-byte header; `max_frame` accepts integer limits from 64 bytes to 64 MiB on both sides |
| Structure limits | At most 64 levels and 100,000 nodes including the request envelope and keys; deeply or exponentially shared graphs may also be rejected |
| Timeout | Positive integer/float seconds, at most 86,400, for the entire connect/write/header/body operation |
| Heartbeat | `pingInterval` defaults to 25 seconds; 0 disables it. Only an actual Workerman loop receives an owned weak timer; plain CLI needs no Timer initialization |
| Concurrency | Synchronous blocking RPC, no new coroutine integration; one data Worker per server |

Configure the same `max_frame` on both sides. This bounds individual frames, not total data-set memory. There is no built-in authentication, TLS, TTL, persistence or cluster failover. Never expose the service publicly; explicit external bindings need deployment-level access isolation.

```php
$server = new RC\Helper\GlobalData\Server('127.0.0.1', 2207, ['max_frame' => 2097152]);
$client = new RC\Helper\GlobalData\Client('127.0.0.1:2207', ['max_frame' => 2097152]);
```

Each client owns its sockets and timers. Closing/destructing one does not affect another. After fork, the child's first operation drops its inherited local descriptors and reconnects without changing the parent's connection. Clients cannot be cloned or serialized.

Connection failures, timeouts and lost acknowledgements throw and discard the affected connection. **Old commands are never replayed.** A failed write may already have executed; an exception does not justify unconditional retry. A later new operation may establish a fresh connection.

## Multiple Nodes And Migration

Legacy RC always used the first listed server. That remains the default to avoid silently relocating existing keys. Explicitly opt into native CRC32 sharding:

```php
$client = new RC\Helper\GlobalData\Client(
    ['127.0.0.1:2207', '127.0.0.1:2208'],
    ['routing' => 'crc32']
);
```

Changing node order/count changes ownership. Existing data is not migrated; consistent hashing, replication and automatic failover are not provided. Separate servers hold separate data sets; increasing one service's Worker count does not scale one coherent store.

Linux supports absolute Unix socket paths, including spaces. Use TCP on Windows:

```php
$server = new RC\Helper\GlobalData\Server('unix:///run/rcmaker/globaldata.sock');
$client = new RC\Helper\GlobalData\Client('unix:///run/rcmaker/globaldata.sock');
```

Native `isset` semantics remain: missing and stored-null keys both read as `null`; `add()` can replace stored null; `increment()` returns `false` for either. Incrementing an existing nonnumeric value starts from zero. CAS still hashes native serialization with MD5, which is not an authentication signature.

**Migration:** store arrays instead of legacy object values. Native classes do not inherit RC protections. The release declares MIT but omits license text/copyright notices; the [manifest](../../ThirdParty/workerman-globaldata/upstream-manifest.json) records this without fabricating an upstream grant. See the [audit ledger](../../../data/docs/dependency-upgrade.en.md) for verification and remaining limits.
