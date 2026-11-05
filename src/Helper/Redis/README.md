# 原生 Redis 连接

简体中文 | [English](README.en.md)

在框架 bootstrap 中加载 `RC\Helper\Redis\Raw`，业务通过 `redis()` 获取已配置的客户端，不调用组件的 `start()`。它返回原生 `Redis`、`RedisCluster` 或 Composer `Predis\Client`，不是缓存门面，也不是异步队列连接。

## 常用配置

`config/redis.php`：

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

// 同一进程、连接名和 ID 返回同一客户端；不同 ID 分别缓存客户端对象。
$other = redis('raw', 'default', 2);
```

- `auto` / `phpredis` 优先使用扩展；扩展不可用时回退到已安装的稳定 Predis。`predis` 强制使用 Composer Predis。框架不自动下载依赖。
- 普通 `set/get` 等命令由客户端应用一次前缀，不要再手工拼接。`rawCommand/executeRaw` 属于底层原生命令，键参数按所选客户端的规则提供。
- 两种客户端的返回类型、事务/管道和扫描 API 并不完全相同，例如未命中可能为 `false` 或 `null`；不要把切换驱动理解为所有调用都零差异。
- ID 应是有限、固定的业务连接编号，不要使用随机值、请求编号或用户编号，否则会不断增加连接。

ACL 用 `username` 和 `password`，也可把 `password` 配为 `[用户名, 密码]`。字符串 `"0"` 不会被当成空凭据。只有连接、认证、选库和选项配置都成功后，客户端才进入缓存；失败后再次获取会重新创建。

## Cluster 与 TLS

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

Cluster 只支持数据库 0；多键事务/脚本仍须遵守 Redis 的同槽要求。增加节点不会消除 CROSSSLOT 限制。Predis 使用只读拓扑发现连接可用种子，发现的节点继续继承认证与 TLS 设置；这不代表会重发失败的业务写入。

在相应连接配置中添加 TLS 参数，单机与集群相同：

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

证书由部署者提供，路径位于可执行文件旁的 `data/tls/`，不依赖当前工作目录。主机名/IP 必须匹配证书；双向认证时在同一数组配置 `local_cert` 与 `local_pk`。也兼容原生 `context.stream` 写法；不要关闭证书校验来解决配置错误。

兼容 `tls://主机` 和 `tls://主机:端口`；集群显式 TLS 种子可推断 TLS 模式，但不能混入明文种子。Unix socket 可用绝对 `host` 路径或 `unix:///绝对路径`，不与 TLS 混用。

## 常驻进程与迁移

- 框架不再隐式注册每连接的 `GET ping` 心跳。获取客户端不会增加后台计时器，也不在定时回调中占用 Worker 等待同步 Redis。
- fork 后重新调用 `redis()` 会获取子进程自己的连接；不要继续使用自己保留的父进程原生对象。子进程释放自己的连接不替换父进程缓存。
- `raw` 仍是同步客户端，慢命令、阻塞命令会占用当前 Worker。长轮询放独立自定义进程，不因主线使用 Workerman 就变成异步 I/O。
- 未指定读取超时时，使用连接超时，默认 5 秒；保留 `readTimeout` 别名，`read_timeout` 优先。显式 `0` / `-1` 保留原生无限等待含义，业务须自行承担阻塞风险；连接超时必须为正且有限。
- 不增加框架层命令重试，显式禁用可配置的网络重试。网络错误时写入可能已执行，不要无条件重放扣款、计数等写操作，应通过业务幂等和对账处理。
- 旧单机分支曾漏设前缀。修复后普通命令会访问配置前缀下的键；上线前迁移确实需要保留的旧无前缀数据，不自动扫描、复制或删除用户数据。

本轮没有改写或启用 Mix/Swoole 连接池。其历史实现仍在独立审计中，不能把 `raw` 的验证结果套用到 `mix`。测试范围和未完成项见[升级记录](../../../data/docs/dependency-upgrade.md)。
