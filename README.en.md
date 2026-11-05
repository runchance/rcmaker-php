# RCMAKER PHP

**Build quickly with PHP. Deliver a standalone application.**

[简体中文](https://github.com/runchance/rcmaker-php/blob/HEAD/README.md) | English

[Website](https://rcmaker.runchance.com) · [Online Manual](https://rcmaker.runchance.com/doc/) · [GitHub](https://github.com/runchance/rcmaker-php)

RCMAKER is a resident-memory PHP application framework focused on fast delivery. Keep PHP and Composer for development, then package the runtime, application and dependencies into a standalone executable with optional code protection. The target machine does not need a separate PHP installation.

`runchance/rcmaker-php` maintains the runtime, project templates and interactive tools together. Application files are generated once and remain project-owned; framework updates do not automatically overwrite business code or configuration.

## Highlights

| Capability | Benefit |
| --- | --- |
| Resident-memory Workerman runtime | Avoid repeated initialization for high-frequency HTTP requests and long-running tasks |
| Standalone delivery | Package for supported Linux, macOS and Windows targets; optionally omit unused bundled components |
| PHP and static asset protection | Keep development sources readable and protect the build when needed |
| Multiple applications and process groups | Separate ports, worker counts and restart policies, or share a group |
| Static applications and preloading | Preload before fork on Linux and per application group on Windows |
| Integrated components | Validation, AutoForm, SDB, JWT, cache, Curl, captcha, Excel, PDF and more |
| Interactive tooling | Build, encrypt, register services, generate keys and install the AI DevKit |
| Free and open source | Apache License 2.0, with access to the Composer ecosystem |

Throughput depends on hardware, configuration and application work. Empty-response benchmarks are not database benchmarks. Code protection is not a guarantee against reverse engineering.

## Quick Start

Requires **PHP 8.5.12 or newer**, Composer 2.2+ and stable dependencies. Defaults use bundled Medoo, SQLite, file cache and file sessions. Enable `pdo_sqlite`; no external database or Redis service is required.

```shell
mkdir my-app
cd my-app
composer require runchance/rcmaker-php
php index.php start
```

Allow the Composer plugin and confirm project initialization when prompted. Open `http://127.0.0.1:8680/`.

Windows initialization also generates `windows.bat` for double-click startup. `.env` contains only minimal runtime settings; `.env.example` is the complete reference for options you can add as needed. External services are not enabled automatically.

If plugins are disabled or initialization was skipped:

```shell
php vendor/bin/rcmaker init
composer dump-autoload
php index.php start
```

Use `php index.php interact` for delivery tools and optional RCMAKER AI DevKit installation. Keep business code driver-independent when moving from Windows/SQLite/File development to Linux/MySQL/PostgreSQL/Redis production.

## Online Guides

- [Installation](https://rcmaker.runchance.com/doc/#/md/install)
- [Project Initialization](https://rcmaker.runchance.com/doc/#/md/project-init.en)
- [Legacy Project Migration](https://rcmaker.runchance.com/doc/#/md/migration.en)
- [Application Process Groups](https://rcmaker.runchance.com/doc/#/md/app-process)
- [Interactive Tools](https://rcmaker.runchance.com/doc/#/md/interact)
- [Component Builds](https://rcmaker.runchance.com/doc/#/md/build-components.en)

Do not overwrite an existing `.env`, configuration or vendor tree to migrate. Preview the dependency change, keep the generated backup and validate a separate project copy before production deployment.
