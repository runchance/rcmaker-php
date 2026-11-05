<?php

declare(strict_types=1);

namespace RC\Runtime;

use Workerman\Worker;

/** @internal Adapt native lifecycle and command validation without changing Workerman sources. */
final class WorkermanLifecycle extends Worker
{
    /** Preserve RC PID-validation failure after native runAll logs and catches the exception. */
    private static bool $invalidMasterPid = false;

    /** Return the native master identity inherited at fork; unlike a PID file it is not user-editable runtime data. */
    public static function getMasterPid(): int
    {
        return parent::$masterPid;
    }

    /** Reject group/broadcast PIDs and malformed positive PID files before native CLI command dispatch can signal them. */
    protected static function checkMasterIsAlive(int $masterPid): bool
    {
        if ($masterPid < 0) {
            self::$invalidMasterPid = true;
            throw new \RuntimeException('Invalid master PID; no process signal was sent. Check the runtime PID file.');
        }
        if ($masterPid > 0) {
            $raw = @file_get_contents(static::$pidFile, false, null, 0, 65);
            $text = is_string($raw) ? trim($raw, " \t\r\n") : '';
            if (!is_string($raw) || strlen($raw) > 64 || preg_match('/\A[0-9]+\z/D', $text) !== 1
                || ltrim($text, '0') !== (string) $masterPid) {
                self::$invalidMasterPid = true;
                throw new \RuntimeException('Invalid or changed master PID file; no process signal was sent.');
            }
        }
        try {
            return parent::checkMasterIsAlive($masterPid);
        } catch (\ValueError $error) {
            self::$invalidMasterPid = true;
            throw new \RuntimeException('Invalid master PID for this platform; no process signal was sent.', 0, $error);
        }
    }

    /** Release master clients before native daemonization forks as well as ordinary APP child creation. */
    public static function runAll(): void
    {
        global $argv;
        self::$invalidMasterPid = false;
        if (PHP_OS_FAMILY !== 'Windows' && in_array($argv[1] ?? '', ['start', 'restart'], true)
            && class_exists(\RC\Helper\Cache\MemcacheStore::class, false)) {
            \RC\Helper\Cache\MemcacheStore::prepareForFork();
        }
        parent::runAll();
        if (self::$invalidMasterPid) {
            exit(1);
        }
    }

    /** Native late-static dispatch covers initial starts, reloads and replacement children without patching Workerman. */
    protected static function forkOneWorkerForLinux(Worker $worker): void
    {
        if (class_exists(\RC\Helper\Cache\MemcacheStore::class, false)) {
            \RC\Helper\Cache\MemcacheStore::prepareForFork();
        }
        parent::forkOneWorkerForLinux($worker);
    }

    /** Called only after an APP child drains HTTP connections; preserve native queue/log graceful-stop hooks. */
    public static function stopGracefully(): void
    {
        parent::$gracefulStop = true;
        parent::stopAll();
    }
}
