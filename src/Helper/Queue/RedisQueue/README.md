## Redis 队列

[English](README.en.md)

业务继续使用 `queue()`，不需要手动初始化数据库、Redis 引擎或事件循环。消费者在 `config/queue.php` 中交给框架启动；普通 PHP 脚本也能投递消息。

### 投递消息

```php
queue()->send('mail', ['to' => 'user@example.com']);
queue()->send('mail', ['to' => 'user@example.com'], 30); // 延迟 30 秒
queue('other')->send('mail', ['to' => 'user@example.com']);

// 常驻 Workerman 中异步发送，需要确认写入结果时使用回调。
queue()->send('mail', ['to' => 'user@example.com'], function (bool $ok): void {
    if (!$ok) {
        // 记录或上报失败；不能把未确认的消息当作已成功投递。
    }
});
```

`send()` 保持原来的无返回值用法。Workerman 运行时使用异步连接；FPM、普通 CLI 或 `Client::connection('default', null)` 使用同步生产者，依赖 `ext-redis` 或通过 Composer 安装的稳定版 Predis。同步发送未提供回调时，连接或写入失败会抛异常；提供回调时会将 `false` 传给回调，不是由 `send()` 返回。异步发送没有回调时，失败写入日志。

数据必须能编码为 JSON，支持数组、字符串、数字、布尔值和 `null`。非法 UTF-8、循环引用等编码错误直接抛异常，不会写入空消息。延迟秒数为非负整数。

### 配置连接

下面是 `config/queue.php` 的连接部分，保留项目原有的 `enable` 和 `consumer_process` 配置：

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
        'queue' => [
            'prefix' => 'my-product:',
            'max_attempts' => 5,
            'retry_seconds' => 5,
        ],
    ],
],
```

- 不同连接、数据库和进程使用独立客户端，不再复用全局 `redis()` 的固定连接名。
- ACL 可配置 `username` + `password`，或 `queue.auth => ['用户名', '密码']`。
- `queue.auth` / `queue.db` 显式设置时，优先于连接的 `password` / `database`。
- `prefix` 只在队列层添加一次；同一业务的生产者和消费者必须配置一致。
- ACL 应允许队列所需的读写命令和 Lua。Redis 7+ 会在延迟转移前检查后续 ZREM 权限，避免已推入目标才发现无权删除源；Lua 的原子执行不代表发生错误时会回滚。
- 连接和读取超时必须为正数且有限；不再接受无限等待的 `readTimeout = -1`。
- TLS 使用 `scheme => 'rediss'`，证书参数放在 `context.ssl`；不要关闭证书和主机名验证。

TLS 连接仍使用同一套队列配置，框架负责转换各客户端的参数格式：

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

示例将证书放在项目的 `data/tls/`，打包后对应可执行文件旁的同名目录，由 `phar_path()` 定位，不依赖当前工作目录。先放好实际 Redis CA 文件；不会自动下载或生成。连接的主机名/IP 应匹配服务端证书，不能以关闭验证解决名称错误。Redis 要求双向认证时，在同一 `ssl` 数组中添加 `local_cert`、`local_pk`，分别指向客户端证书和私钥；私钥应限制文件权限，不打包进公开分发文件。

Cluster 保留原来的配置方式：

```php
'cluster' => [
    'type' => 'redisCluster',
    'host' => ['127.0.0.1:7000', '127.0.0.1:7001', '127.0.0.1:7002'],
    'database' => 0,
    'queue' => ['prefix' => 'my-product:'],
],
```

Cluster 仅支持数据库 0。默认使用 `{redis-queue}` 哈希标签，相关键必须落在同一槽，才能原子转移延迟消息。前缀也可使用自己的非空标签，例如 `orders{orders}:`，以便不同业务连接分布到不同槽；不要让第一个标签是空 `{}`，它会导致 Redis 按完整键名分槽。增加节点不会自动拆分同一标签下的队列。

异步客户端处理 `MOVED` / `ASK`，并在 TCP 尚未建立时尝试其他种子节点。重定向最多 5 次；连接池最多缓存 32 个目标节点，极少数长期拓扑变更达到上限时应重建连接。**已建立连接后断线不自动重发写入**，因为服务端可能已执行命令但响应丢失。

迁移过程中 Redis 可能以 `TRYAGAIN` 暂时拒绝多键 Lua。延迟消息保留在集合中，后续扫描会再次尝试；应完成槽迁移，不要把暂缓提升理解为任务已经丢失。

### 编写消费者

```php
namespace support\queue\mail;

class SendMail
{
    public string $queue = 'mail';
    public string $connection = 'default';
    public int $worker_id = 0; // 需要进程编号时显式声明；不需要则可省略。

    /** 接收邮件任务；使用任务的业务编号保证重复执行时不会重复发信。 */
    public function handle(mixed $data): void
    {
        // 调用应用模型或已有组件处理任务。
        // 处理失败请抛异常；仅返回 false 不会触发重试。
    }
}
```

`consumer_dir` 指向项目内的消费者目录。公共消费者可以放在 `support/queue`；应用自己的业务消费者优先放到 `apps/{app}/model/queue`：

| 文件 | 命名空间 |
| --- | --- |
| `support/queue/mail/SendMail.php` | `support\queue\mail` |
| `apps/orders/model/queue/UpdateOrder.php` | `app\orders\model\queue` |

`apps/` 默认映射到 `app\`，不是 `apps\`。自定义 `app.apps_path`、`app.app_name` 时沿用对应应用根目录和命名空间；`support/` 始终保留全局命名空间。项目的 Composer 自动加载配置也须与这些类一致，新增类后执行 `composer dump-autoload -o`；使用 authoritative 类图时尤其要重新生成。

例如，`apps/orders/model/queue/UpdateOrder.php` 可以写成：

```php
namespace app\orders\model\queue;

/** 订单应用专用消费者，由框架扫描并初始化所需组件。 */
class UpdateOrder
{
    public string $queue = 'orders';
    public string $connection = 'default';

    /** 处理订单任务；这里假定项目已有 orders 表和 state 字段。 */
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

在现有 `config/queue.php` 返回数组中配置以下部分，保留前面的 `connection`：

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

这里由框架在消费进程启动时初始化 Medoo；消费者内不要再调用 `Medoo::start()`。`SDB(null)` 用于没有 HTTP Request 的进程。SQLite/MySQL/PostgreSQL 通过项目数据库配置选择，但运行时须具备对应 PDO 驱动，打包不会自动给 SFX 增加扩展。切换数据库引擎时，进程的 bootstrap 要与项目所用引擎一致。业务操作也可以交给该应用已有的模型。

```php
queue()->send('orders', ['order_id' => 123]);
```

目录下的 PHP 文件都应是有效消费者，需要公开的 `handle($data)` 和非空 `queue`，也支持队列名数组；不要把模型基类或工具文件放入扫描目录。同一进程不能给同一连接的同一队列注册两个处理器。

消费者是独立队列进程，不是 `type=app` 的 HTTP 进程组；`bind_process` 不会自动启停消费者。应用目录只确定代码归属，不自动隔离队列。不同业务使用不同队列名，或不同连接/前缀；多个进程消费同一队列是竞争分发。处理逻辑仍需按业务编号幂等，不能因本例使用状态更新就推定任意业务都只执行一次。

框架先验证目录中的消费者，再订阅。重复调用 `start()` 不会重复注册，停止进程会取消该处理器的订阅。Windows 与 PHAR 内部路径均按项目根目录解析，不依赖当前工作目录。Windows 用于开发，生产进程数以 Linux 配置为准；业务端不要据此写死数据库、缓存驱动。

### 失败与重试

`max_attempts` 表示**首次执行之后最多重试的次数**。例如设为 5，任务最多执行 6 次；设为 0，首次失败即进入失败队列。第 N 次重试等待 `retry_seconds × N` 秒；延迟扫描间隔为 1 秒，实际执行时间还受事件循环负载影响。

```php
use RC\Helper\Queue\RedisQueue\UnretryableException;

// 不可恢复的业务错误，直接进入失败队列。
throw new UnretryableException('任务对应的资源已永久删除');
```

同样识别上游 `Workerman\RedisQueue\UnretryableException`。新版本支持失败钩子，可调整 `data`、`attempts`、`max_attempts` 和字符串 `error`；钩子抛异常或返回非法修改时，记录错误并沿用原消息。

钩子接收上下文副本，只有返回数组中上述四个字段生效。不要通过引用修改消息，也不要改 `id`、`queue`、`time` 或 `delay`；这类修改不会写回原信封。框架先完成重试计数和目标选择，再对最终信封做一次 JSON 编码，避免预校验重复执行数据中的 `JsonSerializable`。同一对象在 JSON 中出现多次时，仍遵循 PHP 的逐处编码语义。

计数已达最大可接受整数时，保留可读取的原计数并进入失败队列，错误中注明 `retry attempts limit reached`，不再生成自身无法解析的重试记录。这是极限保护，不是业务重试策略；正常 `max_attempts` 仍表示首次执行后的重试次数。

```php
queue()->onConsumeFailure(function (\Throwable $error, array $package): array {
    return ['max_attempts' => 2, 'data' => $package['data']];
});
```

失败数据位于 `prefix + {redis-queue}-failed`，延迟消息位于 `prefix + {redis-queue}-delayed`，等待队列位于 `prefix + {redis-queue}-waiting + 队列名`。保留已有键名和 JSON 信封格式，不会自动清空旧数据。格式不完整或队列名与所在键不一致的消息进入失败队列，原文保留。

新消息的 `id` 改为随机十六进制标识，应当作不透明字符串处理；需要消息时间请读取 `time`，不要从 `id` 解析时间。运行时主动更换连接配置后，应先关闭旧队列客户端再重新获取，不能指望已打开的连接自动切库。

### 容量与大消息

以下选项放在连接的 `queue` 数组内，按客户端分别生效：

| 选项 | 默认值 | 含义 |
| --- | --- | --- |
| `max_pending_sends` | 1024 | 异步公开发送中，尚未确认的任务数。 |
| `max_pending_send_bytes` | 16 MiB | 上述任务的 JSON 信封与 Redis 键字节预算。 |
| `max_message_bytes` | 512 MiB | 公开发送允许的单个完整 JSON 信封大小，不只是 `data`。 |
| `delayed_batch_bytes` | 8 MiB | 每次延迟扫描返回的成员字节预算，最多仍为 128 条。 |
| `max_response_bytes` | 1 GiB + 64 KiB | 队列专用异步连接的 RESP 帧上限，不修改 HTTP 或全局连接配置。 |

异步额度满时，有发送回调则立即调用 `false`，没有回调则抛 `OverflowException`；被本地拒绝的任务不会派发，也不逐条刷错误日志。额度在确认/失败回调前释放，因此回调中可提交下一条任务。同步生产者完成一次才返回，不走异步积压计数；超过 `max_message_bytes` 则在派发前抛 `LengthException`。

为兼容已有大任务，单条超过待发字节预算、但未超过消息上限的任务允许独占发送额度；它完成前不再接收其他公开发送。延迟批次类似：首条旧成员超过批次预算时单独返回，避免永久卡住。重试/失败存储不共用公开发送额度，仍受单任务处理及延迟批次状态约束。

帧上限不是预先分配的内存，也不是 PHP 堆上限。默认值覆盖 Redis 默认允许的大键/大值及协议头；实际可处理大小还受 Redis 配置、PHP 内存及 JSON 编解码副本影响。`delayed_batch_bytes` 必须为正且小于帧上限；未设置时最多取 8 MiB，并随较小的帧配置自动缩减。不要把帧上限降到已有任务所需大小以下：BRPOP 无 ACK，拒收响应时消息可能已经弹出。任务优先传业务编号，文件、大对象放持久存储。

发送回调的 `false` 也可能来自远端故障，不能一概理解为“Redis 一定未写入”。本地背压和网络不确定结果都应结合业务幂等与补偿处理，不在失败回调中无限立即重发。

### 进程退出与连接管理

框架启动的 Workerman 主 APP、自定义 APP 和普通自定义进程会自动接入队列退出处理，业务不需要额外注册停止回调。直接创建的异步 `RedisClient` 与 `queue()` 客户端都在本进程内管理；未使用队列的进程不加载队列运行时。

Linux 执行 `reload -g` / `stop -g` 时，先保留并执行用户停止回调，再停止领取新任务。尚未返回的 `BRPOP` 会等待有限超时；若已经弹出但不再有处理器，原消息会放回原队列并等待写入确认。仍在处理的请求可以继续发送消息，包括关闭旧客户端后重新获取；队列命令完成且其他 TCP 连接结束后，关闭本进程队列连接。排空检查只在退出期间创建计时器，平时没有额外轮询。

不要在自己的停止回调中直接 `close()` / `Client::closeAll()` 来代替这套流程：它们是**立即中止**接口，不等待未完成命令，未确认写入结果仍可能未知。它们只清理所属队列订阅、连接和计时器，不清理普通 Redis、缓存或其他任务。绕过框架启动的原生 Workerman 程序需自行管理客户端生命周期；注册自己的回调时也不要覆盖框架已绑定的回调。

优雅退出不是强制退出保障：外部长期连接未结束时可能继续等待，强杀或强制停止仍可能中断任务。连接门面在 fork 后按 PID 重建客户端；不要把自己持有的底层连接跨父子进程继续使用。

### 对象的生命周期

队列客户端是进程内的运行对象，不能 `clone`、`serialize()` 或 `unserialize()`；这些操作会抛出 `LogicException`。需要独立连接时，配置不同连接名或新建 `RedisClient`，不要复制一个持有连接、订阅及回调的对象。保存的是连接配置或任务数据，不是客户端实例。

`queue()` 返回的连接会由门面缓存，不会因局部变量被 `unset` 就关闭。直接创建的一次性生产者在没有其他引用、待发送回调已结算后，会析构并关闭所属连接/计时器。尚在等待确认的发送会继续持有客户端，不能把 `unset` 当作取消或优雅排空接口；订阅及业务闭包也可能继续持有对象。

需要明确的释放时机仍调用 `close()`；它是立即中止，不保证在途写入成功。析构只是非抛异常的清理兜底，不强制 GC、不重发任务，也不取代框架进程退出流程。析构兜底只在创建对象的进程执行，不宣称支持把活动异步客户端跨 `fork` 转交使用。

### 可靠性边界

- 延迟转移使用有界批次和 Redis Lua：先确认成员仍到期，写入目标列表成功后再从延迟集合删除。多个扫描器竞争不会重复转移；目标键类型错误时原成员仍保留。这不等于 Redis 持久化或主从切换时的数据保障。
- 消费仍沿用上游 `BRPOP`，**没有 ACK / 可见性超时**。进程在取出消息后崩溃，消息可能丢失；网络响应丢失也无法仅凭客户端判断是否已经执行。
- 不能声称“至少一次”“恰好一次”或“绝不丢消息”。支付、库存等业务必须有持久任务记录、业务幂等键及对账补偿。
- 普通同步生产者不能订阅。Workerman 使用专用异步消费连接，空队列不会阻塞事件循环。准备弃用的 Swoole 桥接仅保留定时 `RPOP` 轮询，网络往返仍是同步的；没有新增协程支持。

### 上游与迁移

内置原版 [workerman/redis 2.1.0](https://github.com/workerman-php/redis/releases/tag/v2.1.0) 和 [workerman/redis-queue 1.2.2](https://github.com/workerman-php/redis-queue/releases/tag/v1.2.2)，原文件及校验清单在 `src/ThirdParty/`。Composer 安装兼容的相同版本时优先使用 Composer。RC 连接隔离、消息校验、原子延迟转移和生命周期管理均在本目录适配层，不修改上游文件。

旧 `RC\Helper\Queue\RedisQueue\Redis` 保留 `queueRedis.php` 配置兼容，没有该配置时回落到 `queue.php`；推荐新代码统一使用 `queue()`。不再向扩展 Redis 对象动态挂载 `send` / `execCommand` 属性。`RedisClient` 的受保护内部字段和重试实现不是稳定扩展点；自定义子类需重新核对。
