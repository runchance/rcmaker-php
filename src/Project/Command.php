<?php

declare(strict_types=1);

namespace RC\Project;

/** Bootless init/migrate CLI; call through php vendor/bin/rcmaker, not the application entry. */
final class Command
{
    /** Dispatch explicit commands; unattended writes require --yes and previews never write. */
    public static function run(array $arguments): int
    {
        try {
            [$command, $options] = self::parse($arguments);
            if ($command === 'help' || isset($options['help'])) {
                self::help();
                return 0;
            }
            $files = new ProjectFiles($options['project'] ?? (getcwd() ?: '.'));
            $migration = new Migration();
            if ($command === 'init') {
                $plan = (new Initializer())->plan($files);
            } elseif ($command === 'migrate') {
                $plan = isset($options['rollback'])
                    ? $migration->rollback($files, $options['rollback'])
                    : $migration->plan($files, $options['constraint'] ?? '*@stable');
            } else {
                throw new \InvalidArgumentException('Unknown command: ' . $command);
            }
            fwrite(STDOUT, "RCMAKER / {$command}\n项目：{$files->root}\n");
            foreach ($plan['changes'] as $path => $change) {
                fwrite(STDOUT, ($change['before'] === null ? '  + ' : '  ~ ') . $path . "\n");
            }
            foreach ($plan['kept'] ?? [] as $path) {
                fwrite(STDOUT, '  = 保留 ' . $path . "\n");
            }
            foreach ($plan['warnings'] ?? [] as $warning) {
                fwrite(STDOUT, '[!] ' . $warning . "\n");
            }
            if (isset($options['dry-run'])) {
                fwrite(STDOUT, "预览完成，未写入任何文件。\n");
                return 0;
            }
            if ($plan['changes'] === []) {
                fwrite(STDOUT, "无需更改。\n");
                return 0;
            }
            if (!isset($options['yes']) && !self::confirm()) {
                fwrite(STDOUT, "已取消。无人值守执行请先预览，再使用 --yes。\n");
                return 1;
            }
            if ($command === 'migrate' && !isset($options['rollback'])) {
                $id = $migration->apply($files, $plan);
                fwrite(STDOUT, '迁移备份：data/migration/' . $id . "\n");
            } else {
                $files->commit($plan['changes']);
            }
            fwrite(STDOUT, $command === 'init'
                ? "初始化完成。运行 composer install（已安装依赖时用 composer dump-autoload），然后 php index.php start。\n"
                : "清单已更新。请在项目副本中执行 composer update --with-all-dependencies 并验证，再切换生产环境。\n");
            return 0;
        } catch (\Throwable $error) {
            fwrite(STDERR, '[ERROR] ' . $error->getMessage() . "\n");
            return 1;
        }
    }

    /** Parse the deliberately small option set, rejecting typos before changing files. */
    private static function parse(array $arguments): array
    {
        $command = $arguments[1] ?? 'help';
        if ($command === '--help' || $command === '-h') {
            $command = 'help';
        }
        $options = [];
        for ($index = 2; $index < count($arguments); ++$index) {
            $argument = $arguments[$index];
            [$name, $value] = array_pad(explode('=', $argument, 2), 2, null);
            if (!str_starts_with($name, '--')) {
                throw new \InvalidArgumentException('Unexpected argument: ' . $argument);
            }
            $name = substr($name, 2);
            if (isset($options[$name])) {
                throw new \InvalidArgumentException('Repeated option: ' . $name);
            }
            if (in_array($name, ['dry-run', 'yes', 'help'], true)) {
                if ($value !== null) {
                    throw new \InvalidArgumentException('This option takes no value: ' . $name);
                }
                $options[$name] = true;
            } elseif (in_array($name, ['project', 'constraint', 'rollback'], true)) {
                $value ??= $arguments[++$index] ?? null;
                if ($value === null || $value === '' || str_starts_with($value, '--')) {
                    throw new \InvalidArgumentException('Missing option value: ' . $name);
                }
                $options[$name] = $value;
            } else {
                throw new \InvalidArgumentException('Unknown option: ' . $name);
            }
        }
        if ($command !== 'migrate' && (isset($options['constraint']) || isset($options['rollback']))) {
            throw new \InvalidArgumentException('--constraint and --rollback are only valid with migrate.');
        }
        if (isset($options['constraint'], $options['rollback'])) {
            throw new \InvalidArgumentException('--constraint cannot be combined with --rollback.');
        }
        return [$command, $options];
    }

    /** Ask once on a real terminal; piped input cannot accidentally authorize project writes. */
    private static function confirm(): bool
    {
        if (!stream_isatty(STDIN)) {
            return false;
        }
        fwrite(STDOUT, '确认执行以上更改？[y/N]：');
        return in_array(strtolower(trim((string) fgets(STDIN))), ['y', 'yes'], true);
    }

    /** Print concise usage without loading Composer or starting any application. */
    private static function help(): void
    {
        fwrite(STDOUT, <<<'HELP'
RCMAKER 项目工具

  php vendor/bin/rcmaker init --dry-run
  php vendor/bin/rcmaker init --yes
  php /path/to/rcmaker-php/bin/rcmaker migrate --project=/path/to/old-project --dry-run
  php /path/to/rcmaker-php/bin/rcmaker migrate --project=/path/to/old-project --yes
  php /path/to/rcmaker-php/bin/rcmaker migrate --rollback=备份ID --yes

选项：--project=项目目录  --dry-run  --yes  --constraint=稳定版约束（迁移时可选）
默认不覆盖已有文件。运行框架需要 PHP >= 8.5.12。
初始化后：php index.php start / php index.php interact

HELP);
    }
}
