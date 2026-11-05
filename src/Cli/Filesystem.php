<?php

declare(strict_types=1);

namespace RC\Cli;

use FilesystemIterator;
use InvalidArgumentException;
use RuntimeException;

final class Filesystem
{
    public static function mkdir(string $path): void
    {
        if ($path === '' || is_dir($path)) {
            return;
        }
        if (!@mkdir($path, 0777, true) && !is_dir($path)) {
            throw new RuntimeException('Failed to create directory: ' . $path);
        }
    }

    public static function removePath(string $path): void
    {
        $path = self::removalPath($path);
        if (self::isLinkPath($path)) {
            self::removeFile($path);
            return;
        }
        if (is_dir($path)) {
            self::removeDir($path);
            return;
        }
        if (is_file($path) || is_link($path)) {
            self::removeFile($path);
        }
    }

    public static function removeDir(string $path): void
    {
        $path = self::removalPath($path);
        if (self::isLinkPath($path)) {
            self::removeFile($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        // Inspect each child before descent: Windows junctions may not satisfy SplFileInfo::isLink().
        foreach (new \FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $item) {
            self::removePath($item->getPathname());
        }
        self::removeEmptyDir($path);
    }

    public static function removeFile(string $path): void
    {
        $path = self::removalPath($path);
        for ($attempt = 0; $attempt < 20; $attempt++) {
            clearstatcache(true, $path);
            $isLink = self::isLinkPath($path);
            if (!is_file($path) && !$isLink) {
                return;
            }
            // Windows directory links/junctions are removed with rmdir, not by walking the target.
            $removed = DIRECTORY_SEPARATOR === '\\' && $isLink
                ? (@rmdir($path) || @unlink($path)) : @unlink($path);
            if ($removed) {
                return;
            }
            usleep(100000);
        }
        throw new RuntimeException('Failed to remove file after retries: ' . $path);
    }

    public static function removeEmptyDir(string $path): void
    {
        $path = self::removalPath($path);
        for ($attempt = 0; $attempt < 20; $attempt++) {
            clearstatcache(true, $path);
            if (!is_dir($path) || @rmdir($path)) {
                return;
            }
            usleep(100000);
        }
        throw new RuntimeException('Failed to remove directory after retries: ' . $path);
    }

    public static function cleanupDirectory(string $path, string $keepName): void
    {
        $path = self::removalPath($path);
        if (self::isLinkPath($path)) {
            throw new InvalidArgumentException('Refusing to clean the target of a directory link: ' . $path);
        }
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if ($items === false) {
            throw new RuntimeException('Failed to scan directory: ' . $path);
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === $keepName) {
                continue;
            }
            self::removePath($path . DIRECTORY_SEPARATOR . $item);
        }
    }

    /** Guard destructive CLI operations without resolving a final link into its target. */
    private static function removalPath(string $path): string
    {
        if ($path === '' || str_contains($path, "\0") || preg_match('~^[a-z][a-z0-9+.-]*://~i', $path)) {
            throw new InvalidArgumentException('Removal requires a nonempty local filesystem path.');
        }
        $trimmed = rtrim($path, DIRECTORY_SEPARATOR === '\\' ? '/\\' : '/');
        if ($trimmed === '' || (DIRECTORY_SEPARATOR === '\\' && preg_match('/^[A-Za-z]:$/D', $trimmed))) {
            throw new InvalidArgumentException('Refusing to remove a filesystem root.');
        }
        if (DIRECTORY_SEPARATOR === '\\' && preg_match('~^[\\\\/]{2}[^\\\\/]+[\\\\/][^\\\\/]+$~D', $trimmed)) {
            throw new InvalidArgumentException('Refusing to remove a UNC share root.');
        }
        $last = basename(DIRECTORY_SEPARATOR === '\\' ? str_replace('\\', '/', $trimmed) : $trimmed);
        if ($last === '.' || $last === '..') {
            throw new InvalidArgumentException('Removal cannot end with . or ..');
        }
        clearstatcache(true, $trimmed);
        if (!self::isLinkPath($trimmed)) {
            $resolved = realpath($trimmed);
            if ($resolved !== false && dirname($resolved) === $resolved) {
                throw new InvalidArgumentException('Refusing to remove a filesystem root.');
            }
        }
        return $trimmed;
    }

    /** Windows readlink may resolve ordinary paths too; compare against the resolved parent plus leaf. */
    public static function isLinkPath(string $path): bool
    {
        $trimmed = rtrim($path, DIRECTORY_SEPARATOR === '\\' ? '/\\' : '/');
        if ($trimmed === '' || (DIRECTORY_SEPARATOR === '\\' && preg_match('/^[A-Za-z]:$/D', $trimmed))) {
            return false;
        }
        $path = $trimmed;
        if (is_link($path)) {
            return true;
        }
        if (DIRECTORY_SEPARATOR !== '\\') {
            return false;
        }
        $leaf = basename(str_replace('\\', '/', $path));
        if ($leaf === '.' || $leaf === '..') { return false; }
        $target = @readlink($path);
        if ($target === false) { return false; }
        $parent = realpath(dirname($path));
        if ($parent === false) {
            return false;
        }
        $expected = str_replace('\\', '/', $parent . '/' . $leaf);
        return strcasecmp(rtrim(str_replace('\\', '/', $target), '/'), rtrim($expected, '/')) !== 0;
    }

    public static function copyTree(string $sourceRoot, string $targetRoot, callable $exclude): void
    {
        $sourceRoot = self::resolvePath($sourceRoot);
        $targetRoot = self::resolvePath($targetRoot);
        if (!is_dir($sourceRoot)) {
            throw new InvalidArgumentException('Copy source must be an existing local directory.');
        }
        if (self::pathContains($targetRoot, $sourceRoot)) {
            throw new InvalidArgumentException('Copy destination cannot be the source or its ancestor.');
        }
        self::mkdir($targetRoot);
        self::copyDirectory($sourceRoot, $targetRoot, $sourceRoot, '', $exclude, []);
    }

    /** Materialize internal links, with both logical/physical exclusions and per-branch cycle detection. */
    private static function copyDirectory(string $sourceRoot, string $targetRoot, string $directory, string $prefix, callable $exclude, array $ancestors): void
    {
        $key = PHP_OS_FAMILY === 'Windows' ? strtolower($directory) : $directory;
        if (isset($ancestors[$key])) {
            throw new RuntimeException('Directory link cycle while collecting: ' . $prefix);
        }
        $ancestors[$key] = true;
        foreach (new \FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $item) {
            $relative = $prefix . '/' . $item->getFilename();
            if ($exclude(self::normalizeRelative($relative), $item)) {
                continue;
            }
            $source = realpath($item->getPathname());
            if ($source === false) {
                throw new RuntimeException('Unreadable or dangling source entry: ' . $relative);
            }
            $source = PHP_OS_FAMILY === 'Windows' ? str_replace('\\', '/', $source) : $source;
            if (!self::pathContains($sourceRoot, $source)) {
                throw new RuntimeException('Source link points outside the project; exclude it or copy its files into the project: ' . $relative);
            }
            if (self::pathContains($targetRoot, $source)) {
                continue;
            }
            $physicalRelative = '/' . ltrim(substr($source, strlen(rtrim($sourceRoot, '/'))), '/');
            if ($physicalRelative !== self::normalizeRelative($relative)
                && $exclude(self::normalizeRelative($physicalRelative), new \SplFileInfo($source))) {
                continue;
            }
            $target = rtrim($targetRoot, '/') . $relative;
            clearstatcache(true, $target);
            if (self::isLinkPath($target)) {
                throw new RuntimeException('Copy destination entry is a link: ' . $relative);
            }
            if (is_dir($source)) {
                self::mkdir($target);
                self::copyDirectory($sourceRoot, $targetRoot, $source, $relative, $exclude, $ancestors);
                continue;
            }
            if (!is_file($source)) {
                throw new RuntimeException('Copy source is not a regular file: ' . $relative);
            }
            $targetStat = @lstat($target);
            if ($targetStat !== false && ($targetStat['nlink'] > 1 || !is_file($target))) {
                throw new RuntimeException('Copy destination is non-regular or multiply linked: ' . $relative);
            }
            if (!copy($source, $target)) {
                throw new RuntimeException("Failed to copy file: {$source} -> {$target}");
            }
        }
    }

    /** Resolve existing ancestors before normalizing missing suffixes; callers must still control concurrent mutation. */
    public static function resolvePath(string $path, ?string $basePath = null): string
    {
        if ($path === '' || str_contains($path, "\0") || preg_match('~^[a-z][a-z0-9+.-]*://~i', $path)
            || (PHP_OS_FAMILY === 'Windows' && preg_match('~^[A-Za-z]:(?![\\\\/])~', $path))) {
            throw new InvalidArgumentException('Expected an unambiguous local filesystem path.');
        }
        $current = self::absolute($path, $basePath);
        $missing = [];
        while (($resolved = realpath($current)) === false) {
            $leaf = basename($current);
            // Windows can report missing/.. as existing. Resolve its real ancestor before applying dot segments.
            if ($leaf !== '.' && $leaf !== '..') {
                if (self::isLinkPath($current)) {
                    throw new InvalidArgumentException('Cannot resolve existing filesystem entry: ' . $current);
                }
                if (file_exists($current)) {
                    // Another process can create this entry after the first lookup. Retry once, not indefinitely.
                    clearstatcache(true, $current);
                    $resolved = realpath($current);
                    if (self::isLinkPath($current)) {
                        throw new InvalidArgumentException('Cannot resolve existing filesystem entry: ' . $current);
                    }
                    if ($resolved !== false) { break; }
                    if (file_exists($current)) {
                        throw new InvalidArgumentException('Cannot resolve existing filesystem entry: ' . $current);
                    }
                }
            }
            $parent = dirname($current);
            if ($parent === $current) {
                throw new InvalidArgumentException('Cannot resolve filesystem ancestor: ' . $path);
            }
            $missing[] = $leaf;
            $current = $parent;
        }
        if ($missing !== [] && !is_dir($resolved)) {
            throw new InvalidArgumentException('A path ancestor is not a directory: ' . $current);
        }
        foreach (array_reverse($missing) as $segment) {
            if ($segment === '.') {
                continue;
            }
            $resolved = $segment === '..' ? dirname($resolved)
                : rtrim($resolved, PHP_OS_FAMILY === 'Windows' ? '/\\' : '/') . DIRECTORY_SEPARATOR . $segment;
        }
        return PHP_OS_FAMILY === 'Windows' ? str_replace('\\', '/', $resolved) : $resolved;
    }

    /** Compare resolved local paths without changing POSIX backslash filenames. */
    public static function pathsEqual(string $first, string $second): bool
    {
        return PHP_OS_FAMILY === 'Windows' ? strcasecmp(str_replace('\\', '/', $first), str_replace('\\', '/', $second)) === 0 : $first === $second;
    }

    /** Includes equality; inputs should already be resolved with resolvePath(). */
    public static function pathContains(string $parent, string $child): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $parent = strtolower(str_replace('\\', '/', $parent));
            $child = strtolower(str_replace('\\', '/', $child));
        }
        $parent = rtrim($parent, '/');
        return $child === $parent || str_starts_with($child, $parent . '/');
    }

    public static function copyFileToStream(string $sourcePath, $targetStream): void
    {
        $source = fopen($sourcePath, 'rb');
        if (!is_resource($source)) {
            throw new RuntimeException('Failed to open binary input: ' . $sourcePath);
        }
        try {
            if (stream_copy_to_stream($source, $targetStream) === false) {
                throw new RuntimeException('Failed to append binary input: ' . $sourcePath);
            }
        } finally {
            fclose($source);
        }
    }

    public static function writeToStream($stream, #[\SensitiveParameter] string $contents): void
    {
        $length = strlen($contents);
        $offset = 0;
        while ($offset < $length) {
            $written = fwrite($stream, substr($contents, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Failed to write binary output.');
            }
            $offset += $written;
        }
    }

    /** Resolve an ordinary file destination without following a final link or replacing a directory. */
    public static function fileTarget(string $path): string
    {
        if ($path === '' || str_contains($path, "\0") || preg_match('~^[a-z][a-z0-9+.-]*://~i', $path)) {
            throw new InvalidArgumentException('File output requires a nonempty local filesystem path.');
        }
        $absolute = self::absolute($path);
        if (self::isLinkPath($absolute)) {
            throw new InvalidArgumentException('File output cannot be a symbolic link or junction.');
        }
        $path = self::resolvePath($path);
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat !== false && (($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] > 1)) {
            throw new InvalidArgumentException('File output must be an ordinary, singly linked file.');
        }
        return $path;
    }

    /** Create a private sibling file. The caller owns closing and cleaning up the returned [path, stream]. */
    public static function temporaryFile(string $targetPath, string $prefix = 'rcmaker'): array
    {
        if (!preg_match('/\A[a-z][a-z0-9-]{0,40}\z/D', $prefix)) {
            throw new InvalidArgumentException('Invalid temporary-file prefix.');
        }
        $targetPath = self::fileTarget($targetPath);
        self::mkdir(dirname($targetPath));
        $path = dirname($targetPath) . DIRECTORY_SEPARATOR . '.' . $prefix . '-' . bin2hex(random_bytes(12)) . '.part';
        $stream = @fopen($path, 'x+b');
        if (!is_resource($stream)) {
            throw new RuntimeException('Cannot create a private sibling output file.');
        }
        if (!@chmod($path, 0600)) {
            fclose($stream); @unlink($path);
            throw new RuntimeException('Cannot set temporary output permissions.');
        }
        return [$path, $stream];
    }

    /** Atomically replace one regular file; failure never deletes the previous destination first. */
    public static function publishFile(string $preparedPath, string $targetPath): void
    {
        $preparedPath = self::fileTarget($preparedPath);
        $targetPath = self::fileTarget($targetPath);
        if (!is_file($preparedPath) || self::pathsEqual($preparedPath, $targetPath)) {
            throw new InvalidArgumentException('Publication requires a separate, prepared regular file.');
        }
        if (!self::pathsEqual(dirname($preparedPath), dirname($targetPath))) {
            throw new InvalidArgumentException('Publication requires a sibling temporary file.');
        }
        $published = @rename($preparedPath, $targetPath);
        // Windows readers can briefly deny delete sharing; retry without removing the old file.
        for ($attempt = 0; !$published && PHP_OS_FAMILY === 'Windows' && $attempt < 50; ++$attempt) {
            usleep(10000);
            self::fileTarget($targetPath);
            $published = @rename($preparedPath, $targetPath);
        }
        if (!$published) {
            throw new RuntimeException('Cannot publish prepared file; previous output was retained: ' . $targetPath);
        }
        clearstatcache(true, $targetPath);
    }

    public static function parseExcludePaths(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }
        $paths = [];
        foreach (explode(',', str_replace('，', ',', $value)) as $path) {
            $path = trim(str_replace('\\', '/', $path), " \t\n\r\0\x0B/");
            if ($path === '') {
                continue;
            }
            $segments = [];
            foreach (explode('/', $path) as $segment) {
                if ($segment === '' || $segment === '.') {
                    continue;
                }
                if ($segment === '..') {
                    throw new InvalidArgumentException('Exclude paths cannot contain ..: ' . $path);
                }
                $segments[] = $segment;
            }
            if ($segments === []) {
                throw new InvalidArgumentException('Exclude path must point inside the project.');
            }
            $paths[] = self::normalizeRelative(implode('/', $segments));
        }
        return array_values(array_unique($paths));
    }

    public static function shouldExclude(string $relativePath, array $excludePaths): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $relativePath = strtolower($relativePath);
        }
        foreach ($excludePaths as $excludePath) {
            if (PHP_OS_FAMILY === 'Windows') {
                $excludePath = strtolower($excludePath);
            }
            if ($relativePath === $excludePath || str_starts_with($relativePath, $excludePath . '/')) {
                return true;
            }
        }
        return false;
    }

    public static function normalizeRelative(string $path): string
    {
        return '/' . ltrim(str_replace('\\', '/', $path), '/');
    }

    public static function absolute(string $path, ?string $basePath = null): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $path = str_replace('\\', '/', $path);
        }
        if (self::isAbsolute($path)) {
            return $path;
        }
        $basePath ??= getcwd() ?: '.';
        return rtrim($basePath, PHP_OS_FAMILY === 'Windows' ? '/\\' : '/') . DIRECTORY_SEPARATOR . $path;
    }

    public static function normalizedAbsolute(string $path, ?string $basePath = null): string
    {
        $path = self::absolute($path, $basePath);
        // Foreign drive/UNC spellings remain portable, but a POSIX filename may contain literal backslashes.
        if (PHP_OS_FAMILY === 'Windows' || preg_match('~^[A-Za-z]:[\\\\/]~', $path) || str_starts_with($path, '\\\\')) {
            $path = str_replace('\\', '/', $path);
        }
        $prefix = '';
        if (preg_match('/^([A-Za-z]:|\/\/[^\/]+\/[^\/]+|\/)/', $path, $match)) {
            $prefix = $match[1];
            $path = substr($path, strlen($prefix));
        }
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }
        $root = $prefix . ($prefix !== '' && !str_ends_with($prefix, '/') ? '/' : '');
        return $segments === [] ? $root : $root . implode('/', $segments);
    }

    public static function isAbsolute(string $path): bool
    {
        return (bool)preg_match('/^(?:[A-Za-z]:[\\\\\/]|\\\\\\\\|\/)/', $path);
    }
}
