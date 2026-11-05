<?php

declare(strict_types=1);

namespace RC\Cli;

use Phar;
use RC\Config;
use RC\Container;
use RC\Controller;
use RC\Stopwatch;
use RC\Worker as RcmakerWorker;
use ReflectionClass;
use RuntimeException;
use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;
use Workerman\Worker;

require_once __DIR__ . '/WindowsListener.php';
require_once __DIR__ . '/WindowsSupervisor.php';

/**
 * Runs RCMaker's Windows process supervisor behind the unified index.php CLI.
 *
 * The public command is `php index.php start`. Each process group defaults to
 * one independent Workerman child for backward compatibility. windows_count
 * opts into replicas sharing a native Windows listener without fork or a proxy.
 */
final class WindowsRuntime
{
    private const INTERNAL_COMMAND = '__rcmaker_windows';
    private static ?array $handoff = null;

    /**
     * Determines whether the unified CLI should use the Windows supervisor.
     */
    public static function shouldHandle(): bool
    {
        if (PHP_OS_FAMILY !== 'Windows'
            || !in_array(PHP_SAPI, ['cli', 'micro'], true)
            || defined('IS_SCRIPT')) {
            return false;
        }

        $frame = strtolower(trim((string)(Config::get('app', 'cli_frame') ?? 'workerman')));
        if ($frame !== 'workerman') {
            return false;
        }

        return true;
    }

    /**
     * Dispatches the Windows supervisor or one internal child process.
     */
    public static function run(): int
    {
        try {
            self::changeWorkingDirectory();
            global $argv;
            $command = strtolower(trim((string)($argv[1] ?? '')));
            if ($command === self::INTERNAL_COMMAND) {
                return self::runChild((string)($argv[2] ?? ''), (string)($argv[3] ?? ''));
            }
            if ($command === 'start') {
                return self::startMaster();
            }
            if (in_array($command, ['stop', 'reload', 'status'], true)) {
                return WindowsSupervisor::request($command, isset($argv[2]) ? (string) $argv[2] : null);
            }
            throw new RuntimeException('Unsupported Windows command. Use: php index.php start|stop|reload|status');
        } catch (Throwable $throwable) {
            fwrite(STDERR, '[windows] ' . $throwable->getMessage() . PHP_EOL);
            return 1;
        }
    }

    /**
     * Starts one internal APP or named custom-process child.
     */
    private static function runChild(string $mode, string $name): int
    {
        self::$handoff = WindowsSupervisor::childHandoff();
        return match (strtolower(trim($mode))) {
            'app' => self::startApp(),
            'process' => self::startProcess($name),
            default => throw new RuntimeException('Invalid internal Windows child mode.'),
        };
    }

    /**
     * Supervise Windows replicas; the native Linux fork path is deliberately untouched.
     */
    private static function startMaster(): int
    {
        return (new WindowsSupervisor(
            static fn(): array => self::supervisorPlan(),
            static fn(array $target): array => self::childCommand($target['mode'], $target['name'], $target['replica']),
            static fn(array $config): Worker => self::createWorker($config),
            static function (array $specs): void { if (Banner::enabled()) { self::printBanner($specs); } self::applyConsoleTitle(); }
        ))->run();
    }

    /** Preserve one Windows replica for old configurations; opt in per group with windows_count. */
    public static function processCount(array $config): int
    {
        $value = $config['windows_count'] ?? 1;
        if ((!is_int($value) && !is_string($value)) || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            throw new RuntimeException('windows_count must be a positive integer.');
        }
        return (int) $value;
    }

    /** Expand main APP, independent APPs and handler groups into stable, collision-free replica identities. */
    private static function supervisorPlan(): array
    {
        Config::get('app', null, true);
        $specs = self::processSpecs(); $groups = [];
        if (Config::get('app', 'start_app') !== false) {
            $groups[] = ['mode' => 'app', 'name' => '', 'group_name' => 'main', 'config' => Config::get('worker', null, true) ?: [], 'monitor' => false];
        }
        foreach ($specs as $name => $spec) {
            $config = RcmakerWorker::isAppProcessConfig($spec) ? RcmakerWorker::mergeAppProcessConfig('workerman', $spec, true) : $spec;
            $config['windows_count'] = self::processCount($spec);
            $groups[] = ['mode' => 'process', 'name' => (string) $name, 'group_name' => $name === 'main' ? 'process:main' : (string) $name,
                'config' => $config, 'monitor' => self::isReloadMonitor($spec)];
        }
        $targets = [];
        foreach ($groups as $group) {
            $count = self::processCount($group['config']);
            if ($group['monitor'] && $count !== 1) { throw new RuntimeException('The file monitor requires windows_count=1.'); }
            $identity = $group['mode'] . "\0" . $group['name'];
            for ($replica = 0; $replica < $count; $replica++) {
                $slot = substr(hash('sha256', $identity . "\0" . $replica), 0, 24);
                $targets[$slot] = $group + ['count' => $count, 'replica' => $replica, 'group' => hash('sha256', $identity),
                    'label' => ($group['name'] === '' ? 'app' : $group['name']) . ($count > 1 ? '[' . $replica . ']' : '')];
            }
        }
        return ['targets' => $targets, 'specs' => $specs];
    }


    /**
     * Starts the main RCMaker APP in one Workerman child.
     */
    private static function startApp(): int
    {
        self::prepareRuntime();
        Config::get('app', null, true);
        self::applyErrorTypes();
        $config = Config::get('worker', null, true);
        if (!is_array($config) || $config === []) {
            throw new RuntimeException('No Workerman config found.');
        }

        self::setupWorkerLogging($config);
        TcpConnection::$defaultMaxPackageSize = $config['max_package_size'] ?? 10 * 1024 * 1024;
        $worker = self::createWorker($config);
        self::syncRcmakerWorkerState('workerman', $worker, (int)($config['max_request'] ?? 1000000));
        $worker->onWorkerReload = static function (): void {
        };
        RcmakerWorker::configureWorkermanAppWorker($worker);
        self::attachStaticPreload($worker);
        Banner::suppressWindowsWorkermanStartupRow($worker);

        Stopwatch::$_framework = stopwatch('__frame__');
        return self::runWorker($worker, $config);
    }

    /**
     * Starts one custom APP, handler, logger or queue-consumer process.
     */
    private static function startProcess(string $processName): int
    {
        if ($processName === '') {
            throw new RuntimeException('Missing Windows process name.');
        }

        self::prepareRuntime();
        Config::get('app', null, true);
        self::applyErrorTypes();
        $processConfig = self::resolveProcessConfig($processName);
        $isAppProcess = RcmakerWorker::isAppProcessConfig($processConfig);
        if ($processConfig === [] || (!$isAppProcess && !isset($processConfig['handler']))) {
            throw new RuntimeException("Process {$processName} was not found or has no handler.");
        }

        self::setupProcessLogging($processName);
        if ($isAppProcess) {
            return self::startAppProcess($processName, $processConfig);
        }

        $processWorker = new Worker($processConfig['listen'] ?? '', $processConfig['context'] ?? []);
        $processWorker->name = $processName;
        self::syncRcmakerWorkerState('workerman', $processWorker);
        foreach (['count', 'user', 'group', 'reloadable', 'reusePort', 'transport', 'protocol'] as $property) {
            if (array_key_exists($property, $processConfig)) {
                $processWorker->$property = self::normalizeWorkerProperty($property, $processConfig[$property]);
            }
        }
        if (($processConfig['ssl'] ?? false) === true) {
            $processWorker->transport = 'ssl';
        }

        $class = self::resolveProcessClass((string)$processConfig['handler']);
        $processWorker->onWorkerStart = static function (Worker $activeWorker) use ($processConfig, $class): void {
            self::applyConsoleTitle();
            foreach (($processConfig['bootstrap'] ?? []) as $bootstrap) {
                $bootstrap::start();
            }
            foreach (($processConfig['autoload'] ?? []) as $file) {
                include_once $file;
            }
            if ($timezone = ($processConfig['default_timezone'] ?? Config::get('app', 'default_timezone'))) {
                date_default_timezone_set($timezone);
            }
            $instance = Container::make($class, array_merge([
                'type' => 'workerman',
                'worker' => $activeWorker,
                'timer' => Timer::class,
            ], $processConfig['constructor'] ?? []));
            worker_bind($activeWorker, $instance);
        };

        Banner::suppressWindowsWorkermanStartupRow($processWorker);
        Stopwatch::$_framework = stopwatch('__frame__');
        return self::runWorker($processWorker, $processConfig);
    }

    /**
     * Starts a type=app custom process with the complete application runtime.
     */
    private static function startAppProcess(string $processName, array $processConfig): int
    {
        $processConfig['name'] = $processConfig['name'] ?? $processName;
        $runtimeConfig = RcmakerWorker::mergeAppProcessConfig('workerman', $processConfig, true);
        if (empty($runtimeConfig['listen'])) {
            throw new RuntimeException("APP process {$processName} has no listen address.");
        }

        $processWorker = self::createWorker($runtimeConfig);
        foreach (['reloadable', 'protocol'] as $property) {
            if (array_key_exists($property, $runtimeConfig)) {
                $processWorker->$property = self::normalizeWorkerProperty($property, $runtimeConfig[$property]);
            }
        }
        if (($runtimeConfig['ssl'] ?? false) === true) {
            $processWorker->transport = 'ssl';
        }

        self::syncRcmakerWorkerState(
            'workerman',
            $processWorker,
            (int)($runtimeConfig['max_request'] ?? 1000000)
        );
        RcmakerWorker::configureWorkermanAppWorker($processWorker, $processConfig, $processName);
        self::attachStaticPreload($processWorker, $processName);
        Banner::suppressWindowsWorkermanStartupRow($processWorker);
        Stopwatch::$_framework = stopwatch('__frame__');
        return self::runWorker($processWorker, $runtimeConfig);
    }

    /** Windows workers exit inside Workerman on shutdown; an early return means startup failed. */
    private static function runWorker(Worker $worker, array $config): int
    {
        if (self::$handoff !== null) {
            $worker->count = self::$handoff['count'];
            WindowsSupervisor::configureChild($worker, self::$handoff, $config['context'] ?? []);
        }
        Worker::runAll();
        throw new RuntimeException('Workerman returned before the Windows worker could keep running; see the Workerman startup diagnostic or worker log.');
    }

    /**
     * Collects configured processes plus framework logger and queue consumers.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function processSpecs(): array
    {
        $processes = Config::get('process', null, true, []) ?: [];
        if (Config::get('app', 'cli_log')) {
            $workerConfig = Config::get('worker', null, true) ?: [];
            $processes['RCmaker_logger'] = [
                'handler' => \RC\Helper\Process\Logger::class,
                'name' => 'RCmaker_logger',
                'listen' => $workerConfig['logger_listen'] ?? 'Text://127.0.0.1:8689',
                'count' => 1,
                'reusePort' => true,
            ];
        }

        $queueConfig = Config::get('queue', null, true) ?: [];
        if (($queueConfig['enable'] ?? false)
            && isset($queueConfig['consumer_process'])
            && is_array($queueConfig['consumer_process'])) {
            $processes = array_merge($processes, $queueConfig['consumer_process']);
        }

        return array_filter($processes, static function ($config): bool {
            return is_array($config)
                && (isset($config['handler']) || RcmakerWorker::isAppProcessConfig($config));
        });
    }

    /**
     * Resolves one process from normal, queue or logger configuration.
     *
     * @return array<string, mixed>
     */
    private static function resolveProcessConfig(string $processName): array
    {
        $processConfig = Config::get('process', $processName, true);
        if (is_array($processConfig) && $processConfig !== []) {
            return $processConfig;
        }

        $queueConfig = Config::get('queue', null, true) ?: [];
        if (($queueConfig['enable'] ?? false) && isset($queueConfig['consumer_process'][$processName])) {
            return (array)$queueConfig['consumer_process'][$processName];
        }

        if (Config::get('app', 'cli_log') && $processName === 'RCmaker_logger') {
            $workerConfig = Config::get('worker', null, true) ?: [];
            return [
                'handler' => \RC\Helper\Process\Logger::class,
                'name' => 'RCmaker_logger',
                'listen' => $workerConfig['logger_listen'] ?? 'Text://127.0.0.1:8689',
                'count' => 1,
                'reusePort' => true,
            ];
        }
        return [];
    }

    /**
     * Resolves a process handler class from FQCN or support/process.
     */
    private static function resolveProcessClass(string $handler): string
    {
        if (class_exists($handler)) {
            return $handler;
        }
        $classFile = BASE_PATH . '/support/process/' . $handler . '.php';
        $class = 'support\\process\\' . $handler;
        if (!Container::loadClass($classFile, $class)) {
            throw new RuntimeException("Process handler {$handler} does not exist.");
        }
        return $class;
    }

    /**
     * Creates a Workerman worker from RCMaker runtime configuration.
     */
    private static function createWorker(array $config): Worker
    {
        $worker = new Worker((string)($config['listen'] ?? ''), $config['context'] ?? []);
        foreach (['name', 'count', 'user', 'group', 'reusePort', 'transport'] as $property) {
            if (array_key_exists($property, $config)) {
                $worker->$property = self::normalizeWorkerProperty($property, $config[$property]);
            }
        }
        // Native Windows ignores SO_REUSEPORT; keep the configured worker state accurate.
        $worker->reusePort = self::toBool($config['reusePort'] ?? true);
        return $worker;
    }

    /**
     * Normalizes values before assigning typed Workerman properties.
     */
    private static function normalizeWorkerProperty(string $property, mixed $value): mixed
    {
        return match ($property) {
            'count' => max(1, (int)$value),
            'reloadable', 'reusePort' => self::toBool($value),
            'name', 'user', 'group', 'transport', 'protocol' => (string)$value,
            default => $value,
        };
    }

    /**
     * Converts common environment string forms to a boolean.
     */
    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return !in_array(strtolower(trim($value)), ['', '0', 'false', 'off', 'no'], true);
        }
        return (bool)$value;
    }

    /**
     * Builds the argument-array command for one internal child.
     *
     * @return array<int, string>
     */
    private static function childCommand(string $mode, string $name, int $replica = 0): array
    {
        if (PHP_SAPI === 'micro') {
            $command = [self::executablePath(), self::INTERNAL_COMMAND, $mode];
        } else {
            $command = [PHP_BINARY, self::indexPath(), self::INTERNAL_COMMAND, $mode];
        }
        $command[] = $mode === 'process' ? $name : '';
        $command[] = (string) $replica;
        if (Banner::enabled()) {
            $command[] = '-q';
        }
        return $command;
    }




    /**
     * Prints the configured startup Banner from the supervisor only.
     */
    private static function printBanner(?array $processSpecs = null): void
    {
        $startApp = Config::get('app', 'start_app') !== false;
        $workerConfig = Config::get('worker', null, true) ?: [];
        $processSpecs ??= self::processSpecs();
        $rows = Banner::workersFromConfig($startApp, $workerConfig, $processSpecs);
        $index = 0;
        if ($startApp) { $rows[$index++]['processes'] = self::processCount($workerConfig); }
        foreach ($processSpecs as $spec) { $rows[$index++]['processes'] = self::processCount($spec); }
        Banner::output([
            'runtime.name' => 'Workerman',
            'runtime.version' => Worker::VERSION,
            'event_loop' => self::workermanEventLoopName(),
        ], $rows);
    }

    /**
     * Selects the Workerman event loop available on this Windows host.
     */
    private static function workermanEventLoopName(): string
    {
        if (Worker::$eventLoopClass) {
            return Worker::$eventLoopClass;
        }
        if (extension_loaded('event')) {
            return '\\Workerman\\Events\\Event';
        }
        if (extension_loaded('libevent')) {
            return '\\Workerman\\Events\\Libevent';
        }
        return '\\Workerman\\Events\\Select';
    }

    /**
     * Creates framework runtime directories needed by Windows children.
     */
    private static function prepareRuntime(): void
    {
        foreach ([
            runtime_path(),
            runtime_path() . DIRECTORY_SEPARATOR . 'logs',
            runtime_path() . DIRECTORY_SEPARATOR . 'views',
            runtime_path() . DIRECTORY_SEPARATOR . 'windows',
        ] as $path) {
            if (!is_dir($path) && !@mkdir($path, 0777, true) && !is_dir($path)) {
                throw new RuntimeException("Unable to create runtime directory: {$path}");
            }
        }
    }

    /**
     * Applies main APP log, PID, status and event-loop settings.
     */
    private static function setupWorkerLogging(array $config): void
    {
        Worker::$eventLoopClass = self::workermanEventLoopName();
        Worker::$onMasterReload = static function (): void {
        };
        Worker::$pidFile = (string)$config['pid_file'];
        Worker::$stdoutFile = (string)$config['stdout_file'];
        Worker::$logFile = (string)$config['log_file'];
        if ((self::$handoff['count'] ?? 1) > 1) {
            Worker::$pidFile = runtime_path() . '/windows/app-' . self::$handoff['replica'] . '.pid';
            Worker::$stdoutFile = runtime_path() . '/logs/app-' . self::$handoff['replica'] . '.stdout.log';
        }
        if (isset($config['status_file']) && property_exists(Worker::class, 'statusFile')) {
            Worker::$statusFile = (string)$config['status_file'];
        }
    }

    /**
     * Assigns isolated log and state files to a custom process.
     */
    private static function setupProcessLogging(string $name): void
    {
        if ((self::$handoff['count'] ?? 1) > 1) { $name .= '-' . self::$handoff['replica']; }
        $base = runtime_path() . DIRECTORY_SEPARATOR . 'windows';
        Worker::$pidFile = $base . DIRECTORY_SEPARATOR . $name . '.pid';
        Worker::$stdoutFile = runtime_path() . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . $name . '.stdout.log';
        Worker::$logFile = runtime_path() . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . $name . '.log';
        if (property_exists(Worker::class, 'statusFile')) {
            Worker::$statusFile = $base . DIRECTORY_SEPARATOR . $name . '.status.log';
        }
    }

    /**
     * Warms only static applications bound to the active Windows process group.
     */
    private static function attachStaticPreload(Worker $worker, ?string $processName = null): void
    {
        $onWorkerStart = $worker->onWorkerStart;
        $worker->onWorkerStart = static function (Worker $activeWorker) use ($onWorkerStart, $processName): void {
            self::applyConsoleTitle();
            Controller::warmupStaticPreloadForProcess($processName);
            if (is_callable($onWorkerStart)) {
                $onWorkerStart($activeWorker);
            }
        };
    }

    /**
     * Applies project error reporting before child bootstraps execute.
     */
    private static function applyErrorTypes(): void
    {
        error_reporting(Config::get('app', 'error_types') ?? (E_ALL & ~E_NOTICE & ~E_DEPRECATED));
    }

    /**
     * Replaces Workerman's Windows console title after runtime initialization.
     */
    private static function applyConsoleTitle(): void
    {
        if (!Banner::enabled() || !function_exists('cli_set_process_title')) {
            return;
        }
        $title = Banner::consoleTitle([
            'runtime.name' => 'Workerman',
            'runtime.version' => Worker::VERSION,
        ]);
        if ($title !== '') {
            @cli_set_process_title($title);
        }
    }

    /**
     * Synchronizes private RCMaker worker state used by request handling.
     */
    private static function syncRcmakerWorkerState(
        string $frame,
        ?Worker $worker = null,
        ?int $maxRequestCount = null
    ): void {
        $reflection = new ReflectionClass(RcmakerWorker::class);
        $reflection->getProperty('_frame')->setValue(null, $frame);
        if ($worker !== null) {
            $reflection->getProperty('_worker')->setValue(null, $worker);
        }
        if ($maxRequestCount !== null) {
            $reflection->getProperty('_maxRequestCount')->setValue(null, $maxRequestCount);
        }
    }

    /**
     * Identifies the framework file monitor that requests a child restart.
     */
    private static function isReloadMonitor(array $spec): bool
    {
        return ($spec['handler'] ?? null) === \RC\Helper\Process\FileMonitor::class;
    }

    /**
     * Resolves the source entry or current PHAR archive used to launch normal CLI children.
     */
    private static function indexPath(): string
    {
        if (class_exists(Phar::class, false) && Phar::running(false) !== '') {
            return self::executablePath();
        }
        $path = defined('ROOT_PATH') ? ROOT_PATH . DIRECTORY_SEPARATOR . 'index.php' : '';
        $resolved = $path !== '' ? realpath($path) : false;
        if ($resolved === false || !is_file($resolved)) {
            throw new RuntimeException('Unable to resolve project index.php for Windows child processes.');
        }
        return $resolved;
    }

    /**
     * Resolves the current Micro SFX executable without relying on PHP_BINARY.
     */
    private static function executablePath(): string
    {
        $candidates = [
            class_exists(Phar::class, false) ? Phar::running(false) : '',
            $GLOBALS['argv'][0] ?? '',
            $_SERVER['SCRIPT_FILENAME'] ?? '',
            PHP_BINARY,
        ];
        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }
            $resolved = realpath($candidate);
            if ($resolved !== false && is_file($resolved)) {
                return $resolved;
            }
        }
        throw new RuntimeException('Unable to resolve the current Windows executable path.');
    }

    /**
     * Uses the executable directory for external config, runtime and assets.
     */
    private static function changeWorkingDirectory(): void
    {
        if (PHP_SAPI === 'micro' || (class_exists(Phar::class, false) && Phar::running(false) !== '')) {
            chdir(dirname(self::executablePath()));
            return;
        }
        if (defined('ROOT_PATH')) {
            chdir(ROOT_PATH);
        }
    }
}
