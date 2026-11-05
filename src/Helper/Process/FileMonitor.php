<?php

namespace RC\Helper\Process;

/** Poll trusted development paths and request one reload for a batch of observed changes. */
class FileMonitor
{
    protected $_paths = [];
    protected $_extensions = [];
    protected $_timer = null;
    protected $_work = null;
    protected $_type = 'workerman';
    protected $_staticMode = true;
    protected static $_static = [];

    private array $snapshots = [];
    private array $iterators = [];
    private array $scanFailures = [];
    private array $pending = [];
    private bool $polling = false;
    private bool $started = false;
    private bool $reloadFailed = false;
    private int $ownerPid;
    private ?int $parentPid = null;

    /** Keep the existing constructor; staticMode reuses directory iterators, never another monitor's state. */
    public function __construct($type, $work, $timer, $monitor_dir, $monitor_extensions, $staticMode = true)
    {
        $this->_type = $type;
        $this->ownerPid = getmypid();
        if (DIRECTORY_SEPARATOR === '/' && function_exists('posix_getppid')) { $this->parentPid = \posix_getppid(); }
        $this->_work = $work;
        $this->_timer = $timer;
        $this->_extensions = (array) $monitor_extensions;
        $this->_staticMode = (bool) $staticMode;
        foreach ((array) $monitor_dir as $path) {
            if (!is_string($path) || $path === '' || str_contains($path, "\0")) {
                throw new \InvalidArgumentException('File monitor paths must be nonempty strings without NUL.');
            }
            $this->_paths[] = DIRECTORY_SEPARATOR === '\\' ? str_replace('\\', '/', $path) : $path;
        }
        $this->_paths = array_values(array_unique($this->_paths));
        $this->start();
    }

    /** Establish a baseline before the first timer tick; repeated start calls do not create extra timers. */
    public function start()
    {
        if ($this->started) { return; }
        foreach ($this->_paths as $path) { $this->snapshots[$path] = $this->snapshot($path); }
        $callback = function (): void {
            $this->polling = true;
            try { foreach ($this->_paths as $path) { $this->check_files_change($path); } }
            finally { $this->polling = false; }
            $this->reloadPending();
        };
        if ($this->_type === 'workerman') { $this->_timer::add(1, $callback); }
        elseif ($this->_type === 'swoole') { $this->_timer::tick(1000, $callback); }
        $this->started = true;
    }

    /** Poll one path using timer state; new ad-hoc paths first establish their baseline. */
    public function check_files_change($monitor_dir)
    {
        if (!is_string($monitor_dir) || $monitor_dir === '' || str_contains($monitor_dir, "\0")) {
            throw new \InvalidArgumentException('Invalid file monitor path.');
        }
        if (DIRECTORY_SEPARATOR === '\\') { $monitor_dir = str_replace('\\', '/', $monitor_dir); }
        $current = $this->snapshot($monitor_dir);
        if ($current === null) { return; }
        $previous = $this->snapshots[$monitor_dir] ?? null;
        $this->snapshots[$monitor_dir] = $current;
        if ($previous !== null) {
            foreach ($current as $path => $metadata) {
                if (!isset($previous[$path]) || $previous[$path] !== $metadata) { $this->pending[$path] = $metadata; }
            }
            foreach ($previous as $path => $metadata) {
                if (!isset($current[$path])) { $this->pending[$path] = null; }
            }
        }
        if (!$this->polling) { $this->reloadPending(); }
    }

    /** Stat eligible entries without content hashing or traversal through nested directory links. */
    private function snapshot(string $root): ?array
    {
        clearstatcache();
        $stat = @stat($root);
        if ($stat === false && !file_exists($root)) {
            unset($this->iterators[$root], $this->scanFailures[$root]);
            return [];
        }
        try {
            if ($stat === false) { throw new \RuntimeException('Cannot stat monitored path.'); }
            if (($stat['mode'] & 0170000) === 0100000) {
                unset($this->iterators[$root]);
                $files = [$root];
            } elseif (($stat['mode'] & 0170000) === 0040000) {
                $identity = [$stat['dev'], $stat['ino']];
                if (!$this->_staticMode) {
                    unset($this->iterators[$root]);
                    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
                } else {
                    if (!isset($this->iterators[$root]) || $this->iterators[$root]['identity'] !== $identity) {
                        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
                        $this->iterators[$root] = ['identity' => $identity, 'iterator' => $iterator];
                    }
                    $files = $this->iterators[$root]['iterator'];
                }
            } else { return []; }
            $result = [];
            foreach ($files as $entry) {
                $path = $entry instanceof \SplFileInfo ? $entry->getPathname() : $entry;
                if (DIRECTORY_SEPARATOR === '\\') { $path = str_replace('\\', '/', $path); }
                if (basename($path) !== '.env' && !in_array(pathinfo($path, PATHINFO_EXTENSION), $this->_extensions, true)) { continue; }
                $file = $path === $root ? $stat : @stat($path);
                if ($file === false || ($file['mode'] & 0170000) !== 0100000) { continue; }
                $result[$path] = [$file['mtime'], $file['ctime'], $file['size'], $file['dev'], $file['ino']];
            }
            unset($this->scanFailures[$root]);
            return $result;
        } catch (\RuntimeException $error) {
            unset($this->iterators[$root]);
            if (!isset($this->scanFailures[$root])) { echo '[file-monitor] Scan failed; previous state retained: ', $root, "\n"; }
            $this->scanFailures[$root] = true;
            return null;
        }
    }

    /** Validate changed PHP and invalidate every selected cache entry before one reload per polling batch. */
    private function reloadPending(): void
    {
        foreach ($this->pending as $path => $metadata) {
            if ($metadata !== null && pathinfo($path, PATHINFO_EXTENSION) === 'php' && !$this->validPhp($path)) { unset($this->pending[$path]); }
        }
        if (!$this->pending) { return; }
        foreach (array_keys($this->pending) as $path) {
            if (function_exists('opcache_invalidate')) { @opcache_invalidate($path, true); }
        }
        if (DIRECTORY_SEPARATOR === '/' && function_exists('posix_kill') && function_exists('posix_getppid')) {
            $parent = \posix_getppid();
            if ($this->ownerPid !== getmypid()) {
                $this->ownerPid = getmypid();
                $this->parentPid = $parent;
            }
            // A container master may be PID 1; an adopted orphan must not signal a different parent.
            if ($parent < 1 || $parent !== $this->parentPid || !defined('SIGUSR1') || !@\posix_kill($parent, \SIGUSR1)) {
                if (!$this->reloadFailed) { echo "[file-monitor] Reload signal failed; pending changes retained.\n"; }
                $this->reloadFailed = true;
                return;
            }
        } else {
            echo implode(', ', array_keys($this->pending)), " update and reload\n";
            exit(0);
        }
        echo implode(', ', array_keys($this->pending)), " update and reload\n";
        $this->pending = [];
        $this->reloadFailed = false;
    }

    /** Bound genuine CLI lint output/time without a shell; Micro must not be started recursively as php -l. */
    private function validPhp(string $path): bool
    {
        if (PHP_SAPI !== 'cli' || !function_exists('proc_open')) {
            echo '[file-monitor] PHP CLI syntax checking is unavailable; change ignored: ', $path, "\n";
            return false;
        }
        // Windows anonymous pipes may block despite stream_set_blocking(false). Read only after the child exits.
        $capture = @tmpfile();
        if (!is_resource($capture)) { echo '[file-monitor] Cannot stage PHP syntax output: ', $path, "\n"; return false; }
        $process = @proc_open([PHP_BINARY, '-d', 'short_open_tag=' . ini_get('short_open_tag'), '-l', $path],
            [0 => ['pipe', 'r'], 1 => $capture, 2 => ['redirect', 1]],
            $pipes, null, null, PHP_OS_FAMILY === 'Windows' ? ['bypass_shell' => true] : []);
        if (!is_resource($process)) { fclose($capture); echo '[file-monitor] Cannot start PHP syntax check: ', $path, "\n"; return false; }
        fclose($pipes[0]);
        $deadline = hrtime(true) + 5000000000; $output = ''; $exit = -1; $timedOut = $outputLimited = false;
        try {
            do {
                $status = proc_get_status($process);
                $size = fstat($capture)['size'] ?? 0;
                if ($size > 8192) {
                    $outputLimited = true;
                    if ($status['running']) { proc_terminate($process); }
                    else { $exit = $status['exitcode']; }
                    break;
                }
                if (!$status['running']) { $exit = $status['exitcode']; break; }
                if (hrtime(true) >= $deadline) { $timedOut = true; proc_terminate($process); break; }
                usleep(10000);
            } while (true);
        } finally {
            $closed = proc_close($process);
            if ($exit < 0) { $exit = $closed; }
            rewind($capture); $output = stream_get_contents($capture, 8192); fclose($capture);
        }
        if ($exit === 0 && !$timedOut && !$outputLimited) { return true; }
        echo '[file-monitor] PHP syntax check failed', $timedOut ? ' (timeout)' : ($outputLimited ? ' (output limit)' : ''), ': ', $path, "\n";
        if ($output !== '') { echo rtrim($output), "\n"; }
        return false;
    }
}
