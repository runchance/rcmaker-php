# GlobalData

[English](README.en.md)

GlobalData 是独立进程内的共享键值服务，不是各 APP 进程共享同一份 PHP 内存。客户端通过 TCP 或 Unix socket 访问服务端；服务重启后数据丢失。一般业务缓存优先使用框架现有缓存组件，需要持久化、过期和更多数据结构时使用 Redis。

## 基本用法

框架已内置稳定版 `workerman/globaldata 1.0.6`，不必额外安装。保留原版源码；`RC\Helper\GlobalData` 在独立适配层处理安全解码、连接归属和超时。可选 Composer 同名包只允许该版本，并优先于内置副本加载。业务端使用 **RC 类名**，不要绕过适配层直接实例化原生客户端或服务端。

独立源码服务入口可放在 `data/tools/globaldata-server.php`。先定义框架根路径再加载 Composer，不能把下面代码原样放进已有 HTTP 请求或当作普通进程 handler；它会自行创建一个 Workerman Worker。此脚本不替代项目进程配置，`data/` 也默认不打入业务二进制：

```php
define('ROOT_PATH', dirname(__DIR__, 2));
define('IS_SCRIPT', true);
require ROOT_PATH . '/vendor/autoload.php';

new RC\Helper\GlobalData\Server('127.0.0.1', 2207);
Workerman\Worker::runAll();
```

客户端：

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

`add()` 返回服务端真实布尔结果，并读取确认包；不会遗留给下一次查询。`cas()` 比较 PHP 原生序列化值，只保证单个键的一次操作原子性，不是多键事务。先 get 再 set 不是原子操作。

## 连接与边界

| 项目 | 约定 |
| --- | --- |
| 服务端默认地址 | `127.0.0.1:2207`，不再默认监听所有网卡 |
| 数据类型 | 标量、`null`、数组；支持二进制字符串，禁止对象、枚举、资源和循环引用 |
| 帧上限 | 默认 1 MiB，包含 4 字节头；两端可用 `max_frame` 设置 64 字节至 64 MiB 的整数 |
| 结构上限 | 包含协议请求包装在内最多 64 层、100,000 个节点，键也计入节点；过深/过大的共享引用图也可能被拒绝 |
| 超时 | `timeout` 为大于 0、最多 86,400 秒的整数或浮点数，覆盖连接、写入、响应头和响应体的总时间 |
| 心跳 | `pingInterval` 默认 25 秒；0 禁用。仅实际 Workerman 循环存在时注册自己的弱引用计时器，普通 CLI 无需初始化 Timer |
| 并发模型 | 同步阻塞 RPC，不新增协程支持；一个服务端只运行一个数据 Worker |

`max_frame` 两端需一致。它限制单帧，不限制整个数据集内存；组件没有内置认证、TLS、TTL、持久化或集群故障转移。不要暴露公网。显式配置外部绑定时，应由部署环境提供访问隔离。

```php
$server = new RC\Helper\GlobalData\Server('127.0.0.1', 2207, ['max_frame' => 2097152]);
$client = new RC\Helper\GlobalData\Client('127.0.0.1:2207', ['max_frame' => 2097152]);
```

每个客户端独占连接和心跳，`close()`/析构不关闭其他客户端的连接。fork 后首次操作会关闭子进程继承的本地句柄并重新连接，父连接继续使用；不能克隆或序列化客户端。

连接失败、超时或确认包丢失会抛出异常并丢弃本次连接，**不重放旧命令**。写操作可能已经执行，不能把异常当作“肯定没写入”再无条件重试。后续新的操作可以重新连接。

## 多节点与迁移

旧 RC 客户端固定访问列表首节点。本次保留这个默认，避免升级时键悄悄换位置；原生上游的 CRC32 分片需要显式开启：

```php
$client = new RC\Helper\GlobalData\Client(
    ['127.0.0.1:2207', '127.0.0.1:2208'],
    ['routing' => 'crc32']
);
```

节点顺序或数量变化会改变键归属；既有数据不会自动迁移，也没有一致性哈希、复制或自动切换。不同服务端是独立数据集，不要通过增加同一服务的 Worker 数量来扩容同一份状态。

Linux 可使用 Unix socket，路径允许空格且必须为绝对路径；Windows 使用 TCP：

```php
$server = new RC\Helper\GlobalData\Server('unix:///run/rcmaker/globaldata.sock');
$client = new RC\Helper\GlobalData\Client('unix:///run/rcmaker/globaldata.sock');
```

保留上游的 `isset` 语义：缺失键和已存 `null` 的读取结果均为 `null`，`add()` 可覆盖已存 `null`，`increment()` 对两者均返回 `false`；对已存在的非数字值递增时从 0 开始。CAS 仍使用原生序列化值的 MD5，该摘要不是安全签名。

**升级注意：**旧对象值不再支持，需要改存数组；直接调用原生类不会获得 RC 的安全限制。原版归档声明 MIT，但没有提供许可正文及版权声明，现如实记录在 [manifest](../../ThirdParty/workerman-globaldata/upstream-manifest.json)，不伪造上游授权文本。测试及未验收边界见[升级记录](../../../data/docs/dependency-upgrade.md)。
