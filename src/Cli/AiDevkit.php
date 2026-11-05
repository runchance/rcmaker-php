<?php

declare(strict_types=1);

namespace RC\Cli;

use JsonException;
use RuntimeException;
use Throwable;
use ZipArchive;

final class AiDevkit
{
    public const ARCHIVE_NAME = 'rcmaker-ai-devkit.zip';
    public const DOWNLOAD_URL = 'https://github.com/runchance/rcmaker-ai-devkit/releases/latest/download/' . self::ARCHIVE_NAME;
    public const RELEASES_URL = 'https://github.com/runchance/rcmaker-ai-devkit/releases';
    public const MANIFEST_PATH = '.agents/rcmaker-ai-devkit.json';

    private const MAX_FILES = 5000;
    private const MAX_FILE_SIZE = 20 * 1024 * 1024;
    private const MAX_TOTAL_SIZE = 64 * 1024 * 1024;

    /**
     * Return the installed DevKit version, or null when no valid manifest exists.
     */
    public static function installedVersion(string $rootPath): ?string
    {
        $manifestPath = self::join($rootPath, self::MANIFEST_PATH);
        if (!is_file($manifestPath)) {
            return null;
        }
        try {
            return self::readManifest($manifestPath)['version'];
        } catch (Throwable) {
            return null;
        }
    }

    public static function hasManifest(string $rootPath): bool
    {
        return is_file(self::join($rootPath, self::MANIFEST_PATH));
    }

    public static function offlineArchivePath(string $rootPath): string
    {
        return rtrim($rootPath, '/\\') . DIRECTORY_SEPARATOR . self::ARCHIVE_NAME;
    }

    /**
     * Install the root offline archive when present, otherwise download the latest release.
     */
    public static function install(string $rootPath, ?callable $output = null): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException("Installing rcmaker AI DevKit requires the 'zip' extension.");
        }

        $rootPath = realpath($rootPath) ?: '';
        if ($rootPath === '' || !is_dir($rootPath)) {
            throw new RuntimeException('Invalid project root path.');
        }

        $offlineArchive = self::offlineArchivePath($rootPath);
        $archivePath = $offlineArchive;
        $temporaryArchive = null;
        $source = 'offline';

        if (is_file($offlineArchive)) {
            self::emit($output, 'Use offline package: ' . $offlineArchive);
        } else {
            $source = 'online';
            $temporaryArchive = tempnam(sys_get_temp_dir(), 'rcmaker-ai-devkit-');
            if ($temporaryArchive === false) {
                throw new RuntimeException('Failed to create a temporary download file.');
            }
            $archivePath = $temporaryArchive;
            self::emit($output, 'Downloading ' . self::DOWNLOAD_URL . ' ...');
            try {
                (new ArtifactRepository())->downloadFile(self::DOWNLOAD_URL, $archivePath);
            } catch (Throwable $throwable) {
                if (is_file($temporaryArchive)) {
                    @unlink($temporaryArchive);
                }
                throw new AiDevkitDownloadException($throwable->getMessage(), 0, $throwable);
            }
        }

        try {
            return self::installArchive($rootPath, $archivePath, $source, $output);
        } finally {
            if ($temporaryArchive !== null && is_file($temporaryArchive)) {
                @unlink($temporaryArchive);
            }
        }
    }

    private static function installArchive(
        string $rootPath,
        string $archivePath,
        string $source,
        ?callable $output
    ): array {
        $stagePath = self::temporaryDirectory('rcmaker-ai-devkit-stage-');
        try {
            self::emit($output, 'Validate and extract rcmaker AI DevKit ...');
            $files = self::extractValidatedArchive($archivePath, $stagePath);
            $manifest = self::readManifest(self::join($stagePath, self::MANIFEST_PATH));
            $previousVersion = self::installedVersion($rootPath);
            $result = self::applyFiles($rootPath, $stagePath, $files);

            return $result + [
                'version' => $manifest['version'],
                'previous_version' => $previousVersion,
                'source' => $source,
                'archive' => $source === 'offline' ? $archivePath : null,
            ];
        } finally {
            Filesystem::removePath($stagePath);
        }
    }

    private static function extractValidatedArchive(string $archivePath, string $stagePath): array
    {
        if (!is_file($archivePath)) {
            throw new RuntimeException('AI DevKit archive does not exist: ' . $archivePath);
        }

        $zip = new ZipArchive();
        $opened = $zip->open($archivePath);
        if ($opened !== true) {
            throw new RuntimeException("Failed to open AI DevKit archive (ZipArchive code {$opened}).");
        }

        $files = [];
        $seen = [];
        $totalSize = 0;
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_FILES) {
                throw new RuntimeException('AI DevKit archive contains an invalid number of entries.');
            }
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $rawName = $zip->getNameIndex($index);
                if (!is_string($rawName)) {
                    throw new RuntimeException('Failed to read an AI DevKit archive entry.');
                }
                [$relativePath, $directory] = self::validateEntry($zip, $index, $rawName);
                if ($relativePath === '') {
                    continue;
                }

                $caseKey = strtolower($relativePath);
                if (isset($seen[$caseKey])) {
                    throw new RuntimeException('AI DevKit archive contains duplicate paths: ' . $relativePath);
                }
                $seen[$caseKey] = true;
                if ($directory) {
                    continue;
                }

                $stat = $zip->statIndex($index);
                $size = is_array($stat) ? (int)($stat['size'] ?? -1) : -1;
                if ($size < 0 || $size > self::MAX_FILE_SIZE) {
                    throw new RuntimeException('AI DevKit archive entry is too large: ' . $relativePath);
                }
                $totalSize += $size;
                if ($totalSize > self::MAX_TOTAL_SIZE) {
                    throw new RuntimeException('AI DevKit archive exceeds the uncompressed size limit.');
                }

                $input = $zip->getStream($rawName);
                if (!is_resource($input)) {
                    throw new RuntimeException('Failed to read AI DevKit archive entry: ' . $relativePath);
                }
                $targetPath = self::join($stagePath, $relativePath);
                Filesystem::mkdir(dirname($targetPath));
                $target = fopen($targetPath, 'wb');
                if (!is_resource($target)) {
                    fclose($input);
                    throw new RuntimeException('Failed to stage AI DevKit file: ' . $relativePath);
                }
                try {
                    $copied = stream_copy_to_stream($input, $target);
                    if ($copied === false || $copied !== $size) {
                        throw new RuntimeException('AI DevKit archive entry is incomplete: ' . $relativePath);
                    }
                } finally {
                    fclose($input);
                    fclose($target);
                }
                $files[] = $relativePath;
            }
        } finally {
            $zip->close();
        }

        self::assertRequiredFiles($files);
        return $files;
    }

    private static function validateEntry(ZipArchive $zip, int $index, string $rawName): array
    {
        if ($rawName === '' || str_contains($rawName, "\0") || str_contains($rawName, '\\')) {
            throw new RuntimeException('AI DevKit archive contains an invalid path.');
        }
        if (str_starts_with($rawName, '/') || preg_match('/^[A-Za-z]:/', $rawName)) {
            throw new RuntimeException('AI DevKit archive contains an absolute path: ' . $rawName);
        }

        $name = $rawName;
        while (str_starts_with($name, './')) {
            $name = substr($name, 2);
        }
        $directory = str_ends_with($name, '/');
        $name = rtrim($name, '/');
        $segments = explode('/', $name);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('AI DevKit archive contains an unsafe path: ' . $rawName);
            }
            if (preg_match('/[\x00-\x1F<>:"|?*]/', $segment) || rtrim($segment, " .") !== $segment) {
                throw new RuntimeException('AI DevKit archive path is not portable: ' . $rawName);
            }
            $windowsName = strtoupper((string)strtok($segment, '.'));
            if (preg_match('/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/', $windowsName)) {
                throw new RuntimeException('AI DevKit archive uses a reserved file name: ' . $rawName);
            }
        }

        $operatingSystem = 0;
        $attributes = 0;
        if ($zip->getExternalAttributesIndex($index, $operatingSystem, $attributes)) {
            $type = ($attributes >> 16) & 0170000;
            if ($type === 0120000) {
                throw new RuntimeException('AI DevKit archive cannot contain symbolic links: ' . $name);
            }
            $directory = $directory || $type === 0040000;
        }

        if (!self::isAllowedPath($name, $directory)) {
            throw new RuntimeException('Unexpected path in AI DevKit archive: ' . $name);
        }
        return [$name, $directory];
    }

    private static function isAllowedPath(string $path, bool $directory): bool
    {
        if ($directory) {
            return in_array($path, ['.agents', '.cursor', '.github'], true)
                || str_starts_with($path, '.agents/')
                || str_starts_with($path, '.cursor/');
        }
        return in_array($path, ['AGENTS.md', 'CLAUDE.md', 'GEMINI.md', '.github/copilot-instructions.md'], true)
            || str_starts_with($path, '.agents/')
            || str_starts_with($path, '.cursor/');
    }

    private static function assertRequiredFiles(array $files): void
    {
        $lookup = array_fill_keys($files, true);
        $required = [
            self::MANIFEST_PATH,
            '.github/copilot-instructions.md',
            'AGENTS.md',
            'CLAUDE.md',
            'GEMINI.md',
        ];
        foreach ($required as $path) {
            if (!isset($lookup[$path])) {
                throw new RuntimeException('AI DevKit archive is missing required file: ' . $path);
            }
        }
        foreach ($files as $path) {
            if (str_starts_with($path, '.cursor/')) {
                return;
            }
        }
        throw new RuntimeException('AI DevKit archive does not contain Cursor rules.');
    }

    private static function readManifest(string $manifestPath): array
    {
        $contents = @file_get_contents($manifestPath);
        if (!is_string($contents) || $contents === '' || strlen($contents) > 65536) {
            throw new RuntimeException('AI DevKit manifest is missing or invalid.');
        }
        try {
            $manifest = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('AI DevKit manifest is not valid JSON.', 0, $exception);
        }
        if (!is_array($manifest)
            || ($manifest['schema_version'] ?? null) !== 1
            || ($manifest['name'] ?? null) !== 'rcmaker-ai-devkit'
            || ($manifest['rcmaker_major'] ?? null) !== 3
        ) {
            throw new RuntimeException('AI DevKit manifest is not compatible with rcmaker 3.');
        }
        $version = $manifest['version'] ?? null;
        if (!is_string($version) || !preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/', $version)) {
            throw new RuntimeException('AI DevKit manifest contains an invalid version.');
        }
        return $manifest;
    }

    private static function applyFiles(string $rootPath, string $stagePath, array $files): array
    {
        $operations = [];
        $unchanged = 0;
        foreach ($files as $relativePath) {
            $sourcePath = self::join($stagePath, $relativePath);
            $targetPath = self::join($rootPath, $relativePath);
            self::assertWritableTarget($rootPath, $relativePath);
            if (is_dir($targetPath)) {
                throw new RuntimeException('A directory blocks the AI DevKit file: ' . $relativePath);
            }
            if (is_file($targetPath) && hash_file('sha256', $sourcePath) === hash_file('sha256', $targetPath)) {
                $unchanged++;
                continue;
            }
            $operations[] = [
                'relative' => $relativePath,
                'source' => $sourcePath,
                'target' => $targetPath,
                'existing' => is_file($targetPath),
            ];
        }

        if ($operations === []) {
            return ['installed' => 0, 'updated' => 0, 'unchanged' => $unchanged, 'backup' => null];
        }

        $backupPath = self::join(
            $rootPath,
            'data/rcmaker-ai-devkit-backup/' . date('Ymd-His') . '-' . bin2hex(random_bytes(3))
        );
        $updated = 0;
        foreach ($operations as $operation) {
            if (!$operation['existing']) {
                continue;
            }
            $backupRelative = substr($backupPath, strlen(rtrim($rootPath, '/\\')) + 1)
                . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $operation['relative']);
            self::assertWritableTarget($rootPath, str_replace(DIRECTORY_SEPARATOR, '/', $backupRelative));
            $backupFile = self::join($backupPath, $operation['relative']);
            Filesystem::mkdir(dirname($backupFile));
            if (!copy($operation['target'], $backupFile)) {
                throw new RuntimeException('Failed to back up AI DevKit file: ' . $operation['relative']);
            }
            $updated++;
        }

        $applied = [];
        try {
            foreach ($operations as $operation) {
                self::replaceFile($operation['source'], $operation['target']);
                $applied[] = $operation;
            }
        } catch (Throwable $throwable) {
            $rollbackErrors = self::rollback($applied, $backupPath);
            $message = 'AI DevKit installation failed and changes were rolled back: ' . $throwable->getMessage();
            if ($rollbackErrors !== []) {
                $message .= ' Rollback errors: ' . implode('; ', $rollbackErrors);
            }
            throw new RuntimeException($message, 0, $throwable);
        }

        return [
            'installed' => count($operations) - $updated,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'backup' => $updated > 0 ? $backupPath : null,
        ];
    }

    private static function assertWritableTarget(string $rootPath, string $relativePath): void
    {
        $current = rtrim($rootPath, '/\\');
        $segments = explode('/', $relativePath);
        foreach ($segments as $index => $segment) {
            $current .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($current)) {
                throw new RuntimeException('AI DevKit target cannot use a symbolic link: ' . $relativePath);
            }
            if ($index < count($segments) - 1 && file_exists($current) && !is_dir($current)) {
                throw new RuntimeException('A file blocks the AI DevKit path: ' . $relativePath);
            }
            if ($index < count($segments) - 1 && is_dir($current)) {
                $resolved = realpath($current);
                if ($resolved === false || !self::pathIsInside($resolved, $rootPath)) {
                    throw new RuntimeException('AI DevKit target leaves the project root: ' . $relativePath);
                }
            }
        }
    }

    private static function pathIsInside(string $path, string $rootPath): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $rootPath = rtrim(str_replace('\\', '/', $rootPath), '/');
        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
            $rootPath = strtolower($rootPath);
        }
        return $path === $rootPath || str_starts_with($path, $rootPath . '/');
    }

    private static function replaceFile(string $sourcePath, string $targetPath): void
    {
        Filesystem::mkdir(dirname($targetPath));
        $suffix = bin2hex(random_bytes(5));
        $newPath = $targetPath . '.rcmaker-ai-devkit-new-' . $suffix;
        $oldPath = $targetPath . '.rcmaker-ai-devkit-old-' . $suffix;
        if (!copy($sourcePath, $newPath)) {
            throw new RuntimeException('Failed to prepare AI DevKit file: ' . $targetPath);
        }

        $hadOriginal = is_file($targetPath);
        try {
            if ($hadOriginal && !rename($targetPath, $oldPath)) {
                throw new RuntimeException('Failed to replace AI DevKit file: ' . $targetPath);
            }
            if (!rename($newPath, $targetPath)) {
                if ($hadOriginal && is_file($oldPath)) {
                    @rename($oldPath, $targetPath);
                }
                throw new RuntimeException('Failed to finalize AI DevKit file: ' . $targetPath);
            }
            if ($hadOriginal && is_file($oldPath)) {
                @unlink($oldPath);
            }
        } finally {
            if (is_file($newPath)) {
                @unlink($newPath);
            }
        }
    }

    private static function rollback(array $applied, string $backupPath): array
    {
        $errors = [];
        foreach (array_reverse($applied) as $operation) {
            try {
                if ($operation['existing']) {
                    self::replaceFile(self::join($backupPath, $operation['relative']), $operation['target']);
                } else {
                    Filesystem::removePath($operation['target']);
                }
            } catch (Throwable $throwable) {
                $errors[] = $operation['relative'] . ': ' . $throwable->getMessage();
            }
        }
        return $errors;
    }

    private static function temporaryDirectory(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            throw new RuntimeException('Failed to create a temporary AI DevKit directory.');
        }
        @unlink($path);
        Filesystem::mkdir($path);
        return $path;
    }

    private static function join(string $rootPath, string $relativePath): string
    {
        return rtrim($rootPath, '/\\') . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, ltrim($relativePath, '/'));
    }

    private static function emit(?callable $output, string $message): void
    {
        if ($output !== null) {
            $output($message);
        }
    }
}
