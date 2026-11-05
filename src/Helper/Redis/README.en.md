# Native Redis Connections

[简体中文](README.md) | English

Load `RC\Helper\Redis\Raw` through framework bootstrap. Business code obtains configured clients through `redis()`, never by calling component `start()`. Results are native `Redis`, `RedisCluster` or Composer `Predis\Client` objects, not the cache facade or asynchronous queue transport.

## Basic Configuration

`config/redis.php`:

```php
return [
    'default_frame' => 'raw',
    'default' => [
        'type' => 'common',
        'host' => '127.0.0.1',
        'port' => 6379,
        'database' => 0,
        'password' => '',
        'prefix' => 'my-app:',
        'timeout' => 5,
        'read_timeout' => 5,
        'persistent' => false,
        'client' => 'auto',
    ],
];
```

```php
$client = redis();
$client->set('message', 'hello');
$value = $client->get('message');

// Same process, connection name and ID reuse a client; different IDs cache separate client objects.
$other = redis('raw', 'default', 2);
```

- `auto` / `phpredis` prefer the extension, falling back to an installed stable Predis package when unavailable. `predis` forces Composer Predis. The framework does not download dependencies automatically.
- Ordinary `set/get` commands receive one client-managed prefix. Do not prepend it again. `rawCommand/executeRaw` are low-level native APIs; supply keys according to that client's rules.
- Return values, transactions/pipelines and scanning APIs differ between clients. Missing values may be `false` or `null`; switching drivers is not a universal zero-difference abstraction.
- IDs must be a finite set of stable business connection numbers. Random, per-request or per-user IDs continually create more connections.

ACL credentials use `username` and `password`, or `password => [username, password]`. String `"0"` remains a real credential. Clients are cached only after connection, authentication, database and option setup all succeed. A later acquisition after setup failure creates a fresh client.

## Cluster and TLS

```php
'cluster' => [
    'type' => 'cluster',
    'host' => ['127.0.0.1:7000', '127.0.0.1:7001', '127.0.0.1:7002'],
    'database' => 0,
    'prefix' => 'my-app:',
    'timeout' => 5,
    'read_timeout' => 5,
],
```

Cluster supports database 0 only. Multi-key transactions/scripts retain Redis' same-slot requirements; more nodes do not remove CROSSSLOT constraints. Predis discovers topology through available seeds using read-only requests. Discovered nodes inherit authentication/TLS settings, not permission to replay failed business writes.

Add TLS settings to the relevant standalone or cluster connection:

```php
'scheme' => 'tls',
'context' => [
    'ssl' => [
        'cafile' => phar_path() . '/data/tls/redis-ca.pem',
        'verify_peer' => true,
        'verify_peer_name' => true,
    ],
],
```

Deployers provide certificates under `data/tls/` beside the executable, independent of CWD. The host/IP must match the certificate. For mTLS, add `local_cert` and `local_pk` to that same array. Native `context.stream` syntax also works; do not disable verification to hide configuration errors.

Legacy `tls://host` and `tls://host:port` forms remain accepted. Explicit TLS cluster seeds can infer TLS mode but cannot mix with plaintext seeds. Unix sockets accept an absolute host path or `unix:///absolute/path`, never combined with TLS.

## Long-Running Processes and Migration

- The framework no longer creates implicit per-client `GET ping` heartbeats. Client acquisition adds no background timers or synchronous Redis waits inside those callbacks.
- After fork, calling `redis()` obtains a child-owned connection. Do not reuse a retained parent-native object. Closing child-owned clients does not replace the parent's cache.
- `raw` remains synchronous. Slow/blocking commands occupy the current Worker. Use dedicated custom processes for long polling; Workerman does not make these clients asynchronous.
- Unspecified read timeout follows connect timeout, default five seconds. Legacy `readTimeout` remains accepted; `read_timeout` wins. Explicit `0` / `-1` retain native unlimited-wait behavior and its blocking risk. Connect timeout must be positive and finite.
- No framework command retry is added; configurable network retries are explicitly disabled. A failed response can follow an executed write. Use business idempotency/reconciliation instead of blindly replaying money/counter updates.
- The old standalone branch omitted prefixes. Ordinary commands now address configured prefixed keys. Migrate any required historical unprefixed data before rollout; the framework never automatically scans, copies or deletes it.

This round does not rewrite or enable Mix/Swoole pools. They remain separately under audit; `raw` results do not validate `mix`. See the [audit ledger](../../../data/docs/dependency-upgrade.en.md) for scope and remaining work.
