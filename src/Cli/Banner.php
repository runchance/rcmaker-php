<?php

declare(strict_types=1);

namespace RC\Cli;

use Composer\InstalledVersions;
use RC\Config;
use Throwable;

/**
 * Renders the RCMaker CLI startup banner and its optional process table.
 *
 * Applications normally configure this renderer through config/banner.php.
 * Framework startup paths pass runtime metadata and configured worker rows to
 * output(), while tests and tooling can call render() without writing stdout.
 */
final class Banner
{
    private const DEFAULT_WIDTH = 112;
    private const MIN_WIDTH = 60;
    private const MAX_WIDTH = 240;

    private const FOREGROUND_COLORS = [
        'black' => '30',
        'red' => '31',
        'green' => '32',
        'yellow' => '33',
        'blue' => '34',
        'magenta' => '35',
        'cyan' => '36',
        'white' => '37',
        'gray' => '90',
        'bright_black' => '90',
        'bright_red' => '91',
        'bright_green' => '92',
        'bright_yellow' => '93',
        'bright_blue' => '94',
        'bright_magenta' => '95',
        'bright_cyan' => '96',
        'bright_white' => '97',
    ];

    private const BACKGROUND_COLORS = [
        'black' => '40',
        'red' => '41',
        'green' => '42',
        'yellow' => '43',
        'blue' => '44',
        'magenta' => '45',
        'cyan' => '46',
        'white' => '47',
        'gray' => '100',
        'bright_black' => '100',
        'bright_red' => '101',
        'bright_green' => '102',
        'bright_yellow' => '103',
        'bright_blue' => '104',
        'bright_magenta' => '105',
        'bright_cyan' => '106',
        'bright_white' => '107',
    ];

    private const COLUMN_DEFINITIONS = [
        'event_loop' => ['label' => 'event-loop', 'width' => 14],
        'protocol' => ['label' => 'proto', 'width' => 10],
        'user' => ['label' => 'user', 'width' => 16],
        'name' => ['label' => 'worker', 'width' => 24],
        'listen' => ['label' => 'listen', 'width' => 36],
        'processes' => ['label' => 'count', 'width' => 8],
        'status' => ['label' => 'state', 'width' => 10],
    ];

    /**
     * Determines whether RCMaker should replace the runtime's native banner.
     */
    public static function enabled(): bool
    {
        return Config::get('app', 'cli_banner') !== false;
    }

    /**
     * Writes one startup banner to stdout.
     *
     * @param array<string, scalar|null> $context Runtime values that override built-ins.
     * @param array<int, array<string, scalar|null>> $workers Configured process rows.
     */
    public static function output(array $context = [], array $workers = []): void
    {
        fwrite(STDOUT, self::render($context, $workers));
    }

    /**
     * Builds the Windows console title from developer-owned Banner config.
     *
     * Set window_title=false to leave the runtime title unchanged. The same
     * placeholders used by Banner lines are available in the title template.
     */
    public static function consoleTitle(array $context = [], ?array $config = null): string
    {
        $config ??= self::loadConfig();
        if (($config['window_title'] ?? null) === false) {
            return '';
        }
        $template = (string)($config['window_title'] ?? '{app.name}: master process');
        $title = self::replace($template, self::context($context, [], $config));
        $title = preg_replace('/[\x00-\x1F\x7F]+/', ' ', self::sanitize($title)) ?? $title;
        $title = preg_replace('/\s+/u', ' ', trim($title)) ?? trim($title);
        return function_exists('mb_strcut') ? mb_strcut($title, 0, 200, 'UTF-8') : substr($title, 0, 200);
    }

    /**
     * Hides Workerman's unavoidable Windows child startup row.
     *
     * Workerman 4.x and 5.x still print one worker row in Windows child mode
     * even when -q suppresses the native header. RCMaker already renders the
     * complete process table in the master process, so the row is written to a
     * temporary stream and normal output is restored before the original
     * onWorkerStart callback runs.
     *
     * @param object $worker Workerman worker prepared by the Windows runtime.
     */
    public static function suppressWindowsWorkermanStartupRow(object $worker): void
    {
        global $argv;
        if (PHP_OS_FAMILY !== 'Windows'
            || !self::enabled()
            || !is_array($argv)
            || !in_array('-q', $argv, true)
            || !property_exists($worker, 'onWorkerStart')) {
            return;
        }

        $sink = fopen('php://temp', 'w+b');
        if ($sink === false || !self::setWorkermanOutputStream($sink)) {
            if (is_resource($sink)) {
                fclose($sink);
            }
            return;
        }

        // Workerman catches bootstrap errors itself. Never discard its diagnostic if start was not reached.
        register_shutdown_function(static function () use ($sink): void {
            if (!is_resource($sink)) {
                return;
            }
            self::setWorkermanOutputStream(STDOUT);
            rewind($sink);
            stream_copy_to_stream($sink, STDERR);
            fclose($sink);
        });

        $originalCallback = $worker->onWorkerStart;
        $worker->onWorkerStart = static function ($activeWorker) use ($originalCallback, $sink): void {
            self::setWorkermanOutputStream(STDOUT);
            fclose($sink);
            if (is_callable($originalCallback)) {
                $originalCallback($activeWorker);
            }
        };
    }

    /**
     * Builds banner text without producing side effects.
     *
     * A non-empty lines array is a complete custom banner. Missing or empty
     * lines preserve the built-in Workerman-style RCMaker banner.
     *
     * @param array<string, scalar|null> $context Runtime values that override built-ins.
     * @param array<int, array<string, scalar|null>> $workers Configured process rows.
     * @param array<string, mixed>|null $config Explicit configuration for tests or tooling.
     */
    public static function render(array $context = [], array $workers = [], ?array $config = null): string
    {
        $config ??= self::loadConfig();
        $customLines = isset($config['lines']) && is_array($config['lines']) && $config['lines'] !== [];
        if ($customLines) {
            $activeConfig = $config;
        } else {
            unset($config['lines']);
            $activeConfig = array_replace(self::defaultConfig(), $config);
        }
        $width = self::normalizeWidth($activeConfig['width'] ?? self::DEFAULT_WIDTH);
        $colorEnabled = self::colorEnabled($activeConfig['color'] ?? 'auto');
        $values = self::context($context, $workers, $activeConfig);
        $output = [];

        foreach ($activeConfig['lines'] as $line) {
            foreach (self::renderLine($line, $values, $workers, $width, $colorEnabled, $activeConfig) as $rendered) {
                $output[] = $rendered;
            }
        }

        return implode(PHP_EOL, $output) . PHP_EOL;
    }

    /**
     * Converts Workerman-style runtime and process configuration into rows.
     *
     * @param array<string, mixed> $runtimeConfig Main APP runtime configuration.
     * @param array<string, array<string, mixed>> $processConfig Custom process configuration.
     * @return array<int, array<string, scalar|null>>
     */
    public static function workersFromConfig(
        bool $startApp,
        array $runtimeConfig,
        array $processConfig,
        string $runtimeName = 'Workerman'
    ): array {
        $workers = [];

        if ($startApp && $runtimeConfig !== []) {
            $workers[] = self::workerRow(
                $runtimeConfig['name'] ?? ('RC_' . $runtimeName),
                self::runtimeListen($runtimeConfig),
                self::displayProcessCount(
                    $runtimeConfig['count'] ?? $runtimeConfig['worker_num'] ?? 1,
                    $runtimeName
                ),
                $runtimeConfig,
                'app'
            );
        }

        foreach ($processConfig as $name => $process) {
            if (!is_array($process) || (!isset($process['handler']) && !self::isAppProcess($process))) {
                continue;
            }
            $workers[] = self::workerRow(
                $process['name'] ?? (string)$name,
                self::runtimeListen($process),
                self::displayProcessCount(
                    $process['count'] ?? $process['worker_num'] ?? 1,
                    $runtimeName
                ),
                $process,
                self::isAppProcess($process) ? 'app' : 'process'
            );
        }

        return $workers;
    }

    /**
     * Converts the Swoole coroutine process-pool definition into banner rows.
     *
     * @param array<int, array<string, mixed>> $processes Swoole process definitions.
     * @return array<int, array<string, scalar|null>>
     */
    public static function workersFromSwoolePool(array $processes): array
    {
        $workers = [];
        foreach ($processes as $process) {
            if (!is_array($process)) {
                continue;
            }
            $listen = isset($process['listen'])
                ? self::combineListenAndPort((string)$process['listen'], $process['port'] ?? null)
                : 'none';
            $workers[] = [
                'protocol' => (string)($process['protocol'] ?? 'process'),
                'user' => self::currentUser(),
                'name' => (string)($process['name'] ?? 'process'),
                'listen' => $listen,
                'processes' => max(1, (int)($process['workers'] ?? 1)),
                'type' => (($process['app'] ?? false) === true) ? 'app' : 'process',
                'status' => '[OK]',
            ];
        }
        return $workers;
    }

    /**
     * Returns the installed RCMaker framework version for banner placeholders.
     */
    public static function frameworkVersion(): string
    {
        if (class_exists(InstalledVersions::class)) {
            foreach (['runchance/rcmaker-php', 'runchance/rcmaker-framework'] as $package) {
                if (InstalledVersions::isInstalled($package)) {
                    $version = InstalledVersions::getPrettyVersion($package) ?: '';
                    return str_replace('+no-version-set', '', $version);
                }
            }
        }
        return defined('VER') ? (string)VER : 'unknown';
    }

    /**
     * Reads config/banner.php and safely falls back when it is unavailable.
     *
     * @return array<string, mixed>
     */
    private static function loadConfig(): array
    {
        try {
            $config = Config::get('banner');
            return is_array($config) ? $config : [];
        } catch (Throwable $throwable) {
            fwrite(STDERR, '[banner] ' . $throwable->getMessage() . PHP_EOL);
            return [];
        }
    }

    /**
     * Defines the built-in Workerman-style RCMaker banner.
     *
     * @return array<string, mixed>
     */
    private static function defaultConfig(): array
    {
        return [
            'width' => self::DEFAULT_WIDTH,
            'color' => 'auto',
            'lines' => [
                ['type' => 'separator', 'text' => '{framework.name}', 'color' => 'bright_white'],
                ['text' => 'RCMaker/{framework.version}    {runtime.name}/{runtime.version}    PHP/{php.version} (JIT {php.jit})    {os.name}/{os.release}'],
                ['type' => 'separator', 'text' => 'WORKERS', 'color' => 'bright_white'],
                ['type' => 'workers'],
                ['type' => 'separator'],
            ],
        ];
    }

    /**
     * Creates all documented placeholder values.
     *
     * @param array<string, scalar|null> $overrides Runtime-specific values.
     * @param array<int, array<string, scalar|null>> $workers Configured process rows.
     * @param array<string, mixed> $config Active banner configuration.
     * @return array<string, string>
     */
    private static function context(array $overrides, array $workers, array $config): array
    {
        global $argv;
        $application = is_array($config['app'] ?? null) ? $config['app'] : [];
        $workermanVersion = class_exists(\Workerman\Worker::class) ? (string)\Workerman\Worker::VERSION : 'unavailable';
        $swooleVersion = function_exists('swoole_version') ? (string)swoole_version() : 'unavailable';
        $processCount = 0;
        foreach ($workers as $worker) {
            $processCount += max(0, (int)($worker['processes'] ?? 0));
        }

        $values = [
            'framework.name' => 'RCMAKER',
            'framework.version' => self::frameworkVersion(),
            'app.name' => self::scalar($application['name'] ?? $config['name'] ?? 'RCMAKER'),
            'app.version' => self::scalar($application['version'] ?? $config['version'] ?? ''),
            'php.version' => PHP_VERSION,
            'php.sapi' => PHP_SAPI,
            'php.jit' => self::jitStatus(),
            'runtime.name' => 'Workerman',
            'runtime.version' => $workermanVersion,
            'workerman.version' => $workermanVersion,
            'swoole.version' => $swooleVersion,
            'event_loop' => 'unknown',
            'os' => PHP_OS_FAMILY,
            'os.name' => php_uname('s'),
            'os.release' => php_uname('r'),
            'arch' => php_uname('m'),
            'user' => self::currentUser(),
            'datetime' => date((string)($config['datetime_format'] ?? 'Y-m-d H:i:s')),
            'timezone' => date_default_timezone_get(),
            'command' => self::scalar($argv[1] ?? 'start'),
            'process_count' => (string)$processCount,
        ];

        foreach ($overrides as $key => $value) {
            $values[(string)$key] = self::scalar($value);
        }

        foreach ($values as $key => $value) {
            $values[str_replace('.', '_', $key)] = $value;
        }
        return $values;
    }

    /**
     * Renders one configured line, including multiline text and worker blocks.
     *
     * @param mixed $line Configured line definition.
     * @param array<string, string> $values Placeholder values.
     * @param array<int, array<string, scalar|null>> $workers Process rows.
     * @param array<string, mixed> $config Active banner configuration.
     * @return array<int, string>
     */
    private static function renderLine(
        mixed $line,
        array $values,
        array $workers,
        int $width,
        bool $colorEnabled,
        array $config
    ): array {
        if (is_string($line) || is_numeric($line)) {
            $line = ['text' => (string)$line];
        }
        if (!is_array($line)) {
            return [];
        }

        $type = strtolower((string)($line['type'] ?? 'text'));
        if ($type === 'workers') {
            return self::renderWorkers($workers, $line, $width, $colorEnabled, $config, $values);
        }
        if ($type === 'blank') {
            return [''];
        }

        $text = self::replace((string)($line['text'] ?? ''), $values);
        if ($type === 'separator') {
            $text = self::separator($text, $width, (string)($line['fill'] ?? '-'));
        }

        $result = [];
        foreach (preg_split('/\R/u', $text) ?: [''] as $textLine) {
            $textLine = self::align(self::sanitize($textLine), $width, (string)($line['align'] ?? 'left'));
            $result[] = self::style($textLine, $line, $colorEnabled);
        }
        return $result;
    }

    /**
     * Renders the optional worker table with configurable columns and colors.
     *
     * @param array<int, array<string, scalar|null>> $workers Process rows.
     * @param array<string, mixed> $block Worker block configuration.
     * @param array<string, mixed> $config Active banner configuration.
     * @param array<string, string> $values Resolved banner placeholders.
     * @return array<int, string>
     */
    private static function renderWorkers(
        array $workers,
        array $block,
        int $width,
        bool $colorEnabled,
        array $config,
        array $values
    ): array {
        $workersConfig = is_array($config['workers'] ?? null) ? $config['workers'] : [];
        $options = array_replace($workersConfig, $block);
        $columns = $options['columns'] ?? array_keys(self::COLUMN_DEFINITIONS);
        $columns = is_array($columns) ? array_values(array_filter($columns, static function ($column): bool {
            return is_string($column) && isset(self::COLUMN_DEFINITIONS[$column]);
        })) : array_keys(self::COLUMN_DEFINITIONS);
        if ($columns === []) {
            return [];
        }

        $definitions = self::columnDefinitions($columns, $options, $width);
        $lines = [];
        if (!empty($options['title'])) {
            $title = self::separator((string)$options['title'], $width, (string)($options['fill'] ?? '-'));
            $lines[] = self::style($title, ['color' => $options['title_color'] ?? 'bright_white'], $colorEnabled);
        }
        if (($options['header'] ?? true) !== false) {
            $header = [];
            foreach ($definitions as $key => $definition) {
                $header[] = self::fit((string)$definition['label'], (int)$definition['width']);
            }
            $lines[] = self::style(
                implode(' ', $header),
                ['color' => $options['header_color'] ?? 'bright_white', 'bold' => $options['header_bold'] ?? true],
                $colorEnabled
            );
        }

        $statusColors = is_array($options['status_colors'] ?? null) ? $options['status_colors'] : [
            'ok' => 'green',
            'ready' => 'green',
            'disabled' => 'yellow',
            'invalid' => 'red',
            'stopped' => 'red',
        ];
        foreach ($workers as $worker) {
            $cells = [];
            foreach ($definitions as $key => $definition) {
                $value = self::scalar($worker[$key] ?? ($key === 'event_loop'
                    ? self::eventLoopLabel($values['event_loop'] ?? '')
                    : ''));
                $cell = self::fit($value, (int)$definition['width']);
                if ($key === 'status') {
                    $statusKey = strtolower(trim($value, "[] \t\n\r\0\x0B"));
                    $cell = self::style($cell, ['color' => $statusColors[$statusKey] ?? null], $colorEnabled);
                }
                $cells[] = $cell;
            }
            $lines[] = self::style(implode(' ', $cells), ['color' => $options['row_color'] ?? null], $colorEnabled);
        }

        return $lines;
    }

    /**
     * Calculates worker table columns while keeping the table inside the banner.
     *
     * @param array<int, string> $columns Selected column keys.
     * @param array<string, mixed> $options Worker block options.
     * @return array<string, array{label: string, width: int}>
     */
    private static function columnDefinitions(array $columns, array $options, int $width): array
    {
        $definitions = [];
        $customLabels = is_array($options['labels'] ?? null) ? $options['labels'] : [];
        $customWidths = is_array($options['widths'] ?? null) ? $options['widths'] : [];
        foreach ($columns as $column) {
            $definition = self::COLUMN_DEFINITIONS[$column];
            $definitions[$column] = [
                'label' => self::sanitize((string)($customLabels[$column] ?? $definition['label'])),
                'width' => max(4, (int)($customWidths[$column] ?? $definition['width'])),
            ];
        }

        $total = array_sum(array_column($definitions, 'width')) + max(0, count($definitions) - 1);
        if ($total > $width && isset($definitions['listen'])) {
            $overflow = $total - $width;
            $definitions['listen']['width'] = max(12, $definitions['listen']['width'] - $overflow);
        }
        return $definitions;
    }

    /**
     * Creates one normalized process row.
     *
     * @param array<string, mixed> $config Runtime or process configuration.
     * @return array<string, scalar|null>
     */
    private static function workerRow(string $name, string $listen, mixed $count, array $config, string $type): array
    {
        return [
            'protocol' => self::listenProtocol($listen, $type),
            'user' => self::scalar(!empty($config['user']) ? $config['user'] : self::currentUser()),
            'name' => $name,
            'listen' => $listen,
            'processes' => max(1, (int)$count),
            'type' => $type,
            'status' => '[OK]',
        ];
    }

    /**
     * Reports the effective process count for the current runtime platform.
     */
    private static function displayProcessCount(mixed $count, string $runtimeName): int
    {
        if (PHP_OS_FAMILY === 'Windows' && strcasecmp($runtimeName, 'Workerman') === 0) {
            return 1;
        }
        return max(1, (int)$count);
    }

    /**
     * Reads the listen address from Workerman or Swoole style configuration.
     */
    private static function runtimeListen(array $config): string
    {
        if (!isset($config['listen']) || $config['listen'] === '') {
            return 'none';
        }
        return self::combineListenAndPort((string)$config['listen'], $config['port'] ?? null);
    }

    /**
     * Appends a separate Swoole port without duplicating an existing port.
     */
    private static function combineListenAndPort(string $listen, mixed $port): string
    {
        if ($port === null || $port === '' || $listen === 'none') {
            return $listen;
        }
        $target = str_contains($listen, '://') ? explode('://', $listen, 2)[1] : $listen;
        if (preg_match('/:\d+$/', $target) === 1) {
            return $listen;
        }
        return rtrim($listen, '/') . ':' . (int)$port;
    }

    /**
     * Returns a protocol label from a listen URI or process type.
     */
    private static function listenProtocol(string $listen, string $fallback): string
    {
        if (str_contains($listen, '://')) {
            return strtolower(explode('://', $listen, 2)[0]);
        }
        return $listen === 'none' ? $fallback : 'tcp';
    }

    /**
     * Detects a type=app process definition without depending on Worker.
     */
    private static function isAppProcess(array $config): bool
    {
        return strtolower(trim((string)($config['type'] ?? ''))) === 'app';
    }

    /**
     * Resolves the current CLI user on Unix and portable fallback platforms.
     */
    private static function currentUser(): string
    {
        if (function_exists('posix_getpwuid') && function_exists('posix_getuid')) {
            $user = @posix_getpwuid(posix_getuid());
            if (is_array($user) && !empty($user['name'])) {
                return (string)$user['name'];
            }
        }
        $user = get_current_user();
        return $user !== '' ? $user : 'unknown';
    }

    /**
     * Returns Workerman-compatible JIT state text for the version line.
     */
    private static function jitStatus(): string
    {
        if (!function_exists('opcache_get_status')) {
            return 'off';
        }
        $status = @opcache_get_status(false);
        return is_array($status) && (($status['jit']['on'] ?? false) === true) ? 'on' : 'off';
    }

    /**
     * Converts an event-loop class or runtime label to Workerman's short form.
     */
    private static function eventLoopLabel(string $eventLoop): string
    {
        $eventLoop = trim(self::sanitize($eventLoop), "\\ \t\n\r\0\x0B");
        if ($eventLoop === '') {
            return 'unknown';
        }
        if (str_contains($eventLoop, '\\')) {
            $eventLoop = substr($eventLoop, (int)strrpos($eventLoop, '\\') + 1);
        }
        return strtolower(str_replace(' ', '-', $eventLoop));
    }

    /**
     * Replaces documented placeholders and leaves unknown placeholders visible.
     */
    private static function replace(string $text, array $values): string
    {
        $replace = [];
        foreach ($values as $key => $value) {
            $replace['{' . $key . '}'] = $value;
        }
        return strtr($text, $replace);
    }

    /**
     * Creates a Workerman-style centered separator.
     */
    private static function separator(string $label, int $width, string $fill): string
    {
        $fill = self::sanitize($fill);
        $fill = $fill !== '' ? substr($fill, 0, 1) : '-';
        $label = trim(self::sanitize($label));
        if ($label === '') {
            return str_repeat($fill, $width);
        }
        $label = ' ' . $label . ' ';
        $remaining = max(0, $width - self::textWidth($label));
        $left = intdiv($remaining, 2);
        return str_repeat($fill, $left) . $label . str_repeat($fill, $remaining - $left);
    }

    /**
     * Aligns text inside the configured width without truncating banner copy.
     */
    private static function align(string $text, int $width, string $align): string
    {
        $remaining = max(0, $width - self::textWidth($text));
        return match (strtolower($align)) {
            'center' => str_repeat(' ', intdiv($remaining, 2)) . $text . str_repeat(' ', $remaining - intdiv($remaining, 2)),
            'right' => str_repeat(' ', $remaining) . $text,
            default => $text,
        };
    }

    /**
     * Applies whitelisted ANSI styles when terminal color is enabled.
     */
    private static function style(string $text, array $style, bool $enabled): string
    {
        if (!$enabled || $text === '') {
            return $text;
        }
        $codes = [];
        if (($style['bold'] ?? false) === true) {
            $codes[] = '1';
        }
        if (($style['dim'] ?? false) === true) {
            $codes[] = '2';
        }
        $foreground = strtolower((string)($style['color'] ?? ''));
        $background = strtolower((string)($style['background'] ?? ''));
        if (isset(self::FOREGROUND_COLORS[$foreground])) {
            $codes[] = self::FOREGROUND_COLORS[$foreground];
        }
        if (isset(self::BACKGROUND_COLORS[$background])) {
            $codes[] = self::BACKGROUND_COLORS[$background];
        }
        return $codes === [] ? $text : "\033[" . implode(';', $codes) . 'm' . $text . "\033[0m";
    }

    /**
     * Resolves color mode from environment, configuration and terminal support.
     */
    private static function colorEnabled(mixed $setting): bool
    {
        $environment = strtolower((string)getenv('RCMAKER_COLOR'));
        if ($environment === 'always') {
            return self::enableWindowsAnsi();
        }
        if ($environment === 'never' || getenv('NO_COLOR') !== false || getenv('TERM') === 'dumb') {
            return false;
        }
        if ($setting === true || strtolower((string)$setting) === 'always') {
            return self::enableWindowsAnsi();
        }
        if ($setting === false || strtolower((string)$setting) === 'never') {
            return false;
        }
        $isTty = function_exists('stream_isatty')
            ? @stream_isatty(STDOUT)
            : (function_exists('posix_isatty') && @posix_isatty(STDOUT));
        return $isTty && self::enableWindowsAnsi();
    }

    /**
     * Enables ANSI output on supported Windows terminals.
     */
    private static function enableWindowsAnsi(): bool
    {
        if (PHP_OS_FAMILY !== 'Windows' || !function_exists('sapi_windows_vt100_support')) {
            return true;
        }
        return @sapi_windows_vt100_support(STDOUT, true);
    }

    /**
     * Sets the Workerman output stream across supported 4.x and 5.x releases.
     *
     * @param resource $stream Destination used by Workerman::safeEcho().
     */
    private static function setWorkermanOutputStream($stream): bool
    {
        if (!class_exists(\Workerman\Worker::class) || !is_resource($stream)) {
            return false;
        }

        foreach (['outputStream', '_outputStream'] as $propertyName) {
            if (!property_exists(\Workerman\Worker::class, $propertyName)) {
                continue;
            }
            try {
                $property = new \ReflectionProperty(\Workerman\Worker::class, $propertyName);
                $property->setValue(null, $stream);
                return true;
            } catch (Throwable) {
                return false;
            }
        }
        return false;
    }

    /**
     * Fits one table value into a fixed display width.
     */
    private static function fit(string $text, int $width): string
    {
        $text = self::sanitize($text);
        if (self::textWidth($text) > $width) {
            $text = self::truncate($text, max(1, $width - 3)) . '...';
        }
        return $text . str_repeat(' ', max(0, $width - self::textWidth($text)));
    }

    /**
     * Truncates UTF-8 text when mbstring is available and safely falls back.
     */
    private static function truncate(string $text, int $width): string
    {
        if (function_exists('mb_strimwidth')) {
            return mb_strimwidth($text, 0, $width, '', 'UTF-8');
        }
        return substr($text, 0, $width);
    }

    /**
     * Calculates terminal display width with a portable fallback.
     */
    private static function textWidth(string $text): int
    {
        return function_exists('mb_strwidth') ? mb_strwidth($text, 'UTF-8') : strlen($text);
    }

    /**
     * Removes ANSI and unsafe control characters from configuration values.
     */
    private static function sanitize(string $text): string
    {
        $text = preg_replace('/\x1B(?:[@-Z\\-_]|\[[0-?]*[ -\/]*[@-~])/', '', $text) ?? $text;
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text) ?? $text;
    }

    /**
     * Converts a placeholder or cell value to safe display text.
     */
    private static function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return '';
        }
        if (!is_scalar($value)) {
            return '';
        }
        return self::sanitize((string)$value);
    }

    /**
     * Keeps banner width inside practical terminal limits.
     */
    private static function normalizeWidth(mixed $width): int
    {
        return min(self::MAX_WIDTH, max(self::MIN_WIDTH, (int)$width));
    }
}
