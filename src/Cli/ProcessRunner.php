<?php

declare(strict_types=1);

namespace RC\Cli;

use RuntimeException;

/** Synchronous CLI commands with explicit stdin and native output inheritance. */
final class ProcessRunner
{
    /** Wait for the actual child exit; allowFailure controls exceptions, never the child's output. */
    public static function run(array $command, ?string $workingDirectory = null, bool $allowFailure = false): int
    {
        // Casting PHP stdout/stderr streams can seek back and overwrite redirected logs.
        $process = proc_open(
            array_map(static fn($part): string => (string)$part, $command),
            [0 => STDIN],
            $pipes,
            $workingDirectory,
            null,
            PHP_OS_FAMILY === 'Windows' ? ['bypass_shell' => true] : []
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Failed to start process: ' . self::display($command));
        }
        $exitCode = proc_close($process);
        if ($exitCode !== 0 && !$allowFailure) {
            throw new RuntimeException('Command failed with exit code ' . $exitCode . ': ' . self::display($command));
        }
        return $exitCode;
    }

    /** Format diagnostics only; execution always uses the argument array without a shell. */
    public static function display(array $command): string
    {
        return implode(' ', array_map(static function ($argument): string {
            $argument = (string)$argument;
            if ($argument !== '' && preg_match('/^[A-Za-z0-9_@.\/:=+,-]+$/', $argument)) {
                return $argument;
            }
            if (PHP_OS_FAMILY === 'Windows') {
                return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $argument) . '"';
            }
            return escapeshellarg($argument);
        }, $command));
    }
}
