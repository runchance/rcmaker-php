<?php

declare(strict_types=1);

namespace RC\Cli;

use Phar;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/OutputBatch.php';
require_once __DIR__ . '/CustomIni.php';
require_once __DIR__ . '/EncryptionSources.php';
require_once __DIR__ . '/LegacyFontAssets.php';

final class EncryptPhp
{
    /** Encrypt privately; publish complete outputs only after every producer and tool cleanup succeeds. */
    public static function execute(string $rootPath, array $options, ?callable $output = null): array
    {
        if (!extension_loaded('openssl')) {
            throw new RuntimeException("The host PHP must have the 'openssl' extension enabled.");
        }

        $rootPath = Filesystem::resolvePath($rootPath);
        if (!is_dir($rootPath)) { throw new RuntimeException('Project root must be an existing directory.'); }
        $inputOption = trim((string)($options['input'] ?? ''));
        $outputOption = trim((string)($options['output'] ?? ''));
        if ($inputOption === '' || $outputOption === '') {
            throw new RuntimeException('Input and output paths are required.');
        }
        $inputPath = Filesystem::resolvePath($inputOption, $rootPath);
        $rawOutputPath = Filesystem::absolute($outputOption, $rootPath);
        if (Filesystem::isLinkPath($rawOutputPath)) {
            throw new RuntimeException('Encrypted output must not be a symbolic link or junction.');
        }
        $outputPath = Filesystem::resolvePath($outputOption, $rootPath);
        $directory = is_dir($inputPath);
        if (!$directory && !is_file($inputPath)) {
            throw new RuntimeException('Input path does not exist: ' . $inputPath);
        }
        $inputMode = $directory ? 0 : fileperms($inputPath);
        if ($inputMode === false) { throw new RuntimeException('Cannot read input file permissions.'); }

        $version = ArtifactRepository::normalizePhpVersion((string)($options['with-php'] ?? ArtifactRepository::DEFAULT_PHP_VERSION));
        $platform = ArtifactRepository::normalizePlatform((string)($options['platform'] ?? 'auto'));
        $arch = ArtifactRepository::normalizeArch((string)($options['arch'] ?? 'auto'));
        ArtifactRepository::assertTarget($platform, $arch);

        $force = (bool)($options['force'] ?? false);
        $buildBinaryOption = trim((string)($options['build-bin'] ?? ''));
        if ($buildBinaryOption !== '' && Filesystem::isLinkPath(Filesystem::absolute($buildBinaryOption, $rootPath))) {
            throw new RuntimeException('Binary output must not be a symbolic link or junction.');
        }
        $buildBinaryPath = $buildBinaryOption !== ''
            ? Filesystem::fileTarget(Filesystem::absolute($buildBinaryOption, $rootPath))
            : '';
        $runtimeOutputOption = trim((string)($options['runtime-output'] ?? ''));
        $downloadRuntime = (bool)($options['download-runtime'] ?? false)
            || $runtimeOutputOption !== '';
        $excludePaths = Filesystem::parseExcludePaths((string)($options['exclude-files'] ?? ''));
        $customIni = self::resolveCustomIni((string)($options['custom-ini'] ?? ''), $rootPath);
        $samePath = Filesystem::pathsEqual($inputPath, $outputPath);
        $runtimeRaw = $runtimeOutputOption !== '' ? Filesystem::absolute($runtimeOutputOption, $rootPath)
            : ($directory ? $outputPath : dirname($outputPath)) . '/' . ArtifactRepository::runtimeEntry($platform);
        if ($downloadRuntime && Filesystem::isLinkPath($runtimeRaw)) {
            throw new RuntimeException('Runtime output must not be a symbolic link or junction.');
        }
        $runtimePreview = $downloadRuntime ? Filesystem::fileTarget($runtimeRaw) : '';
        if ($runtimePreview !== '' && $buildBinaryPath !== ''
            && (Filesystem::pathContains($runtimePreview, $buildBinaryPath) || Filesystem::pathContains($buildBinaryPath, $runtimePreview))) {
            throw new RuntimeException('Runtime output and binary output must not overlap.');
        }

        if ($samePath && !$force) {
            throw new RuntimeException('In-place encryption requires overwrite confirmation.');
        }
        if (!$samePath && Filesystem::pathContains($outputPath, $inputPath)) {
            throw new RuntimeException('Output path cannot be an ancestor of the input path.');
        }
        foreach (['Runtime output' => $runtimePreview, 'Binary output' => $buildBinaryPath] as $label => $path) {
            if ($path === '') {
                continue;
            }
            $path = Filesystem::resolvePath($path, $rootPath);
            if (Filesystem::pathContains($path, $inputPath) || Filesystem::pathContains($path, $outputPath)) {
                throw new RuntimeException($label . ' must not replace the input, encrypted output or their ancestors.');
            }
        }
        if ($buildBinaryPath !== '') {
            if (!class_exists(Phar::class)) { throw new RuntimeException("The host PHP must have the 'phar' extension enabled."); }
            if (filter_var(ini_get('phar.readonly'), FILTER_VALIDATE_BOOL)) {
                throw new RuntimeException('Single-file build requires phar.readonly=0.');
            }
            if ($directory) { self::resolveEntry($inputPath, (string)($options['entry'] ?? '')); }
        }
        $runtimeMetadata = $runtimeLock = '';
        if ($runtimePreview !== '') {
            [$runtimeMetadata, $runtimeLock] = ArtifactRepository::cacheSidecars($runtimePreview);
            foreach ([$inputPath, $outputPath, $buildBinaryPath] as $path) {
                if ($path !== '' && (Filesystem::pathsEqual($path, $runtimeMetadata) || Filesystem::pathsEqual($path, $runtimeLock))) {
                    throw new RuntimeException('Runtime cache sidecars must not replace an input or another output.');
                }
            }
        }
        $batch = new OutputBatch();
        $outputPath = OutputBatch::target($outputPath, $directory);
        $workDir = $pharPath = null;
        $failure = null;
        $warnings = [];
        $repository = new ArtifactRepository($output);
        try {
            $batch->guardProject($rootPath);
            $batch->guardProject($inputPath);
            $encryptedPath = $samePath && $directory ? null : $batch->stage($outputPath, $directory, $force);
            $insideOutput = static fn(string $path): bool => $directory && !$samePath && $path !== '' && Filesystem::pathContains($outputPath, $path);
            $runtimeStage = $runtimeMetadataStage = $binaryStage = null;
            if ($downloadRuntime && !$insideOutput($runtimePreview)) {
                $runtimeStage = $batch->stage($runtimePreview, false, $force);
                $runtimeMetadataStage = $batch->stage($runtimeMetadata, false, true);
                $batch->guardArtifact($runtimePreview);
            }
            if ($buildBinaryPath !== '' && !$insideOutput($buildBinaryPath)) {
                $binaryStage = $batch->stage($buildBinaryPath, false, $force);
            }
            $candidate = Filesystem::resolvePath(sys_get_temp_dir()) . '/rcmaker-framework-encrypt-' . bin2hex(random_bytes(12));
            if (!@mkdir($candidate, 0700)) { throw new RuntimeException('Cannot create a private encryption workspace.'); }
            $workDir = $candidate;
            $encryptedPath ??= $workDir . '/encrypted';
            $generatedPaths = [$workDir, $samePath ? '' : $outputPath, $buildBinaryPath, $runtimePreview, $runtimeMetadata, $runtimeLock,
                ...$batch->generatedPaths()];
            foreach ($generatedPaths as $generatedPath) {
                if ($generatedPath !== '' && Filesystem::pathContains($inputPath, $generatedPath)) {
                    $excludePaths[] = Filesystem::normalizeRelative(substr($generatedPath, strlen(rtrim($inputPath, '/'))));
                }
            }
            $source = $directory ? $encryptedPath : $workDir . '/single/source.php';
            $originals = [];
            if ($directory) {
                Filesystem::copyTree($inputPath, $source, static function (string $relative, \SplFileInfo $file) use ($excludePaths, $samePath): bool {
                    if (Filesystem::shouldExclude($relative, $excludePaths)) { return true; }
                    if ($samePath && Filesystem::isLinkPath($file->getPathname())) {
                        throw new RuntimeException('In-place encryption does not follow source links; exclude the link or use a separate output.');
                    }
                    return false;
                });
                if ($buildBinaryPath !== '') { self::resolveEntry($source, (string)($options['entry'] ?? '')); }
                if ($samePath) {
                    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS)) as $file) {
                        if ($file->isFile()) {
                            $hash = hash_file('sha256', $file->getPathname());
                            if ($hash === false) { throw new RuntimeException('Cannot fingerprint staged input.'); }
                            $originals[substr($file->getPathname(), strlen($source) + 1)] = $hash;
                        }
                    }
                }
            } else {
                Filesystem::mkdir(dirname($source));
                if (!copy($inputPath, $source)) { throw new RuntimeException('Cannot stage encryption input.'); }
                $sidecar = $inputPath.'.rcfont.json';
                if (file_exists($sidecar)) {
                    $sidecar = Filesystem::fileTarget($sidecar);
                    if (!copy($sidecar, $source.'.rcfont.json')) { throw new RuntimeException('Cannot stage companion font metadata.'); }
                }
            }
            $preservedSources = EncryptionSources::capture($source);
            $fontAssets = LegacyFontAssets::prepare($source);
            $hostPlatform = ArtifactRepository::currentPlatform();
            $hostArch = ArtifactRepository::normalizeArch('auto');
            $beastEntry = ArtifactRepository::beastEntry($hostPlatform);
            $beastPath = $repository->ensure(
                ArtifactRepository::beastArchive($hostPlatform, $hostArch),
                $beastEntry,
                $workDir . DIRECTORY_SEPARATOR . $beastEntry
            );
            self::emit($output, 'Encrypt PHP source ...');

            if (!$directory) {
                // Directory mode retains already-encrypted PHP, while the tool's file mode exits with an error.
                ProcessRunner::run([$beastPath, 'dir', dirname($source), '--in-place', '--force'], $rootPath);
                EncryptionSources::restore($preservedSources);
                LegacyFontAssets::finalize($fontAssets);
                if (!copy($source, $encryptedPath) || !@chmod($encryptedPath, $inputMode & 0777)) { throw new RuntimeException('Cannot prepare encrypted file or preserve its permissions.'); }
                if ($fontAssets !== []) {
                    $metadata = $batch->stage($outputPath.'.rcfont.json', false, $force);
                    if (!copy($source.'.rcfont.json', $metadata) || !@chmod($metadata, 0600)) { throw new RuntimeException('Cannot prepare companion font metadata.'); }
                }
            } else {
                ProcessRunner::run([$beastPath, 'dir', $source, '--in-place', '--force'], $rootPath);
                EncryptionSources::restore($preservedSources);
                LegacyFontAssets::finalize($fontAssets);
                if ($samePath) {
                    foreach ($originals as $relative => $hash) {
                        $target = Filesystem::fileTarget($inputPath . '/' . $relative);
                        if (!is_file($target) || hash_file('sha256', $target) !== $hash) {
                            throw new RuntimeException('Input changed while encrypting: ' . $relative);
                        }
                        $prepared = $encryptedPath . '/' . $relative;
                        if (hash_file('sha256', $prepared) === $hash) { continue; }
                        $destination = $batch->stage($target, false, true, false);
                        if (!copy($prepared, $destination) || !@chmod($destination, fileperms($target) & 0777)) {
                            throw new RuntimeException('Cannot prepare in-place encrypted file: ' . $relative);
                        }
                    }
                    foreach ($fontAssets as $fontAsset) {
                        if (!$fontAsset['created']) { continue; }
                        $relative = substr($fontAsset['sidecar'], strlen($source) + 1);
                        $destination = $batch->stage($inputPath.'/'.$relative, false, false, false);
                        if (!copy($fontAsset['sidecar'], $destination) || !@chmod($destination, 0600)) {
                            throw new RuntimeException('Cannot prepare new in-place font metadata.');
                        }
                    }
                }
            }
            if ($preservedSources) {
                self::emit($output, 'Retained ' . count($preservedSources) . ' source-verified public engine file(s) unencrypted; application PHP remains protected.');
            }
            // Build the PHAR before adding optional runtimes/binaries to the directory: never bundle those tools again.
            if ($buildBinaryPath !== '') {
                $sfxPath = $repository->ensure(
                    ArtifactRepository::microArchive($version, $platform, $arch),
                    'micro.sfx',
                    $workDir . DIRECTORY_SEPARATOR . 'micro.sfx'
                );
                $pharPath = $workDir . DIRECTORY_SEPARATOR . 'payload.phar';
                self::buildPhar(
                    $encryptedPath,
                    $pharPath,
                    (string)($options['entry'] ?? ''),
                    self::pharAlias($buildBinaryPath),
                    basename($outputPath)
                );
                $binaryStage ??= $encryptedPath . substr($buildBinaryPath, strlen($outputPath));
                self::buildBinary($sfxPath, $pharPath, $binaryStage, $customIni, $platform);
                self::releasePhar($pharPath); $pharPath = null;
            }
            if ($downloadRuntime) {
                $runtimeStage ??= $encryptedPath . substr($runtimePreview, strlen($outputPath));
                $repository->ensure(ArtifactRepository::runtimeArchive($version, $platform, $arch), ArtifactRepository::runtimeEntry($platform), $runtimeStage);
                [$metadata, $lock] = ArtifactRepository::cacheSidecars($runtimeStage);
                if ($runtimeMetadataStage !== null && (!copy($metadata, $runtimeMetadataStage) || !@chmod($runtimeMetadataStage, 0600))) {
                    throw new RuntimeException('Cannot prepare runtime cache metadata.');
                }
                Filesystem::removeFile($lock);
            }
            Filesystem::removePath($workDir); $workDir = null;
            self::emit($output, 'Publish encrypted outputs ...');
            $batch->commit();
        } catch (Throwable $error) {
            $failure = $error;
            throw $error;
        } finally {
            $cleanupError = null;
            foreach ([
                static function () use ($pharPath): void { if ($pharPath !== null && is_file($pharPath)) { self::releasePhar($pharPath); } },
                static function () use ($workDir): void { if ($workDir !== null) { Filesystem::removePath($workDir); } },
            ] as $cleanup) {
                try { $cleanup(); } catch (Throwable $error) { $cleanupError ??= $error; }
            }
            $warnings = $batch->close();
            if ($cleanupError !== null) {
                if ($failure === null) { throw $cleanupError; }
                $warnings[] = 'Private encryption cleanup failed: ' . $cleanupError->getMessage();
            }
            if ($failure !== null) {
                foreach ($warnings as $warning) { try { self::emit($output, $warning); } catch (Throwable) {} }
            }
        }
        foreach ($warnings as $warning) { self::emit($output, $warning); }
        if ($downloadRuntime) { self::emit($output, 'Runtime saved to: ' . $runtimePreview); }
        if ($buildBinaryPath !== '') { self::emit($output, 'Binary saved to: ' . $buildBinaryPath); }
        if (!$downloadRuntime && $buildBinaryPath === '') {
            self::emit($output, 'Encrypted PHP requires the matching rcmaker PHP runtime with RCMB support; a stock system PHP cannot execute it.');
        }
        return [
            'input' => $inputPath,
            'output' => $outputPath,
            'runtime' => $downloadRuntime ? $runtimePreview : null,
            'binary' => $buildBinaryPath !== '' ? $buildBinaryPath : null,
            'platform' => $platform,
            'arch' => $arch,
            'php' => $version,
        ];
    }

    private static function buildPhar(string $sourcePath, string $pharPath, string $entry, string $alias, string $fileName): void
    {
        self::releasePhar($pharPath);
        $phar = new Phar($pharPath);
        $phar->startBuffering();
        $phar->setSignatureAlgorithm(Phar::SHA256);
        if (is_file($sourcePath)) {
            $entryFile = $fileName;
            $phar->addFile($sourcePath, $entryFile);
        } else {
            $entryFile = self::resolveEntry($sourcePath, $entry);
            $phar->buildFromDirectory($sourcePath);
        }
        $phar->setStub(
            "#!/usr/bin/env php\n<?php\n"
            . "Phar::mapPhar('{$alias}');\n"
            . 'require ' . var_export('phar://' . $alias . '/' . $entryFile, true) . ";\n"
            . "__HALT_COMPILER();\n"
        );
        $phar->stopBuffering();
        unset($phar);
    }

    private static function releasePhar(string $pharPath): void
    {
        try {
            Phar::unlinkArchive($pharPath);
        } catch (Throwable) {
            Filesystem::removePath($pharPath);
        }
        clearstatcache(true, $pharPath);
    }

    private static function buildBinary(
        string $sfxPath,
        string $payloadPath,
        string $outputPath,
        string $customIni,
        string $platform
    ): void {
        Filesystem::mkdir(dirname($outputPath));
        $stream = fopen($outputPath, 'x+b');
        if (!is_resource($stream)) {
            throw new RuntimeException('Failed to create private binary output.');
        }
        try {
            $expected = filesize($sfxPath) + filesize($payloadPath) + ($customIni === '' ? 0 : strlen($customIni) + 8);
            Filesystem::copyFileToStream($sfxPath, $stream);
            if ($customIni !== '') {
                Filesystem::writeToStream(
                    $stream,
                    "\xfd\xf6\x69\xe6" . pack('N', strlen($customIni)) . $customIni
                );
            }
            Filesystem::copyFileToStream($payloadPath, $stream);
            if (!fflush($stream) || (fstat($stream)['size'] ?? null) !== $expected) { throw new RuntimeException('Binary output was not written completely.'); }
        } catch (Throwable $throwable) {
            fclose($stream);
            throw $throwable;
        }
        if (!fclose($stream) || !@chmod($outputPath, $platform === 'windows' ? 0644 : 0755)) {
            throw new RuntimeException('Cannot finish binary output or set its permissions.');
        }
    }

    private static function resolveEntry(string $sourceRoot, string $entry): string
    {
        $entry = trim(str_replace('\\', '/', $entry));
        if ($entry === '') {
            $entry = 'index.php';
        }
        if (str_contains($entry, "\0") || Filesystem::isAbsolute($entry) || preg_match('#(?:^|/)\.\.(?:/|$)#', $entry)) {
            throw new RuntimeException('Phar entry must be a safe relative path.');
        }
        $entryPath = $sourceRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry);
        if (!is_file($entryPath)) {
            throw new RuntimeException('Entry file does not exist in encrypted output: ' . $entry);
        }
        return $entry;
    }

    private static function resolveCustomIni(string $value, string $rootPath): string
    {
        return CustomIni::resolve($value, $rootPath);
    }

    private static function pharAlias(string $path): string
    {
        $name = preg_replace('/[^A-Za-z0-9_-]+/', '_', pathinfo($path, PATHINFO_FILENAME));
        return $name !== '' ? $name : 'app';
    }

    private static function emit(?callable $output, string $message): void
    {
        if ($output !== null) {
            $output($message);
        }
    }
}
