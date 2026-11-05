# RCMAKER PHP

**用 PHP 快速开发，像 Go 应用一样交付。**

简体中文 | [English](https://github.com/runchance/rcmaker-php/blob/HEAD/README.en.md)

[官网](https://rcmaker.runchance.com) · [操作手册](https://rcmaker.runchance.com/doc/) · [GitHub](https://github.com/runchance/rcmaker-php)

RCMAKER 是面向快速交付的 PHP 常驻内存应用框架。继续使用熟悉的 PHP 与 Composer 生态，开发接口、后台和多应用系统；交付时，将运行环境、代码与依赖封装为支持代码保护的独立可执行程序。目标机器无需另装 PHP，直接启动。

`runchance/rcmaker-php` 将框架核心、项目模板和交互式工具统一维护。安装依赖后，由框架生成应用目录和配置，不再单独维护一套项目壳。生成后的业务与配置归项目所有，更新框架不会自动覆盖它们。

## 核心优势

| 能力 | 带来的价值 |
| --- | --- |
| 常驻内存，高效并发 | 减少每次请求的重复加载与初始化，基于 Workerman 处理高频接口和长期运行任务 |
| 独立程序交付 | 打包 PHP、代码和依赖；支持 Linux、macOS、Windows，可按需精简内置组件 |
| 源码与静态资源保护 | 按需加密 PHP 和静态资源，源码开发、打包保护，便于产品分发 |
| 多应用与独立进程组 | 按业务配置端口、进程数和重启策略；多个应用也可共享一组进程 |
| 静态应用与预热 | 支持独立静态站点；Linux fork 前全局预热，Windows 按应用进程组预热 |
| 丰富的内置组件 | 验证器、自动表单、SDB、JWT、缓存、Curl、验证码、Excel、PDF 等，少做重复封装 |
| 交互式项目工具 | 打包、加密、注册服务、签名密钥、AI DevKit，通过 `interact` 完成 |
| 免费开源 | Apache License 2.0，保留 PHP 与 Composer 生态的选择空间 |

高并发能力取决于硬件、进程配置与业务开销，空接口压测不等于数据库业务性能。支持代码保护也不代表程序无法被逆向分析。

## 快速开始

需要 **PHP 8.5.12 或更高版本**及 Composer 2.2+，只使用第三方稳定版。默认采用内置 Medoo + SQLite、文件缓存和文件 Session，不需要另装数据库服务或 Redis；PHP 需启用 `pdo_sqlite`。

```shell
mkdir my-app
cd my-app
composer require runchance/rcmaker-php
php index.php start
```

首次安装时，允许 Composer 加载本包插件，再确认初始化。访问 `http://127.0.0.1:8680/`，看到 `Hello rcmaker!` 即可开始开发。

Windows 初始化时还会生成 `windows.bat`，支持双击启动。`.env.example` 提供完整环境配置参考，默认仍保持独立运行，不自动启用外部服务。

禁用插件或跳过初始化时，手动执行：

```shell
php vendor/bin/rcmaker init
composer dump-autoload
php index.php start
```

日常交付操作使用：

```shell
php index.php interact
```

开发环境可以使用 SQLite / File，Linux 生产环境按配置切换 MySQL、PostgreSQL、Redis，不把驱动写死在业务代码中。需要 AI 辅助开发时，可在 `interact` 中安装 RCMAKER AI DevKit。

## 操作手册

| 目标 | 在线说明 |
| --- | --- |
| 安装 PHP、Composer 并启动 | [安装与启动](https://rcmaker.runchance.com/doc/#/md/install) |
| 了解初始化及文件保护规则 | [项目初始化](https://rcmaker.runchance.com/doc/#/md/project-init) |
| 从旧 rcmaker / rcmaker-framework 切换 | [老项目迁移步骤](https://rcmaker.runchance.com/doc/#/md/migration) |
| 编写业务与使用内置组件 | [5 分钟开始](https://rcmaker.runchance.com/doc/#/md/quick-start) · [组件手册](https://rcmaker.runchance.com/doc/) |
| 拆分动态、静态应用 | [应用进程组](https://rcmaker.runchance.com/doc/#/md/app-process) · [静态预热](https://rcmaker.runchance.com/doc/#/md/static-directory) |
| 打包与部署产品 | [交互式项目工具](https://rcmaker.runchance.com/doc/#/md/interact) · [按需打包](https://rcmaker.runchance.com/doc/#/md/build-components) |

老项目不要直接覆盖 `.env`、配置或替换 `vendor/`。迁移工具只调整依赖清单并保留备份，先在项目副本中验证，再发布。
