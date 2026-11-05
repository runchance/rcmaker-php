<?php

declare(strict_types=1);

namespace RC\Cli;

use Closure;
use RuntimeException;
use Throwable;
use Workerman\Timer;
use Workerman\Worker;

/** @internal Project-local Windows replicas, native shared listeners and owner-scoped lifecycle control. */
final class WindowsSupervisor
{
    private string $owner;
    private string $directory;
    private string $instance;
    private mixed $lock = null;
    private array $targets = [];
    private array $children = [];
    private array $listeners = [];
    private array $history = [];
    private array $restartAt = [];
    private bool $stopping = false;

    /** Supply the existing runtime's plan/command/listener/banner factories; no application bootstrap runs here. */
    public function __construct(private Closure $planFactory, private Closure $commandFactory, private Closure $listenerFactory, private Closure $banner)
    {
        $this->owner = bin2hex(random_bytes(16));
        $this->directory = self::baseDirectory(); $this->instance = $this->directory . '/' . $this->owner;
    }

    /** Start the configured replica plan and supervise only resources opened by this instance. */
    public function run(): int
    {
        Filesystem::mkdir($this->directory);
        $this->lock = fopen($this->directory . '/supervisor.lock', 'c+b');
        if ($this->lock === false || !flock($this->lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('This project already has a Windows supervisor.'); }
        Filesystem::mkdir($this->instance);
        register_shutdown_function(function (): void { $this->cleanup(); });
        try {
            $this->startPlan();
            if ($this->targets === []) { return 0; }
            while (!$this->stopping) {
                $this->handleRequests();
                if ($this->stopping) { break; }
                foreach ($this->targets as $slot => $target) {
                    $child = $this->children[$slot] ?? null;
                    if ($child === null) {
                        if (($this->restartAt[$slot] ?? INF) <= microtime(true)) {
                            try { $this->spawn($slot); unset($this->restartAt[$slot]); }
                            catch (Throwable $error) { $this->restartAt[$slot] = microtime(true) + 5; $this->logExit($target, -1, 'restart_failed'); }
                        }
                        continue;
                    }
                    $state = proc_get_status($child['process']);
                    if ($state['running']) { continue; }
                    $exitPath = $this->instance . '/exit-' . $slot . '.json';
                    $exit = self::readJson($exitPath); @unlink($exitPath);
                    $graceful = $state['exitcode'] === 0 && ($exit['owner'] ?? null) === $this->owner
                        && ($exit['pid'] ?? null) === $child['pid'] && ($exit['reason'] ?? null) === 'graceful';
                    $this->reap($slot);
                    if ($target['monitor'] && $state['exitcode'] === 0) {
                        try { $this->restartPlan(); }
                        catch (Throwable $error) {
                            fwrite(STDERR, '[windows] Monitor reload failed: ' . $error->getMessage() . "\n");
                            $this->restartAt[$slot] = microtime(true) + 5; $this->publish();
                        }
                        continue 2;
                    }
                    if ($graceful) {
                        // max_request retirement is healthy lifecycle, not a repeated startup/crash failure.
                        unset($this->history[$slot]); $this->restartAt[$slot] = microtime(true); $this->publish(); continue;
                    }
                    $recent = array_values(array_filter($this->history[$slot] ?? [], static fn(float $time): bool => $time >= microtime(true) - 60));
                    $recent[] = microtime(true); $this->history[$slot] = $recent;
                    $this->logExit($target, $state['exitcode'], $state['exitcode'] === 0 ? 'normal_exit' : 'unexpected_exit');
                    if (count($recent) < 5) { $this->restartAt[$slot] = microtime(true) + ($state['exitcode'] === 0 ? 0 : min(15, 1 << (count($recent) - 1))); }
                    else { unset($this->restartAt[$slot]); fwrite(STDERR, $target['label'] . " repeatedly exited; automatic restart paused. Other replicas remain running.\n"); }
                    $this->publish();
                }
                usleep(100000);
            }
            return 0;
        } finally { $this->cleanup(); }
    }

    /** Build one native listener per multi-replica group, then spawn its independent PHP processes. */
    private function startPlan(?array $plan = null): void
    {
        $plan ??= ($this->planFactory)(); $this->targets = $plan['targets'];
        foreach ($this->targets as $target) {
            if ($target['count'] > 1 && !empty($target['config']['listen']) && !isset($this->listeners[$target['group']])) {
                $this->listeners[$target['group']] = new WindowsListener(($this->listenerFactory)($target['config']));
            }
        }
        ($this->banner)($plan['specs']); $this->publish();
        foreach (array_keys($this->targets) as $slot) { $this->spawn($slot); }
    }

    /** Hand off one PID-bound socket and private control identity through this child's stdin pipe. */
    private function spawn(string $slot): void
    {
        @unlink($this->instance . '/child-' . $slot . '.json');
        @unlink($this->instance . '/exit-' . $slot . '.json');
        $target = $this->targets[$slot]; $environment = getenv(); $environment['RCMAKER_WINDOWS_MANAGED'] = '1';
        $process = proc_open(($this->commandFactory)($target), [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, null, $environment, ['bypass_shell' => true]);
        if (!is_resource($process)) { throw new RuntimeException('Unable to start Windows replica.'); }
        $pid = proc_get_status($process)['pid'];
        $this->children[$slot] = ['process' => $process, 'pid' => $pid];
        try {
            $id = isset($this->listeners[$target['group']]) ? $this->listeners[$target['group']]->exportTo($pid) : null;
            $line = json_encode(['pid' => $pid, 'parent' => getmypid(), 'owner' => $this->owner, 'slot' => $slot,
                'replica' => $target['replica'], 'count' => $target['count'], 'listener' => $id], JSON_THROW_ON_ERROR) . "\n";
            Filesystem::writeToStream($pipes[0], $line); fflush($pipes[0]);
        } catch (Throwable $error) { proc_terminate($process); $this->reap($slot); throw $error; }
        finally { fclose($pipes[0]); }
        $this->publish();
    }

    /** Reap one owned resource and release only its own listener export. */
    private function reap(string $slot): void
    {
        $child = $this->children[$slot] ?? null;
        if ($child === null) { return; }
        proc_close($child['process']); unset($this->children[$slot]);
        $group = $this->targets[$slot]['group'];
        if (isset($this->listeners[$group])) { $this->listeners[$group]->release($child['pid']); }
    }

    /** Drain selected replicas through their own event loop; force only owned hung children after a bounded wait. */
    private function stopChildren(?string $group = null): void
    {
        $selected = [];
        foreach ($this->children as $slot => $child) {
            if ($group !== null && $this->targets[$slot]['group_name'] !== $group) { continue; }
            self::writeJson($this->instance . '/child-' . $slot . '.json', ['owner' => $this->owner, 'operation' => 'stop']);
            $selected[$slot] = $child;
        }
        $deadline = microtime(true) + 10;
        do {
            foreach ($selected as $slot => $child) {
                if (!proc_get_status($child['process'])['running']) { $this->reap($slot); unset($selected[$slot]); }
            }
            if ($selected !== []) { usleep(20000); }
        } while ($selected !== [] && microtime(true) < $deadline);
        foreach ($selected as $slot => $child) { proc_terminate($child['process']); $this->reap($slot); }
    }

    /** Reload configuration after a file-monitor event; native Linux lifecycle is not involved. */
    private function restartPlan(): void
    {
        // Validate the new topology before stopping any healthy worker.
        $plan = ($this->planFactory)();
        $this->stopChildren();
        foreach ($this->listeners as $listener) { $listener->close(); }
        $this->listeners = $this->history = $this->restartAt = [];
        $this->startPlan($plan);
    }

    /** Apply authenticated local requests to this project and this supervisor generation only. */
    private function handleRequests(): void
    {
        foreach (glob($this->instance . '/request-*.json') ?: [] as $file) {
            $request = self::readJson($file); @unlink($file);
            if (($request['owner'] ?? null) !== $this->owner || !preg_match('/\A[0-9a-f]{32}\z/D', $request['id'] ?? '')) { continue; }
            $error = null;
            try {
                $group = $request['group'] ?? null;
                if ($group !== null && !in_array($group, array_column($this->targets, 'group_name'), true)) { throw new RuntimeException('Unknown process group.'); }
                if ($request['operation'] === 'stop') { $this->stopChildren(); $this->stopping = true; }
                elseif ($request['operation'] === 'reload' && $group === null) { $this->restartPlan(); }
                elseif ($request['operation'] === 'reload') {
                    $this->validateGroupReload($group);
                    $this->stopChildren($group);
                    foreach ($this->targets as $slot => $target) {
                        if ($target['group_name'] === $group) { unset($this->history[$slot], $this->restartAt[$slot]); $this->spawn($slot); }
                    }
                } else { throw new RuntimeException('Unsupported Windows control operation.'); }
            } catch (Throwable $failure) { $error = $failure->getMessage(); }
            self::writeJson($this->instance . '/response-' . $request['id'] . '.json', ['ok' => $error === null, 'error' => $error]);
        }
    }

    /** A named reload may update code/resources, but must not attach a changed definition to an old socket. */
    private function validateGroupReload(string $group): void
    {
        $next = array_values(array_filter(($this->planFactory)()['targets'], static fn(array $target): bool => $target['group_name'] === $group));
        $current = array_values(array_filter($this->targets, static fn(array $target): bool => $target['group_name'] === $group));
        $changed = count($next) !== count($current) || $next === [];
        foreach (['listen', 'transport', 'protocol', 'ssl', 'context'] as $property) {
            $changed = $changed || ($next[0]['config'][$property] ?? null) !== ($current[0]['config'][$property] ?? null);
        }
        if ($changed) { throw new RuntimeException('Process group listener/count changed; use a full project reload or stop/start. No workers were stopped.'); }
    }

    /** Publish only process metadata; never include commands, secrets, request bodies or listener export IDs. */
    private function publish(): void
    {
        $children = [];
        foreach ($this->targets as $slot => $target) {
            $children[] = ['group' => $target['group_name'], 'replica' => $target['replica'], 'count' => $target['count'],
                'pid' => $this->children[$slot]['pid'] ?? null, 'slot' => $slot];
        }
        self::writeJson($this->directory . '/supervisor.json', ['owner' => $this->owner, 'master_pid' => getmypid(), 'children' => $children]);
    }

    /** Retain structured exit diagnostics without terminating healthy siblings. */
    private function logExit(array $target, int $code, string $reason): void
    {
        Filesystem::mkdir(runtime_path() . '/logs');
        $line = json_encode(['time' => date('c'), 'process' => $target['label'], 'exit_code' => $code, 'reason' => $reason], JSON_THROW_ON_ERROR);
        file_put_contents(runtime_path() . '/logs/windows-supervisor.log', $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /** Release this instance exactly once, including partial startup failure and normal CLI stop. */
    private function cleanup(): void
    {
        if (!is_resource($this->lock)) { return; }
        $this->stopChildren();
        foreach ($this->listeners as $listener) { $listener->close(); }
        $this->listeners = [];
        $state = self::readJson($this->directory . '/supervisor.json');
        if (($state['owner'] ?? null) === $this->owner) { @unlink($this->directory . '/supervisor.json'); }
        foreach (glob($this->instance . '/child-*.json') ?: [] as $file) { @unlink($file); }
        foreach (glob($this->instance . '/exit-*.json') ?: [] as $file) { @unlink($file); }
        @rmdir($this->instance);
        flock($this->lock, LOCK_UN); fclose($this->lock); $this->lock = null;
    }

    /** Run `index.php status|stop|reload [group]` without touching another project's processes. */
    public static function request(string $operation, ?string $group = null): int
    {
        if ($operation === 'stop' && $group !== null) { throw new RuntimeException('stop applies to the whole project; use reload <group> to replace one group.'); }
        $base = self::baseDirectory(); $lock = @fopen($base . '/supervisor.lock', 'r+b');
        if ($lock === false) { throw new RuntimeException('This project is not running on Windows.'); }
        $inactive = flock($lock, LOCK_EX | LOCK_NB);
        if ($inactive) { flock($lock, LOCK_UN); }
        fclose($lock);
        $state = self::readJson($base . '/supervisor.json');
        if ($inactive || !preg_match('/\A[0-9a-f]{32}\z/D', $state['owner'] ?? '')) { throw new RuntimeException('This project is not running on Windows.'); }
        if ($operation === 'status') {
            fwrite(STDOUT, 'Windows master PID: ' . $state['master_pid'] . PHP_EOL);
            foreach ($state['children'] as $child) { fwrite(STDOUT, $child['group'] . '[' . $child['replica'] . '] PID=' . ($child['pid'] ?? 'restarting') . PHP_EOL); }
            return 0;
        }
        $id = bin2hex(random_bytes(16)); $directory = $base . '/' . $state['owner'];
        self::writeJson($directory . '/request-' . $id . '.json', ['id' => $id, 'owner' => $state['owner'], 'operation' => $operation, 'group' => $group]);
        $responsePath = $directory . '/response-' . $id . '.json'; $deadline = microtime(true) + 35;
        do {
            clearstatcache(true, $responsePath); $response = self::readJson($responsePath);
            if ($response !== null) {
                @unlink($responsePath);
                if ($operation === 'stop') { @rmdir($directory); }
                if (!$response['ok']) { throw new RuntimeException($response['error']); }
                fwrite(STDOUT, 'Windows ' . $operation . " success.\n"); return 0;
            }
            usleep(50000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('The Windows supervisor did not acknowledge this request.');
    }

    /** Read and validate the supervisor's stdin handoff; old direct internal child commands still work. */
    public static function childHandoff(): ?array
    {
        if (getenv('RCMAKER_WINDOWS_MANAGED') !== '1') { return null; }
        $data = json_decode((string) fgets(STDIN, 8192), true, 512, JSON_THROW_ON_ERROR);
        if (($data['pid'] ?? null) !== getmypid() || !preg_match('/\A[0-9a-f]{32}\z/D', $data['owner'] ?? '')
            || !preg_match('/\A[0-9a-f]{24}\z/D', $data['slot'] ?? '') || !is_int($data['replica'] ?? null)
            || !is_int($data['count'] ?? null) || $data['replica'] < 0 || $data['replica'] >= $data['count']) { throw new RuntimeException('Invalid Windows child handoff.'); }
        return $data;
    }

    /** Bind listener/control before native startup, with a low-frequency orphan watchdog and request drain. */
    public static function configureChild(Worker $worker, array $handoff, array $context): void
    {
        $worker->id = $handoff['replica'];
        if ($handoff['listener'] !== null) { WindowsListener::attach($worker, $handoff['listener'], $context); }
        $start = $worker->onWorkerStart;
        $worker->onWorkerStart = static function (Worker $active) use ($start, $handoff): void {
            if (is_callable($start)) { $start($active); }
            // Bootstraps may replace onWorkerStop; preserve the final application callback before wrapping it.
            $stop = $active->onWorkerStop;
            $active->onWorkerStop = static function (Worker $stopped) use ($stop, $handoff): void {
                if (is_callable($stop)) { $stop($stopped); }
                if ((new \ReflectionProperty(Worker::class, 'gracefulStop'))->getValue()) {
                    self::writeJson(self::baseDirectory() . '/' . $handoff['owner'] . '/exit-' . $handoff['slot'] . '.json',
                        ['owner' => $handoff['owner'], 'pid' => getmypid(), 'reason' => 'graceful']);
                }
            };
            $stopping = false; $nextWatch = 0.0; $path = self::baseDirectory() . '/' . $handoff['owner'] . '/child-' . $handoff['slot'] . '.json';
            Timer::add(0.1, static function () use ($active, $handoff, $path, &$stopping, &$nextWatch): void {
                if (!$stopping) {
                    clearstatcache(true, $path);
                    $command = is_file($path) ? self::readJson($path) : null;
                    $stop = ($command['owner'] ?? null) === $handoff['owner'] && ($command['operation'] ?? '') === 'stop';
                    if ($nextWatch <= microtime(true)) {
                        $state = self::readJson(self::baseDirectory() . '/supervisor.json'); $nextWatch = microtime(true) + 2;
                        $stop = $stop || ($state['owner'] ?? null) !== $handoff['owner'] || ($state['master_pid'] ?? null) !== $handoff['parent'] || !self::hasActiveMaster();
                    }
                    if (!$stop) { return; }
                    @unlink($path); $active->unlisten(); $stopping = true;
                }
                foreach ($active->connections as $connection) {
                    if (empty($connection->context->streamSending) && $connection->getRecvBufferQueueSize() === 0) { $connection->close(); }
                }
                if ($active->connections === []) { \RC\Runtime\WorkermanLifecycle::stopGracefully(); }
            });
        };
    }

    /** Keep all control state in the configured runtime directory, including packaged executables. */
    private static function baseDirectory(): string { return rtrim(runtime_path(), '/\\') . '/windows'; }

    /** A held project lock proves a live supervisor even when a stale PID/state file survives a forced exit. */
    private static function hasActiveMaster(): bool
    {
        $file = @fopen(self::baseDirectory() . '/supervisor.lock', 'r+b');
        if ($file === false) { return false; }
        $unlocked = flock($file, LOCK_EX | LOCK_NB);
        if ($unlocked) { flock($file, LOCK_UN); }
        fclose($file); return !$unlocked;
    }

    /** Read a complete bounded JSON file under its filesystem lock; absent state is not a valid command. */
    private static function readJson(string $path): ?array
    {
        $file = @fopen($path, 'rb'); if ($file === false) { return null; }
        try {
            if (!flock($file, LOCK_SH)) { return null; }
            $bytes = stream_get_contents($file, 1048577);
            if (!is_string($bytes) || strlen($bytes) > 1048576) { return null; }
            $value = json_decode($bytes, true); return is_array($value) ? $value : null;
        } finally { fclose($file); }
    }

    /** Publish complete local state under a lock, avoiding partially read JSON on Windows. */
    private static function writeJson(string $path, array $data): void
    {
        $file = @fopen($path, 'c+b'); if ($file === false) { throw new RuntimeException('Cannot open Windows control state; the supervisor may have stopped.'); }
        try {
            if (!flock($file, LOCK_EX)) { throw new RuntimeException('Cannot lock Windows control state.'); }
            rewind($file); Filesystem::writeToStream($file, json_encode($data, JSON_THROW_ON_ERROR) . "\n");
            if (!ftruncate($file, ftell($file)) || !fflush($file)) { throw new RuntimeException('Cannot publish complete Windows control state.'); }
        } finally { fclose($file); }
    }
}
