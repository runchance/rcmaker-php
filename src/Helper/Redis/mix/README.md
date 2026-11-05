# Mix Redis 兼容接口

[English](README.en.md)

保留旧项目的 `redis('mix')` 调用，适配 PHP 8.5 与稳定 Mix Redis 3.0.11。需要 **ext-redis**；要使用 Predis，请选框架原生 Redis 接口 `redis('raw')`，不要在业务中另写连接初始化。

> **升级前检查键名。** 旧单机接口漏用了配置中的 `prefix`，现在会正确应用；旧集群接口把空前缀强制改成 `rcmaker_`，现在尊重配置。需要继续读取旧集群键时，明确设置 `'prefix' => 'rcmaker_'`。框架不会自动迁移或删除原有键。

## 配置与普通命令

在 `config/bootstrap.php` 启用 `RC\Helper\Redis\Mix`。`config/redis.php` 示例：

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

连接按配置名和连接 ID 缓存，平时不会新增连接或后台心跳。前缀由原生客户端处理一次，业务键不要重复拼前缀。ACL 支持用户名/密码字符串 `"0"`，也接受配置中的 `[username, password]` 密码数组。认证、数据库和选项全部设置成功后，连接才进入缓存。

读取超时默认跟随连接超时，通常为 5 秒；`readTimeout/retryInterval` 旧别名保留，`read_timeout` 优先。显式 `0` / `-1` 仍表示无限读取等待，应谨慎使用。TLS、双向认证、Unix/IPv6 及配置格式复用[原生 Redis 连接配置](../README.md)，不能从已有 TLS 测试推断所有网络组合均已验收。

## 事务和管道

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

- 作用域内使用返回的 `$transaction/$pipeline`，不要再通过外层 `$redis` 混入普通命令。框架会明确拒绝混用、嵌套切换模式和修改活动连接。
- 原生排队命令的链式结果仍返回 RC 包装对象，不会绕过所有权检查。`exec()` 只执行一次；结束后的对象不能继续发命令。
- `discard()` 取消未执行队列，已结束时返回 `false`，所以可放在 finally。丢弃未结束的对象也会尝试取消，不会自动提交。
- 管道不是事务。Redis MULTI 也不是关系数据库的回滚事务；运行时命令错误可能与其他成功结果并存，应检查每项结果。
- `AUTH/SELECT/setOption` 应放在批次外，不能排进事务或管道。原始 `rawCommand()` 不能绕过 MULTI/EXEC/WATCH 等生命周期，其他数据命令仍使用原生参数和返回值。

`watch('key')` 保留旧语义：WATCH 后立即进入 MULTI，`exec()` 返回 `false` 表示冲突。它不是“WATCH → 读取 → MULTI”的延迟事务接口；不要在此时经外层 manager 读取，也不要把冲突或断线直接当作可以安全重试。复杂原生工作流需要独立管理的原生连接和明确的清理策略。

## 集群与游标

集群配置用 `'type' => 'cluster'` 和 `host` 种子数组，只能选择数据库 0。凭据和 TLS 参数也传给发现的节点。普通命令遵循 phpredis 原生规则；`ping/client/rawCommand/scan` 等节点命令需要节点或路由键参数。

`MixCluster::scan(&$cursor, $node, $pattern, $count)` 只扫描指定节点，不把一个节点的游标伪装成整个集群。单机 SCAN 的第四个 TYPE 参数已转发，sScan/hScan/zScan 继续保留游标引用；匹配模式与返回键名的前缀规则遵循原生客户端。

原生 RedisCluster 不支持管道，调用会在改变连接状态前明确报错；使用 MULTI 或单机连接。集群 MULTI 也不提供跨节点全局原子性，不能把分布在不同节点的写入当作一笔全局事务。

## 故障与日志

框架不再递归重发失败命令，也不允许通过 `OPT_MAX_RETRIES` 打开隐式网络重试。断线错误抛给调用方，之后新的 manager 查询才尝试重连，并重新应用认证、数据库、前缀等设置。**写入或 EXEC 后断线可能已产生真实效果**；订单、扣款等业务必须自备幂等和恢复策略。这不改变原生 Cluster 的路由/重定向语义。

fork 后框架清理继承的连接句柄，新查询建立子进程连接，不向父进程会话发送清理命令。当前实测覆盖仍持有 manager/连接的 fork；fork 前已经存在的底层闲置持久池、其他库产生的持久连接与更多池配置组合仍待验证，不建议在全局预热阶段建立业务连接。

可选 logger 的异常不会覆盖原命令结果或原异常，改为记录通用日志错误；回调也不能重入当前作用域，提前提交、取消或追加命令。AUTH 参数脱敏，其他参数可能含业务数据，仍需应用控制访问与脱敏。未配置 logger 时不做额外计时。普通命令错误不代表未执行，日志错误更不能成为重复写入的理由。

## 运行与验证边界

这是同步接口，会占用当前进程等待 Redis 响应；本轮没有增加 Workerman 协程。旧 `startPool()` 仅在真实 Swoole 协程上下文可用，不满足条件时保留原同步连接并明确报错。真实 Swoole 调度、池等待/回收及压力尚未验收。

原版源码和许可记录保留在 `src/ThirdParty/mix-redis`。复用原版 ScanTrait、LoggerInterface 与 ObjectPool 基础类型；RC 命令/事务核心是有明确标注的派生适配，不直接加载仍有递归重发等风险的上游 manager。Composer 优先，内置回退保留 PHAR 路径。

测试方法见[回归说明](../../../../data/tests/README.md)。基础、真实 Redis、TLS/Cluster、Composer 冷加载和 PHAR 配置测试是不同证据；没有把无网络的配置检查当作真实命令验收。完整 Micro/加密程序、更多故障迁移、长期连接/RSS/吞吐和其他持久池组合仍需单独验证。
