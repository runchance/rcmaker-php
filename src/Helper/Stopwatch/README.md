# 计时器兼容入口

简体中文 | [English](README.en.md)

此目录保留旧 `RC\Helper\Stopwatch\...` 类名，通过别名复用原版 Symfony Stopwatch 8.1.x，不再维护改名后的计时引擎。

兼容的 Composer 安装优先；未安装时加载 `src/ThirdParty/symfony-stopwatch/` 原版 8.1.0。依赖的 Service Contracts 仅内置原版 `ResetInterface`，不代表安装了整个 contracts 包，其他接口/trait 请通过 Composer 使用。

框架使用 `RC\Stopwatch` 和 `stopwatch($name)`；独立或异步任务可自行创建计时器对象。兼容行为、精度与生命周期见[迁移说明](../../../data/docs/stopwatch-migration.md)。
