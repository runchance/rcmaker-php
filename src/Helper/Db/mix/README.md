# Mix 数据库兼容层

[English](README.en.md)

此目录保留旧项目使用的 `RC\Helper\Db\mix` 接口，适配 PHP 8.5。**它不是新增的 SDB 引擎。** 新业务仍优先使用框架 AutoForm；纯数据库操作使用 SDB，复杂 SQL 才使用框架数据库接口。不要在应用模型中手动初始化数据库。

## 已有项目怎么用

以下 `$database` 是旧项目已经持有的 `RC\Helper\Db\mix\Database\Database` 实例，不是要求应用新增一套连接初始化代码。

```php
use RC\Helper\Db\mix\Database\Expr;

$users = $database->table('users')
    ->where('id IN (?)', [1, 2, 3])
    ->order('id', 'asc')
    ->limit(20)
    ->get();

$database->table('users')->where('id = ?', 1)->updates([
    'name' => "O'Reilly",
    'visits' => new Expr('visits + ?', 1),
]);
```

- 数组参数展开为 PDO 占位符，不拼接值；空数组展开为 `NULL`。`IN (?)` 不能改写为字符串拼接。
- `Expr` 只接收开发者控制的 SQL 模板，外部数据放在后续绑定参数中。带参数的 Expr 不允许强制转换为字符串，以免绕过绑定。
- `table/select/join/where/order/group/having` 中的 SQL 片段仍是可信 SQL 接口，不会自动校验用户传入的字段名或语句。动态字段、排序方向必须先做白名单。
- 普通查询对象只执行一次；下一次查询从 `$database` 开始。不要保存一个 builder 反复执行不同查询。

## SQL 模式与数组参数

MySQL 的 `NO_BACKSLASH_ESCAPES`、`ANSI_QUOTES` 及 PostgreSQL 的 `standard_conforming_strings` 会改变引号的含义。数组参数按**实际执行 PDO 的当前模式**识别占位位置；不把绑定值拼入 SQL，也不跨查询缓存会话模式。模式读取失败时不会继续准备业务语句，游标清理失败也不会覆盖更早的读取异常。

这项适配不为标量参数或普通数组 SQL 增加模式查询。只有 SQL 模板中的反斜杠与引号可能改变占位位置时，才读取模式；反斜杠仅存在于绑定值里不触发它。优先把文本放进绑定参数，可避免这条额外路径。

PHP PDO 的模拟预处理对部分非默认 MySQL 模式仍有原生扫描限制。确需使用这些模式及复杂原始 SQL 时，在**已有 MySQL 连接的 PDO 选项**里明确设置：

```php
PDO::ATTR_EMULATE_PREPARES => false,
```

框架不会偷偷切换预处理选项、替换 PDO 或修改用户 SQL 的反斜杠。PostgreSQL 有意使用转义字符串时优先明确写 `E'...'`；普通字符串关闭标准模式后，复杂引号及 Dollar Quote 中的占位符仍可能遇到原生 PDO 限制。不要通过字符串拼接绕过绑定错误。

## 行数限制与偏移

不调用 `limit()` 表示不限行数，显式 `limit(0)` 表示空范围，不能再把零值当成“未设置”。`limit()` / `offset()` 保留 `int` 参数，负数立即报错，不替换先前的有效限制。

| 调用 | 行为 |
| --- | --- |
| `limit(0)->get()` / `first()` | 返回 `[]` / `false`，包括聚合查询；`value()` 仍按缺少行报错 |
| `limit(0)->update(...)` / `delete()` | 执行保留原条件的空匹配 SQL，返回原连接结果，`rowCount()` 为 0 |
| `offset(10)->get()` | SQLite、MySQL、PostgreSQL 都真正跳过前 10 行；请明确排序以稳定分页 |
| 正数限量写入 | MySQL 支持无偏移的 `LIMIT`；SQLite 取决于原生驱动的可选编译能力；其他方言明确拒绝 |
| 带正数偏移的写入 | 仅交给 SQLite 原生限量写入语法；不支持时原样报错；MySQL、PostgreSQL 明确拒绝 |

```php
$rows = $database->table('users')->order('id', 'asc')->offset(20)->limit(10)->get();
$affected = $database->table('users')->where('active = ?', 0)->limit(0)->delete()->rowCount(); // 0
```

零限制写入会将所有 OR 条件整体括起后再加空匹配条件，但不是 SQL 解析器。不要传入用户拼接的 SQL 片段。原生不支持的 `ORDER/GROUP/JOIN/LOCK` 组合仍可能报错；不会为了成功执行而丢掉这些修饰。语句级触发器仍可能执行，因此零匹配不等于数据库没有任何副作用。

这些限制只作用于表构建器；`raw()` / `exec()` 在调用时已经执行，不能在后面补 `limit()` 限制原始 SQL。`first()` 保留逐行游标语义，不自动改写为单行查询；确实只需要一行时可用 `limit(1)->first()`。事务中每条已构建语句执行后清空限制，下一条从“不限量”开始。

## 事务要使用回调提供的对象

```php
$database->transaction(static function ($transaction): void {
    $transaction->table('users')->where('id = ?', 1)->update('name', 'updated');
    $transaction->insert('audit_log', ['message' => 'user updated']);
});
```

回调正常结束后提交，抛异常则回滚并保留原始异常。事务期间不能通过外部 `$database` 或提前保存的 builder 偷用同一连接。手动事务请使用 `beginTransaction()` 返回的对象，并明确 `commit()` / `rollback()`；丢弃未结束的事务会尝试回滚。不能通过裸 `BEGIN/COMMIT/ROLLBACK` 绕过此生命周期，也不承诺嵌套事务或自动保存点。

已确认断线的事务不能继续执行或提交，也不会换一条连接“接着做”。游标关闭失败时仍会尝试回滚，保留先发生的错误。数据库业务错误是否使事务进入失败状态，仍遵循数据库本身的规则；不要捕获后忽略错误并假定后续提交必然有效。

## 结果与故障处理

| 情况 | 行为 |
| --- | --- |
| `queryColumn()` | 保留 `0`、空字符串、`false`、`null`，不会提前截断 |
| `value()` | 字段值为 `null` 时正常返回；不存在的行或字段仍报错 |
| `queryOne()` / `queryScalar()` | 每次读取下一行；未读完的池化结果继续占用连接 |
| `queryAll()` / `queryColumn()` | 读取剩余结果后归还池化连接；销毁结果对象也会清理游标 |
| SQL 或网络错误 | 保留原生异常；PDO 仅返回失败时转为下述 RC 异常，不重发 SQL、不重试事务 |
| 已标记断线后的新查询 | 仅新的非池化连接借用会尝试重新连接；不恢复上一条语句 |

写入后断线可能意味着“服务端已执行，客户端没收到确认”，不能据此认定写入失败或直接重试。订单、扣款等操作仍需业务幂等键和明确的补偿策略。

断线判断优先读取原生 SQLSTATE/驱动状态，不因重复键值、列名或业务异常里出现 “server has gone away” 等文字就丢弃健康连接。查询取消不等于断线；空闲会话被服务端关闭则按断线处理。未识别的驱动状态仍保留原来的兼容判断，不代表全部 PDO 驱动、语言和错误场景均已验证。

不会强制修改 `PDO::ATTR_ERRMODE`。静默/警告模式中的原生 `false` 失败，由 `RC\Helper\Db\PdoFailure`（继承 `PDOException`）携带失败对象当时的 `errorInfo` 抛出；`getCode()` 为 SQLSTATE，驱动代码在 `errorInfo[1]`，不是把驱动整数误当作 SQLSTATE。原生已抛出的异常不包装；警告模式仍产生原生警告。依据：[PDO 错误模式](https://www.php.net/manual/en/pdo.error-handling.php)。

`queryAll()` / `queryColumn()` 也检查静默读取错误，不把部分数组当作完整结果。逐行接口已经交给调用方的行无法撤回，但在遇到读取错误时会抛异常，不伪装成正常 EOF。结果释放时关闭失败的池连接会被丢弃，不再归还；这些清理操作不会覆盖更早的业务异常。

`lastInsertId()` 遵循 PDO 语义；PostgreSQL 需要确切主键时使用明确的 `RETURNING`，不猜测表名/序列。事务释放前的自动缓存对 PostgreSQL 延后到成功提交之后，避免 `LASTVAL` 在未使用序列时破坏事务；没有可用序列 ID 时缓存空字符串。开发者在事务内显式调用仍遵循原生行为。可选 ID 缓存失败不会把已确认提交的写入报告为提交失败。

调试记录保留 SQL 模板与绑定值，不生成可执行的插值 SQL；日志可能包含业务数据，交给应用控制访问和脱敏。

## 连接池边界

本轮没有增加 Workerman 协程支持。旧 `startPool()` 依赖真实 Swoole 协程上下文，不满足条件时明确报错，并保留原同步连接。**真实 Swoole 调度、等待/回收和长期池压力尚未验收**；PHP 测试中的归还计数替身只证明结果对象的资源归属，不能代替实际连接池测试。

当前回归覆盖 SQLite、MySQL、PostgreSQL 的列值、绑定、事务、分页、失败清理，以及所列会话模式下的真实数组读写。原生/模拟预处理分开记录，不把模拟模式的底层失败当作成功。其他 PDO 方言、全部 SQL mode 组合、真实中途断网及提交确认丢失仍需单独验证；不能把当前测试理解为任意 SQL 语法、数据库或并发模式都已支持。

## 上游与加载方式

以稳定发布为基础：Mix Database **3.0.23**、Mix Redis **3.0.11**、Mix Object Pool **3.0.27**，来源及逐文件哈希见 `src/ThirdParty/mix-*/upstream-manifest.json`。原归档声明 Apache-2.0 但未携带许可正文，目录中另附官方未修改的许可文本并注明来源。

RC 复用原版 Driver、Expr 数据结构和 ObjectPool 基础类型；查询/事务核心保留为有明确标注的 RC 派生适配，修复旧插值、递归重发、隐式可空参数和连接归属问题。直接使用上游 `Mix\Database\Database` **不等同于使用本适配**，不能把 RC 结果当成全部原生 SQL、故障恢复和连接池行为的验收。

### PHP 8.5 声明修订

内置的 `AbstractConnection.php`、`Connection.php` 各给 `queryOne()` / `queryAll()` 的 `$fetchStyle` 补上显式 `?int`，共四个 `?`。旧写法 `int $fetchStyle = null` 本来就接受 null，Reflection 也已经返回 `?int`，但 PHP 8.4 起会在解析声明时发出弃用提示。修订不改方法体、参数名、默认值、返回类型或 SQL，不增加运行时包装和错误屏蔽。[PHP 官方说明](https://www.php.net/manual/en/migration84.deprecated.php#migration84.deprecated.core.implicitly-nullable-parameter)

这两份文件不再称为“未经修改的原版”。清单的 `files` 保留发布源码哈希，`compatibility_patches` 单列修订后哈希；审计会反向去掉每份文件的两个 `?`，核对是否逐字节还原原版。其他原版文件保持原样。

清单的 `runtime_sha256` 指修订后的明文源码，不是加密 PHAR 条目的哈希。加密交付另核对实际保护字节与加载来源，不在运行中拿密文与明文哈希比较。

该修订只作用于框架内置副本，不改用户通过 Composer 安装的 `mix/database`。RC 仍优先复用 Composer 的 Driver/Expr 基础类型，并且不加载其原生查询核心；独立原生声明测试在隔离子进程中明确选择内置副本，不冒充外部 Composer 查询类已被修复。

兼容版本的 Composer 包优先，否则由相邻 bootstrap 加载内置副本；路径保留 `phar://`，无需改 `public_path()` 或改成外部目录。Mix Redis 的适配与边界另见[独立说明](../../Redis/mix/README.md)，不以本页数据库结果代替其验收。
