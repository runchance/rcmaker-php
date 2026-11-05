# Mix Redis Compatibility

[中文](README.md)

Retains legacy `redis('mix')` access for PHP 8.5 and stable Mix Redis 3.0.11. Requires **ext-redis**. Use framework `redis('raw')` for Predis instead of implementing separate application connection initialization.

> **Check existing key names before upgrading.** Standalone Mix previously omitted the configured prefix; it now applies it. Cluster previously replaced an empty prefix with `rcmaker_`; it now honors configuration. Explicitly configure `'prefix' => 'rcmaker_'` to keep reading those legacy cluster keys. The framework does not migrate or delete existing keys.

## Configuration And Commands

Enable `RC\Helper\Redis\Mix` in `config/bootstrap.php`. Example `config/redis.php`:

```php
return [
    'default_frame' => 'mix',
    'default' => [
        'host' => rcEnv('redis.host', '127.0.0.1'),
        'port' => (int) rcEnv('redis.port', 6379),
        'username' => rcEnv('redis.username', ''),
        'password' => rcEnv('redis.password', ''),
        'database' => (int) rcEnv('redis.database', 0),
        'prefix' => 'project:',
        'timeout' => 5,
        'read_timeout' => 5,
        'persistent' => false,
    ],
];
```

```php
$redis = redis('mix');
$redis->set('greeting', 'hello');
$message = $redis->get('greeting');
```

Connections are cached by configuration name and connection ID. Ordinary operations do not add connections or background heartbeats. Native prefixing happens once; do not prepend it to application keys. ACL username/password strings `"0"` and `[username, password]` credential arrays are supported. Clients are cached only after authentication, database and options succeed.

Read timeout defaults to connection timeout, normally five seconds. Legacy readTimeout/retryInterval aliases remain; read_timeout takes precedence. Explicit 0/-1 unlimited reads remain available but need care. TLS/mTLS, Unix/IPv6 and configuration parsing share the [native Redis configuration](../README.en.md). Existing TLS results do not establish every network combination.

## Transactions And Pipelines

```php
$transaction = $redis->multi();
try {
    $transaction->set('counter', 1)->incr('counter');
    $results = $transaction->exec();
} finally {
    $transaction->discard();
}

$pipeline = $redis->pipeline();
try {
    $pipeline->get('greeting')->get('counter');
    $values = $pipeline->exec();
} finally {
    $pipeline->discard();
}
```

- Use the returned transaction/pipeline inside its scope. Outer-manager commands, nested mode switching and active-session replacement fail explicitly.
- Queued fluent calls return the RC wrapper rather than leaking the native client. exec() runs once; completed scopes cannot send more commands.
- discard() cancels an unfinished queue and returns false after completion, making finally cleanup safe. Abandoned scopes attempt cancellation, never automatic commit.
- A pipeline is not a transaction. Redis MULTI does not provide relational rollback: runtime errors can coexist with other successful command results. Inspect each result.
- AUTH/SELECT/setOption belong outside batches. rawCommand() cannot bypass MULTI/EXEC/WATCH lifecycle ownership; other data commands retain native arguments and results.

watch('key') retains legacy immediate-WATCH-then-MULTI behavior. False from exec() means a conflict. This is not a deferred WATCH/read/MULTI API. Do not read through the outer manager during its scope or assume conflicts/disconnects permit safe retries. Advanced native workflows need a separately managed native connection and explicit cleanup.

## Cluster And Cursors

Cluster configuration uses type=cluster and a host seed array, with database 0 only. Credentials and TLS settings also reach discovered nodes. Native phpredis command rules apply; node-directed ping/client/rawCommand/scan operations require a node or routing key.

MixCluster::scan(&$cursor, $node, $pattern, $count) scans the specified node, not the entire cluster under one cursor. Standalone SCAN forwards its fourth TYPE argument. sScan/hScan/zScan retain cursor references. Pattern and returned-key prefix behavior follows the native client.

Native RedisCluster does not support pipelines; rejection occurs before changing session state. Use MULTI or standalone Redis. Cluster MULTI is not globally atomic across nodes; distributed writes cannot be treated as one global transaction.

## Failures And Logging

Failed commands are no longer recursively replayed; OPT_MAX_RETRIES cannot enable implicit network retries. A disconnect propagates to the caller. Only a fresh manager query attempts reconnection with credentials, database, prefix and options restored. **Writes or EXEC may already have taken effect when a disconnect is reported.** Orders/payments require business idempotency and recovery. Native Cluster routing/redirection semantics are unchanged.

After fork, inherited handles are cleaned locally before a child connection is opened, without sending cleanup commands to the parent session. Current evidence covers retained managers/connections. Preexisting idle native persistent pools, other libraries' persistent clients and further pooling settings remain unverified. Avoid opening business connections during global prewarming.

Optional logger errors preserve the original command outcome/error and emit only a generic logging-failure message. Callbacks cannot reenter the observed scope to commit, cancel or append commands. AUTH arguments are redacted; other business arguments still need application access control/redaction. Without a logger, no extra timing is performed. Neither command nor logging errors prove a write was unexecuted.

## Runtime And Validation

This is synchronous access and waits inside the current process. No Workerman coroutine support was added. Legacy startPool() requires a real Swoole coroutine context; unsupported startup fails explicitly while preserving the working synchronous driver. Real Swoole scheduling, waits/reclamation and pool pressure remain unverified.

Pristine upstream source/license records remain in src/ThirdParty/mix-redis. RC reuses native ScanTrait, LoggerInterface and ObjectPool primitives. Its explicitly marked derived command/transaction adapter does not load upstream managers that still recursively replay commands. Composer takes precedence; bundled fallback retains PHAR paths.

See [regression instructions](../../../../data/tests/README.en.md). Configuration, live Redis, TLS/Cluster, Composer cold starts and PHAR configuration cases are distinct evidence, not interchangeable acceptance. Complete Micro/encrypted programs, more failure migrations, sustained sockets/RSS/throughput and other native persistent-pool combinations still need separate validation.
