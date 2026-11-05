<?php

declare(strict_types=1);

namespace RC\Cli;

use RC\Configuration\Files;
use RuntimeException;

/** Build-time optional component selection. Never changes source files or installs/removes Composer packages. */
final class BuildComponents
{
    private const COMPONENTS = [
        'pdf' => ['label' => 'PDF', 'libraries' => ['tcpdf', 'tc-lib-pdf'], 'paths' => ['Helper/Pdf.php', 'Helper/Pdf']],
        'pinyin' => ['label' => '拼音', 'libraries' => ['overtrue-pinyin'], 'paths' => ['Helper/PinYin.php', 'Helper/Pinyin']],
        'excel' => ['label' => 'Excel', 'libraries' => ['php-xlsxwriter', 'fast-excel-reader', 'fast-excel-helper'],
            'paths' => ['Helper/Xlsx.php', 'Helper/XlsxReader.php', 'Helper/XLSXWriter_BuffererWriter.php', 'Helper/Excel']],
        'captcha' => ['label' => '验证码（图形 / 离线滑块）', 'libraries' => ['gregwar-captcha', 'zxf-captcha'], 'paths' => ['Helper/Captcha']],
        'templates' => ['label' => '模板引擎（原生视图始终保留）', 'libraries' => ['smarty', 'think-template'],
            'paths' => ['Helper/View/Smarty.php', 'Helper/View/SmartyEngine.php', 'Helper/View/SmartyFile.php',
                'Helper/View/ThinkPHP.php', 'Helper/View/ThinkEngine.php', 'Helper/View/ThinkFile.php',
                'Helper/View/Twig.php', 'Helper/View/Blade.php', 'Helper/Template']],
        'mailer' => ['label' => '邮件', 'libraries' => ['phpmailer'], 'paths' => ['Helper/Mailer.php', 'Helper/Mailer']],
        'qrcode' => ['label' => '二维码 / 条码', 'libraries' => ['tc-lib-barcode'], 'paths' => ['Helper/QRcode.php', 'Helper/Qrcode']],
    ];

    // RC adapters use these small shared utilities beyond the upstream Composer dependency graph.
    private const CORE_LIBRARIES = ['think-helper', 'think-container', 'psr-container', 'psr-simple-cache', 'symfony-polyfill-mbstring'];
    private const RUNTIME_CLI_FILES = ['banner.php', 'filesystem.php', 'windowsruntime.php', 'windowssupervisor.php', 'windowslistener.php', 'interactive.php'];

    // Reviewed metadata for curated source-only bundles which intentionally omit upstream composer.json.
    private const CURATED_LIBRARIES = [
        'fast-excel-reader' => ['avadim/fast-excel-reader', ['fast-excel-helper']],
        'overtrue-pinyin' => ['overtrue/pinyin', []],
        'php-xlsxwriter' => ['mk-j/php_xlsxwriter', []],
        'medoo' => ['catfan/medoo', []],
        'php-jwt' => ['firebase/php-jwt', []],
        'php-curl-class' => ['php-curl-class/php-curl-class', []],
        'php-paginator' => ['jasongrimes/paginator', []],
        'psr-container' => ['psr/container', []],
        'psr-simple-cache' => ['psr/simple-cache', []],
        'think-cache' => ['topthink/think-cache', ['psr-simple-cache', 'think-container']],
        'think-container' => ['topthink/think-container', ['psr-container', 'think-helper']],
        'think-helper' => ['topthink/think-helper', []],
    ];

    /** List stable selection IDs and display labels for interact and external build tooling. */
    public static function catalog(): array
    {
        return array_map(static fn(array $item): string => $item['label'], self::COMPONENTS);
    }

    /** Normalize an explicit profile; full mode always includes current and future supported components. */
    public static function normalize(array $profile): array
    {
        $mode = $profile['mode'] ?? 'full';
        if (!in_array($mode, ['full', 'custom'], true)) {
            throw new RuntimeException('build.binary.mode must be full or custom.');
        }
        $keepTools = $profile['keep_cli_tools'] ?? false;
        if (!is_bool($keepTools)) { throw new RuntimeException('build.binary.keep_cli_tools must be a boolean.'); }
        if ($mode === 'full') {
            return ['mode' => 'full', 'components' => array_keys(self::COMPONENTS), 'keep_cli_tools' => $keepTools];
        }
        $selected = $profile['components'] ?? null;
        if (!is_array($selected) || !array_is_list($selected)) {
            throw new RuntimeException('Custom builds require build.binary.components as a list; use [] for core only.');
        }
        foreach ($selected as $name) {
            if (!is_string($name) || !isset(self::COMPONENTS[$name])) {
                throw new RuntimeException('Unknown binary component: ' . (is_scalar($name) ? (string) $name : get_debug_type($name)));
            }
        }
        return ['mode' => 'custom', 'components' => array_values(array_intersect(array_keys(self::COMPONENTS), $selected)), 'keep_cli_tools' => $keepTools];
    }

    /** Read only the reserved binary settings; unrelated legacy config/build.php fields retain their meaning. */
    public static function load(string $root): array
    {
        $path = self::configPath($root);
        if (!is_file($path)) {
            return self::normalize([]);
        }
        $settings = Files::load($path, true);
        if (!is_array($settings) || !isset($settings['binary'])) {
            return self::normalize([]);
        }
        if (!is_array($settings['binary'])) {
            throw new RuntimeException('config/build.php binary settings must be an array.');
        }
        return self::normalize($settings['binary']);
    }

    /** Atomically persist interact choices, preserving other literal fields and refusing dynamic PHP rewrites. */
    public static function save(string $root, array $profile): void
    {
        $profile = self::normalize($profile);
        $path = self::configPath($root);
        $settings = is_file($path) ? Files::load($path, true) : [];
        if (!is_array($settings)) {
            throw new RuntimeException('Cannot merge build choices into a non-array config/build.php.');
        }
        $binary = $settings['binary'] ?? [];
        if (!is_array($binary)) {
            throw new RuntimeException('Cannot merge invalid build.binary settings.');
        }
        Files::update(dirname($path), 'build', 'binary', array_replace($binary, $profile));
    }

    /** Resolve bundled dependency closure once per build, outside the HTTP runtime. */
    public static function plan(string $root, ?array $explicit = null): array
    {
        $root = Filesystem::resolvePath($root);
        $profile = $explicit === null ? self::load($root) : self::normalize($explicit);
        $framework = self::framework($root);
        $plan = $profile + ['framework' => $framework, 'entry_points' => self::entryPoints($root, $framework),
            'excluded_libraries' => [], 'disabled' => [], 'shared_libraries' => []];
        if ($profile['mode'] === 'full') {
            return $plan;
        }
        if ($framework === null) {
            throw new RuntimeException('Custom component builds require an installed runchance/rcmaker-php package inside the project.');
        }
        $directory = rtrim($root, '/\\') . '/' . $framework . '/src/ThirdParty';
        [$graph, $names] = self::libraryGraph($directory);
        $allSeeds = [];
        $selectedSeeds = [];
        $owners = [];
        foreach (self::COMPONENTS as $name => $item) {
            foreach ($item['libraries'] as $seed) {
                if (!isset($graph[$seed])) { throw new RuntimeException('Missing bundled component metadata: ' . $seed); }
                $allSeeds[] = $seed;
                if (in_array($name, $profile['components'], true)) { $selectedSeeds[] = $seed; }
            }
            if (!in_array($name, $profile['components'], true)) { $plan['disabled'][] = $name; }
            else {
                foreach (self::closure($item['libraries'], $graph) as $library) { $owners[$library][] = $name; }
            }
        }
        $optional = self::closure($allSeeds, $graph);
        $core = array_values(array_unique([...array_diff(array_keys($graph), $optional), ...self::CORE_LIBRARIES]));
        $retained = self::closure([...$core, ...$selectedSeeds], $graph);
        $plan['excluded_libraries'] = array_values(array_diff($optional, $retained));
        sort($plan['excluded_libraries']);
        foreach ($owners as $library => $components) {
            if (count(array_unique($components)) > 1) { $plan['shared_libraries'][$names[$library]] = array_values(array_unique($components)); }
        }
        return $plan;
    }

    /** Filter only this framework's bundled libraries/assets, never unrelated Composer dependencies. */
    public static function excludes(array $plan, string $relative): bool
    {
        if ($plan['framework'] === null) { return false; }
        $relative = strtolower(ltrim(Filesystem::normalizeRelative($relative), '/'));
        if (in_array($relative, $plan['entry_points'], true)) { return true; }
        $prefix = strtolower($plan['framework']) . '/src/';
        if (!str_starts_with($relative, $prefix)) { return false; }
        $path = substr($relative, strlen($prefix));
        if (str_starts_with($path, 'project/') && $path !== 'project/platform.php') { return true; }
        if (!$plan['keep_cli_tools'] && str_starts_with($path, 'cli/')
            && !in_array(substr($path, 4), self::RUNTIME_CLI_FILES, true)) { return true; }
        if ($plan['mode'] === 'full') { return false; }
        foreach ($plan['excluded_libraries'] as $library) {
            if (self::under($path, 'thirdparty/' . strtolower($library))) { return true; }
        }
        return false;
    }

    /** Replace disabled RC PHP entry files inside the private staging tree with clear, zero-hot-path-cost failures. */
    public static function prepare(string $stage, array $plan): int
    {
        if ($plan['framework'] === null) { return 0; }
        $base = rtrim($stage, '/\\') . '/' . $plan['framework'] . '/src';
        if (!$plan['keep_cli_tools']) {
            $interactive = $base . '/Cli/Interactive.php';
            if (is_file($interactive)) {
                $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace RC\Cli;

/** Source-only tool signal retained for optimized Composer maps. */
final class InteractiveInputClosed extends \RuntimeException {}

/** Packaged executables retain the existing explicit refusal of source-only interactive tools. */
final class Interactive
{
    /** Report the same source-only boundary without shipping build/download/installation engines. */
    public static function run(): int
    {
        fwrite(STDERR, "Interactive tools require a source project and cannot run inside a packaged executable.\n");
        return 1;
    }
}

PHP;
                if (file_put_contents(Filesystem::fileTarget($interactive), $source, LOCK_EX) !== strlen($source)) {
                    throw new RuntimeException('Cannot prepare packaged interactive boundary.');
                }
            }
        }
        if ($plan['mode'] === 'full') { return 0; }
        $count = 0;
        foreach ($plan['disabled'] as $name) {
            $message = 'RCMAKER binary component "' . $name . '" was not included. Enable it in config/build.php and rebuild.';
            $stub = "<?php\n\n// Generated only in the private binary-build copy.\nthrow new \\RuntimeException(" . var_export($message, true) . ");\n";
            foreach (self::COMPONENTS[$name]['paths'] as $owned) {
                $path = $base . '/' . $owned;
                if (!file_exists($path)) { continue; }
                $files = is_dir($path)
                    ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS))
                    : [new \SplFileInfo($path)];
                foreach ($files as $file) {
                    if (!$file->isFile()) { continue; }
                    $target = $file->getPathname();
                    if (!Filesystem::pathContains($stage, $target) || Filesystem::isLinkPath($target)) {
                        throw new RuntimeException('Component stubs must stay inside the private build tree.');
                    }
                    if (strtolower($file->getExtension()) !== 'php') {
                        if (!preg_match('/^(?:licen[cs]e|copying|copyright|notice|authors)(?:[._-].*)?$/iD', $file->getFilename())) {
                            Filesystem::removeFile($target);
                        }
                        continue;
                    }
                    if (file_put_contents(Filesystem::fileTarget($target), $stub, LOCK_EX) !== strlen($stub)) {
                        throw new RuntimeException('Cannot write disabled component entry: ' . $owned);
                    }
                    ++$count;
                }
            }
        }
        return $count;
    }

    /** Match an exact file or a directory descendant, not similarly named business paths. */
    private static function under(string $path, string $prefix): bool
    {
        return $path === $prefix || str_starts_with($path, $prefix . '/');
    }

    /** Check project/config parents before loading or writing developer-owned build configuration. */
    private static function configPath(string $root): string
    {
        $root = Filesystem::resolvePath($root);
        $directory = $root . '/config';
        if (Filesystem::isLinkPath($directory)) { throw new RuntimeException('Build configuration directory cannot be a link.'); }
        if (file_exists($directory) && !is_dir($directory)) { throw new RuntimeException('Build configuration parent is not a directory.'); }
        return Filesystem::fileTarget($directory . '/build.php');
    }

    /** Locate the package using the root manifest's vendor-dir without loading the target application's autoloader. */
    private static function framework(string $root): ?string
    {
        $path = rtrim($root, '/\\') . '/composer.json';
        $manifest = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
        $vendor = $manifest['config']['vendor-dir'] ?? 'vendor';
        if (!is_string($vendor) || str_contains($vendor, '://')) { return null; }
        $vendor = Filesystem::normalizedAbsolute($vendor, $root);
        $package = $vendor . '/runchance/rcmaker-php';
        if (!Filesystem::pathContains($root, $package) || !is_file($package . '/src/Runtime.php')) { return null; }
        $relative = ltrim(substr(str_replace('\\', '/', $package), strlen(rtrim(str_replace('\\', '/', $root), '/'))), '/');
        return $relative;
    }

    /** Remove only proxies belonging to this package; other Composer binaries may be business dependencies. */
    private static function entryPoints(string $root, ?string $framework): array
    {
        if ($framework === null) { return []; }
        $path = $root . '/composer.json';
        $manifest = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
        $vendor = $manifest['config']['vendor-dir'] ?? 'vendor';
        $bin = $manifest['config']['bin-dir'] ?? (is_string($vendor) ? rtrim($vendor, '/\\') . '/bin' : 'vendor/bin');
        if (!is_string($bin) || str_contains($bin, '://')) { return []; }
        $directory = Filesystem::normalizedAbsolute($bin, $root);
        if (!Filesystem::pathContains($root, $directory)) { return []; }
        $result = [];
        foreach (['rcmaker', 'rcmaker.bat'] as $name) {
            $file = $directory . '/' . $name;
            if (!is_file($file)) { continue; }
            $contents = file_get_contents($file, false, null, 0, 16384);
            $resolved = realpath($file);
            $own = realpath($root . '/' . $framework . '/bin/rcmaker');
            if (($own !== false && $resolved === $own)
                || (is_string($contents) && str_contains($contents, 'runchance') && str_contains($contents, 'rcmaker-php') && str_contains($contents, 'bin'))) {
                $result[] = strtolower(ltrim(substr(str_replace('\\', '/', $file), strlen(rtrim(str_replace('\\', '/', $root), '/'))), '/'));
            }
        }
        return $result;
    }

    /** Build a dependency graph from pristine bundled Composer manifests; nonbundled requirements are not removed. */
    private static function libraryGraph(string $directory): array
    {
        $records = [];
        $byName = [];
        $names = [];
        foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $item) {
            if (!$item->isDir() || $item->isLink()) { continue; }
            $manifest = $item->getPathname() . '/composer.json';
            $folder = $item->getFilename();
            if (is_file($manifest)) {
                $record = json_decode((string) file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
            } elseif (isset(self::CURATED_LIBRARIES[$folder])) {
                $record = ['name' => self::CURATED_LIBRARIES[$folder][0], 'require' => []];
            } else { continue; }
            if (!is_array($record) || !is_string($record['name'] ?? null)) { throw new RuntimeException('Invalid bundled package manifest.'); }
            $records[$folder] = $record;
            $byName[$record['name']] = $folder;
            $names[$folder] = $record['name'];
        }
        $graph = [];
        foreach ($records as $folder => $record) {
            $graph[$folder] = [];
            foreach (array_keys($record['require'] ?? []) as $dependency) {
                if (isset($byName[$dependency])) { $graph[$folder][] = $byName[$dependency]; }
            }
            foreach (self::CURATED_LIBRARIES[$folder][1] ?? [] as $dependency) {
                $graph[$folder][] = $dependency;
            }
        }
        return [$graph, $names];
    }

    /** Resolve shared/transitive libraries with a visited set, including cyclic upstream dependency declarations. */
    private static function closure(array $seeds, array $graph): array
    {
        $seen = [];
        while ($seeds !== []) {
            $item = array_pop($seeds);
            if (isset($seen[$item])) { continue; }
            if (!isset($graph[$item])) { throw new RuntimeException('Missing bundled dependency metadata: ' . $item); }
            $seen[$item] = true;
            array_push($seeds, ...$graph[$item]);
        }
        return array_keys($seen);
    }
}
