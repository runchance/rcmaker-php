<?php

declare(strict_types=1);

namespace RC\Cli;

use RuntimeException;

/** Register literal project paths with systemd; command parsing is not shell parsing. */
final class SystemdService
{
    private const SERVICE_DIRECTORY = '/etc/systemd/system';
    private const TEMPLATE = <<<'SERVICE'
[Unit]
Description=rcmaker {name} Service
After=network.target

[Service]
Type=simple
WorkingDirectory={workingDirectory}
ExecStart={command} start
ExecReload={command} reload
ExecStop={command} stop
User={user}
Group={group}

[Install]
WantedBy=multi-user.target
SERVICE;

    public static function execute(string $rootPath, array $options, ?callable $output = null): array
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            throw new RuntimeException('systemd service management is available only on Linux.');
        }
        if (!function_exists('posix_getuid') || posix_getuid() !== 0) {
            throw new RuntimeException('systemd service management requires root privileges.');
        }

        $rootPath = realpath($rootPath) ?: $rootPath;
        $name = trim((string)($options['name'] ?? 'rcmaker'));
        $operation = strtolower(trim((string)($options['operation'] ?? 'add')));
        $user = trim((string)($options['user'] ?? 'root'));
        $mode = strtolower(trim((string)($options['mode'] ?? 'php')));
        $phpBinary = trim((string)($options['php'] ?? PHP_BINARY));

        if (!preg_match('/^[a-z][a-z0-9_-]{0,19}$/', $name)) {
            throw new RuntimeException('Invalid service name.');
        }
        if (!in_array($operation, ['add', 'remove'], true)) {
            throw new RuntimeException('Service operation must be add or remove.');
        }
        if (!in_array($mode, ['php', 'bin'], true)) {
            throw new RuntimeException('Service mode must be php or bin.');
        }
        if (!preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $user)) {
            throw new RuntimeException('Invalid service user.');
        }

        $servicePath = self::SERVICE_DIRECTORY . '/' . $name . '.service';
        $serviceUnit = $name . '.service';
        if ($operation === 'remove') {
            if (!is_file($servicePath)) {
                throw new RuntimeException('Service file does not exist: ' . $servicePath);
            }
            ProcessRunner::run(['systemctl', 'stop', $serviceUnit], $rootPath, true);
            ProcessRunner::run(['systemctl', 'disable', $serviceUnit], $rootPath, true);
            Filesystem::removeFile($servicePath);
            ProcessRunner::run(['systemctl', 'daemon-reload'], $rootPath);
            self::emit($output, 'Service removed: ' . $serviceUnit);
            return ['operation' => 'remove', 'name' => $name, 'path' => $servicePath];
        }

        if (!function_exists('posix_getpwnam')) {
            throw new RuntimeException('posix_getpwnam is required to resolve the service user.');
        }
        $userInfo = posix_getpwnam($user);
        if ($userInfo === false) {
            throw new RuntimeException('Service user does not exist: ' . $user);
        }
        $groupInfo = function_exists('posix_getgrgid') ? posix_getgrgid($userInfo['gid']) : false;
        $group = is_array($groupInfo) && !empty($groupInfo['name']) ? $groupInfo['name'] : $user;

        if ($mode === 'bin') {
            $binary = $rootPath . DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR . 'rcmaker.bin';
            if (!is_file($binary) || !is_executable($binary)) {
                throw new RuntimeException('build/rcmaker.bin does not exist or is not executable.');
            }
            $command = self::quote($binary);
        } else {
            if ($phpBinary === '' || !is_file($phpBinary) || !is_executable($phpBinary)) {
                throw new RuntimeException('PHP executable does not exist or is not executable: ' . $phpBinary);
            }
            $entry = $rootPath . DIRECTORY_SEPARATOR . 'index.php';
            if (!is_file($entry)) {
                throw new RuntimeException('Project index.php does not exist: ' . $entry);
            }
            $command = self::quote($phpBinary) . ' ' . self::quote($entry, true);
        }

        $contents = strtr(self::TEMPLATE, [
            '{name}' => $name,
            '{command}' => $command,
            '{workingDirectory}' => self::workingDirectory($rootPath),
            '{user}' => $user,
            '{group}' => $group,
        ]) . "\n";

        $temporary = self::SERVICE_DIRECTORY . '/.' . $name . '.service.tmp-' . bin2hex(random_bytes(4));
        if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)) {
            @unlink($temporary);
            throw new RuntimeException('Failed to write temporary service file: ' . $temporary);
        }
        @chmod($temporary, 0644);
        if (!rename($temporary, $servicePath)) {
            @unlink($temporary);
            throw new RuntimeException('Failed to install service file: ' . $servicePath);
        }

        ProcessRunner::run(['systemctl', 'daemon-reload'], $rootPath);
        ProcessRunner::run(['systemctl', 'enable', $serviceUnit], $rootPath);
        self::emit($output, 'Service registered: ' . $serviceUnit);
        return ['operation' => 'add', 'name' => $name, 'path' => $servicePath, 'mode' => $mode];
    }

    /** Executable paths stay literal; later arguments additionally escape systemd's environment substitution. */
    private static function quote(string $value, bool $argument = false): string
    {
        if ($value === '' || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new RuntimeException('Invalid path in systemd command.');
        }
        $quoted = str_replace(['\\', '"', '%'], ['\\\\', '\\"', '%%'], $value);
        if ($argument) { $quoted = str_replace('$', '$$', $quoted); }
        return '"' . $quoted . '"';
    }

    /**
     * WorkingDirectory is a path directive, not an Exec command line. Quotes
     * would become part of the path on systemd versions that parse it directly.
     */
    private static function workingDirectory(string $path): string
    {
        if (!str_starts_with($path, '/')) {
            throw new RuntimeException('systemd working directory must be an absolute path: ' . $path);
        }
        if (preg_match('/[\x00-\x20\x7f"\\\\]/', $path)) {
            throw new RuntimeException('systemd working directory cannot contain whitespace, quotes or backslashes: ' . $path);
        }
        return str_replace('%', '%%', $path);
    }

    private static function emit(?callable $output, string $message): void
    {
        if ($output !== null) {
            $output($message);
        }
    }
}
