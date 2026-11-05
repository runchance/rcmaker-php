<?php

declare(strict_types=1);

namespace RC\Cli;

use Phar;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/CustomIni.php';
require_once __DIR__ . '/EncryptionSources.php';
require_once __DIR__ . '/LegacyFontAssets.php';
require_once __DIR__ . '/BuildStaticAssets.php';

final class BuildBinary
{
    private const DEFAULT_EXCLUDE_PREFIXES = [
        '/.agents',
        '/.claude',
        '/.codex',
        '/.continue',
        '/.cursor',
        '/.gemini',
        '/.github',
        '/.idea',
        '/.roo',
        '/.setting',
        '/.tmp',
        '/.vscode',
        '/agent',
        '/build',
        '/coverage',
        '/data',
        '/node_modules',
        '/official',
        '/php',
        '/php.exe',
        '/composer',
        '/composer.phar',
        '/rcmaker-ai-devkit.zip',
        '/runtime',
        '/rcmaker.db',
        '/rcmaker.db-wal',
        '/rcmaker.db-shm',
        '/rcmaker.db-journal',
        '/scripts',
        '/test',
        '/tests',
        '/tools',
        '/vendor-bin',
        '/vendor/runchance/rcmaker-framework/data',
        '/vendor/runchance/rcmaker-php/data',
        '/vendor/runchance/rcmaker-php/official',
        '/vendor/runchance/rcmaker-php/resources/project',
        '/config/static_assets_build.php',
    ];

    private const DEFAULT_EXCLUDE_DIRECTORY_NAMES = [
        '.agents',
        '.claude',
        '.codex',
        '.continue',
        '.cursor',
        '.gemini',
        '.git',
        '.github',
        '.hg',
        '.idea',
        '.roo',
        '.setting',
        '.svn',
        '.vscode',
        'node_modules',
        'vendor-bin',
    ];

    private const DEFAULT_EXCLUDE_VENDOR_DIRECTORY_NAMES = [
        'benchmark',
        'benchmarks',
        'doc',
        'docs',
        'example',
        'examples',
        'test',
        'tests',
    ];

    private const DEFAULT_EXCLUDE_FILE_NAMES = [
        '.env',
        '.editorconfig',
        '.env.example',
        '.gitattributes',
        '.gitignore',
        '.gitmodules',
        '.git-credentials',
        '.netrc',
        '.npmrc',
        '.pypirc',
        'auth.json',
        'bun.lock',
        'bun.lockb',
        'composer.json',
        'composer.lock',
        'compose.yaml',
        'compose.yml',
        'docker-compose.yaml',
        'docker-compose.yml',
        'dockerfile',
        'jsconfig.json',
        'makefile',
        'package-lock.json',
        'package.json',
        'phpstan.neon',
        'phpstan.neon.dist',
        'phpunit.xml',
        'phpunit.xml.dist',
        'pnpm-lock.yaml',
        'psalm.xml',
        'rector.php',
        'tsconfig.json',
        'windows.bat',
        'windows.php',
        'yarn.lock',
    ];

    private const DEFAULT_EXCLUDE_EXTENSIONS = [
        'map',
        'markdown',
        'md',
        'rst',
    ];

    private static array $activeOutputs = [];

    /** Build privately and publish one complete executable; failures before publication retain the old program. */
    public static function execute(string $rootPath, #[\SensitiveParameter] array $options, ?callable $output = null): array
    {
        $rootPath = Filesystem::resolvePath($rootPath);
        if (!is_dir($rootPath)) {
            throw new RuntimeException('Project root does not exist: ' . $rootPath);
        }
        if (!class_exists(Phar::class)) {
            throw new RuntimeException("The host PHP must have the 'phar' extension enabled.");
        }
        if (filter_var(ini_get('phar.readonly'), FILTER_VALIDATE_BOOL)) {
            throw new RuntimeException("Binary build requires phar.readonly=0.");
        }

        $version = ArtifactRepository::normalizePhpVersion((string)($options['with-php'] ?? ArtifactRepository::DEFAULT_PHP_VERSION));
        $platform = ArtifactRepository::normalizePlatform((string)($options['platform'] ?? 'auto'));
        $arch = ArtifactRepository::normalizeArch((string)($options['arch'] ?? 'auto'));
        ArtifactRepository::assertTarget($platform, $arch);
        $encrypt = (bool)($options['encrypt'] ?? false);

        $customIni = self::resolveCustomIni((string)($options['custom-ini'] ?? ''), $rootPath);
        $excludePaths = Filesystem::parseExcludePaths((string)($options['exclude-files'] ?? ''));
        $buildDir = $rootPath . DIRECTORY_SEPARATOR . 'build';
        $binaryName = $platform === 'windows' ? 'rcmaker.exe' : 'rcmaker.bin';
        $entryFile = 'index.php';
        $repository = new ArtifactRepository($output);

        if (Filesystem::isLinkPath($buildDir)) {
            throw new RuntimeException('Build directory must not be a symbolic link or junction.');
        }
        Filesystem::mkdir($buildDir);
        $buildDir = Filesystem::resolvePath($buildDir);
        $binaryPath = Filesystem::fileTarget($buildDir . DIRECTORY_SEPARATOR . $binaryName);
        $key = PHP_OS_FAMILY === 'Windows' ? strtolower($binaryPath) : $binaryPath;
        if (isset(self::$activeOutputs[$key])) {
            throw new RuntimeException('A binary build is already running for this output.');
        }
        $lock = self::lockOutput($buildDir, $binaryName);
        self::$activeOutputs[$key] = true;
        $workDir = $pharPath = $temporaryBinary = null;
        $phar = $stream = null;
        $failure = null;
        $staticProtection = null;
        try {
            $candidate = $buildDir . DIRECTORY_SEPARATOR . '.rcmaker-build-' . bin2hex(random_bytes(12));
            if (!@mkdir($candidate, 0700)) {
                throw new RuntimeException('Cannot create a private binary-build workspace.');
            }
            $workDir = $candidate;
            $stagingDir = $workDir . DIRECTORY_SEPARATOR . 'source';
            $pharPath = $workDir . DIRECTORY_SEPARATOR . 'payload.phar';
            Filesystem::mkdir($stagingDir);
            Filesystem::copyTree(
                $rootPath,
                $stagingDir,
                static function (string $relativePath) use ($excludePaths): bool {
                    if (self::shouldExcludeByDefault($relativePath)) {
                        return true;
                    }
                    return Filesystem::shouldExclude($relativePath, $excludePaths);
                }
            );
            if (!is_file($stagingDir . DIRECTORY_SEPARATOR . $entryFile)) {
                throw new RuntimeException("Build entry {$entryFile} is missing or excluded.");
            }

            if (isset($options['static-encryption']) && $options['static-encryption'] !== false) {
                if (!is_array($options['static-encryption'])) { throw new RuntimeException('static-encryption must be a selection array or null.'); }
                $staticProtection = BuildStaticAssets::prepare($rootPath, $stagingDir, $options['static-encryption'], $output);
            }

            if ($encrypt) {
                self::emit($output, 'Encrypt staged project files ...');
                $preservedSources = EncryptionSources::capture($stagingDir);
                $fontAssets = LegacyFontAssets::prepare($stagingDir);
                $hostPlatform = ArtifactRepository::currentPlatform();
                $hostArch = ArtifactRepository::normalizeArch('auto');
                $beastEntry = ArtifactRepository::beastEntry($hostPlatform);
                $beastPath = $repository->ensure(
                    ArtifactRepository::beastArchive($hostPlatform, $hostArch),
                    $beastEntry,
                    self::artifactTarget($buildDir, $workDir, $beastEntry)
                );
                ProcessRunner::run([$beastPath, 'dir', $stagingDir, '--in-place', '--force'], $rootPath);
                if ($preserved = EncryptionSources::restore($preservedSources)) {
                    self::emit($output, 'Retained ' . $preserved . ' source-verified public engine file(s) unencrypted; application PHP remains protected.');
                }
                LegacyFontAssets::finalize($fontAssets);
            }

            self::emit($output, 'Create Phar payload ...');
            // Do not reserve the public runtime alias inside the builder process or reuse a cached filename.
            $phar = new Phar($pharPath);
            $phar->startBuffering();
            $phar->setSignatureAlgorithm(Phar::SHA256);
            $phar->buildFromDirectory($stagingDir);
            $stub = "#!/usr/bin/env php\n"
                . "<?php\n"
                . "define('IN_PHAR', true);\n"
                . "Phar::mapPhar('rcmaker');\n"
                . "require 'phar://rcmaker/{$entryFile}';\n"
                . "__HALT_COMPILER();\n";
            $phar->setStub($stub);
            $phar->stopBuffering();
            $phar = null;

            $sfxArchive = ArtifactRepository::microArchive($version, $platform, $arch);
            $sfxPath = $repository->ensure(
                $sfxArchive,
                'micro.sfx',
                self::artifactTarget($buildDir, $workDir, substr($sfxArchive, 0, -4) . '.sfx')
            );

            self::emit($output, 'Combine Micro SFX and Phar payload ...');
            clearstatcache(true, $sfxPath);
            clearstatcache(true, $pharPath);
            $expectedBytes = filesize($sfxPath) + filesize($pharPath)
                + ($customIni === '' ? 0 : 8 + strlen($customIni));
            [$temporaryBinary, $stream] = Filesystem::temporaryFile($binaryPath, 'rcmaker-binary');
            Filesystem::copyFileToStream($sfxPath, $stream);
            if ($customIni !== '') {
                Filesystem::writeToStream(
                    $stream,
                    "\xfd\xf6\x69\xe6" . pack('N', strlen($customIni)) . $customIni
                );
            }
            Filesystem::copyFileToStream($pharPath, $stream);
            if (!fflush($stream) || (fstat($stream)['size'] ?? null) !== $expectedBytes) {
                throw new RuntimeException('Binary output was not written completely.');
            }
            $closed = fclose($stream); $stream = null;
            if (!$closed || !@chmod($temporaryBinary, $platform === 'windows' ? 0644 : 0755)) {
                throw new RuntimeException('Cannot finish binary output or set its permissions.');
            }

            // Cleanup can still fail on Windows open handles. Do it before the single publication point.
            self::cleanupBuild($pharPath, $workDir, null);
            $pharPath = $workDir = null;
            Filesystem::publishFile($temporaryBinary, $binaryPath);
            $temporaryBinary = null;
        } catch (Throwable $error) {
            $failure = $error;
            throw $error;
        } finally {
            $cleanupError = null;
            try { $phar = null; } catch (Throwable $error) { $cleanupError = $error; }
            if (is_resource($stream)) {
                try { fclose($stream); } catch (Throwable $error) { $cleanupError = $error; }
            }
            try { self::cleanupBuild($pharPath, $workDir, $temporaryBinary); }
            catch (Throwable $error) { $cleanupError ??= $error; }
            try { @flock($lock, LOCK_UN); fclose($lock); }
            catch (Throwable $error) { $cleanupError ??= $error; }
            finally { unset(self::$activeOutputs[$key]); }
            if ($cleanupError !== null) {
                if ($failure === null) { throw $cleanupError; }
                // Reporting a secondary cleanup failure must not replace the original build/callback error.
                try { self::emit($output, 'Temporary build cleanup failed: ' . $cleanupError->getMessage()); }
                catch (Throwable) {}
            }
        }
        // A callback failure after this commit point cannot roll back the successfully published program.
        self::emit($output, 'Binary saved to: ' . $binaryPath);

        return [
            'path' => $binaryPath,
            'platform' => $platform,
            'arch' => $arch,
            'php' => $version,
            'encrypted' => $encrypt,
        ] + ($staticProtection === null ? [] : ['static_encryption' => $staticProtection]);
    }

    /** Keep cooperative output locks stable; another process must not publish the same output concurrently. */
    private static function lockOutput(string $buildDir, string $binaryName)
    {
        $path = Filesystem::fileTarget($buildDir . '/.rcmaker-output-' . substr(hash('sha256', $binaryName), 0, 24) . '.lock');
        $lock = @fopen($path, 'x+b');
        if (!is_resource($lock)) { $lock = @fopen($path, 'r+b'); }
        if (!is_resource($lock)) { throw new RuntimeException('Cannot open the binary-build output lock.'); }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('A binary build is already running for this output.');
            }
            Filesystem::fileTarget($path);
            $opened = fstat($lock); $current = lstat($path);
            if ($opened === false || $current === false || $opened['dev'] !== $current['dev'] || $opened['ino'] !== $current['ino']) {
                throw new RuntimeException('Binary-build lock changed during open.');
            }
            return $lock;
        } catch (Throwable $error) { fclose($lock); throw $error; }
    }

    /** Honor explicit legacy/offline artifacts, but put new downloads only in this build's private workspace. */
    private static function artifactTarget(string $buildDir, string $workDir, string $name): string
    {
        $legacy = $buildDir . DIRECTORY_SEPARATOR . $name;
        return Filesystem::isLinkPath($legacy) || @lstat($legacy) !== false
            ? $legacy : $workDir . DIRECTORY_SEPARATOR . $name;
    }

    /** Remove only paths allocated by this build, attempting all cleanup even when one removal fails. */
    private static function cleanupBuild(?string $pharPath, ?string $workDir, ?string $temporaryBinary): void
    {
        $error = null;
        foreach ([
            static function () use ($pharPath): void { if ($pharPath !== null && is_file($pharPath)) { self::releasePhar($pharPath); } },
            static function () use ($workDir): void { if ($workDir !== null) { Filesystem::removePath($workDir); } },
            static function () use ($temporaryBinary): void { if ($temporaryBinary !== null) { Filesystem::removePath($temporaryBinary); } },
        ] as $cleanup) {
            try { $cleanup(); } catch (Throwable $failure) { $error ??= $failure; }
        }
        if ($error !== null) { throw $error; }
    }

    /** Release only the owned payload filename, including PHP's process-local PHAR cache. */
    private static function releasePhar(string $pharPath): void
    {
        try {
            Phar::unlinkArchive($pharPath);
        } catch (Throwable) {
            Filesystem::removePath($pharPath);
        }
        clearstatcache(true, $pharPath);
    }

    private static function resolveCustomIni(string $value, string $rootPath): string
    {
        return CustomIni::resolve($value, $rootPath);
    }

    private static function containsExcludedDirectory(string $relativePath): bool
    {
        return self::containsDirectoryNamed($relativePath, self::DEFAULT_EXCLUDE_DIRECTORY_NAMES);
    }

    private static function containsDirectoryNamed(string $relativePath, array $directoryNames): bool
    {
        $segments = explode('/', trim(str_replace('\\', '/', $relativePath), '/'));
        foreach ($segments as $segment) {
            if (in_array(strtolower($segment), $directoryNames, true)) {
                return true;
            }
        }
        return false;
    }

    private static function shouldExcludeByDefault(string $relativePath): bool
    {
        $relativePath = Filesystem::normalizeRelative($relativePath);
        $lowerPath = strtolower($relativePath);
        if (self::containsExcludedDirectory($relativePath)) {
            return true;
        }
        // Also cover projects using a relative custom vendor-dir instead of vendor/.
        $packageMarker = '/runchance/rcmaker-php/';
        $packageOffset = strpos($lowerPath, $packageMarker);
        if ($packageOffset !== false) {
            $packagePath = substr($lowerPath, $packageOffset + strlen($packageMarker));
            foreach (['data', 'official', 'agent', 'resources/project'] as $directory) {
                if ($packagePath === $directory || str_starts_with($packagePath, $directory . '/')) {
                    return true;
                }
            }
        }
        foreach (self::DEFAULT_EXCLUDE_PREFIXES as $prefix) {
            if ($lowerPath === $prefix || str_starts_with($lowerPath, $prefix . '/')) {
                return true;
            }
        }
        if (str_starts_with($lowerPath, '/vendor/')
            && self::containsDirectoryNamed($relativePath, self::DEFAULT_EXCLUDE_VENDOR_DIRECTORY_NAMES)
        ) {
            return true;
        }

        $fileName = strtolower(basename($relativePath));
        if (str_starts_with($fileName, '.env.')) {
            return true;
        }
        if (in_array($fileName, self::DEFAULT_EXCLUDE_FILE_NAMES, true)) {
            return true;
        }
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (in_array($extension, ['md', 'markdown', 'rst'], true)
            && preg_match('/^(?:licen[cs]e|copying|copyright|notice|authors)(?:[._-].*)?$/D', $fileName)) {
            return false;
        }
        return $extension !== '' && in_array($extension, self::DEFAULT_EXCLUDE_EXTENSIONS, true);
    }

    private static function emit(?callable $output, string $message): void
    {
        if ($output !== null) {
            $output($message);
        }
    }
}
