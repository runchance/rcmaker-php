<?php

declare(strict_types=1);

namespace RC\Configuration;

/** Cold-path config I/O; cached Config::get calls do not enter this helper. */
final class Files
{
    /** Accept relative config names, never stream URLs, dot segments or platform-specific aliases. */
    public static function name(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        if ($name === '' || preg_match('/[\x00-\x1f\x7f:]/', $name)) { throw new \InvalidArgumentException('Invalid configuration filename.'); }
        foreach (explode('/', $name) as $part) {
            if ($part === '' || $part === '.' || $part === '..'
                || (PHP_OS_FAMILY === 'Windows' && (rtrim($part, '. ') !== $part || preg_match('/\A(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|\z)/iD', $part)))) {
                throw new \InvalidArgumentException('Invalid configuration path component.');
            }
        }
        return PHP_OS_FAMILY === 'Windows' ? strtolower($name) : $name;
    }

    /** Include in a parameter-free local scope and discard unexpected output on failure. */
    public static function load(string $path, bool $refresh = false): mixed
    {
        if ($refresh && function_exists('opcache_invalidate')) { opcache_invalidate($path, true); }
        $level = ob_get_level(); ob_start();
        try {
            $value = (static function () { return include func_get_arg(0); })($path);
            if (ob_get_level() !== $level + 1 || ob_get_length() !== 0) {
                throw new \RuntimeException('Configuration files must return data without output.');
            }
            return $value;
        } finally { while (ob_get_level() > $level) { ob_end_clean(); } }
    }

    /** scandir supports the PHAR wrapper, unlike glob; hidden lock/temp files are excluded. */
    public static function listing(string $directory): array
    {
        if (!is_dir($directory)) {
            if (file_exists($directory) || is_link($directory)) { throw new \RuntimeException('Configuration directory is not a directory.'); }
            return [];
        }
        $entries = @scandir($directory);
        if ($entries === false) { throw new \RuntimeException('Cannot enumerate configuration files.'); }
        $names = [];
        foreach ($entries as $entry) {
            if ($entry[0] !== '.' && str_ends_with($entry, '.php') && is_file($directory . '/' . $entry)) { $names[] = self::name(substr($entry, 0, -4)); }
        }
        sort($names, SORT_STRING);
        return $names;
    }

    /** Serialize writers with a stable lock; reread disk to avoid overwriting another writer's keys. */
    public static function update(string $directory, string $name, int|string $key, mixed $value): array
    {
        if (str_contains($directory, '://')) { throw new \RuntimeException('Packaged configuration is read-only; use external environment or data files.'); }
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) { throw new \RuntimeException('Cannot create configuration directory.'); }
        $root = realpath($directory);
        if ($root === false) { throw new \RuntimeException('Cannot resolve configuration directory.'); }
        $parts = explode('/', $name); $base = array_pop($parts) . '.php'; $parent = $root;
        foreach ($parts as $part) {
            $parent .= DIRECTORY_SEPARATOR . $part;
            if (is_link($parent)) { throw new \RuntimeException('Configuration writes cannot traverse child symlinks.'); }
            if (!is_dir($parent) && !@mkdir($parent, 0755) && !is_dir($parent)) { throw new \RuntimeException('Cannot create configuration subdirectory.'); }
        }
        $path = $parent . DIRECTORY_SEPARATOR . $base;
        $lockPath = $parent . DIRECTORY_SEPARATOR . '.rc-config-' . hash('sha256', $base) . '.lock';
        self::regular($path); self::regular($lockPath);
        $lock = @fopen($lockPath, 'x+b');
        if ($lock === false) { $lock = @fopen($lockPath, 'r+b'); }
        if ($lock === false) { throw new \RuntimeException('Cannot open configuration lock.'); }
        $temporary = null;
        try {
            if (!flock($lock, LOCK_EX)) { throw new \RuntimeException('Cannot lock configuration.'); }
            clearstatcache(true, $lockPath); self::regular($lockPath);
            $opened = fstat($lock); $current = lstat($lockPath);
            if ($opened === false || $current === false || $opened['ino'] !== $current['ino'] || $opened['dev'] !== $current['dev']) { throw new \RuntimeException('Configuration lock changed during open.'); }
            self::regular($path);
            $values = [];
            if (is_file($path)) {
                $source = @file_get_contents($path);
                if ($source === false) { throw new \RuntimeException('Cannot read configuration before update.'); }
                Literal::assertSource($source);
                if (function_exists('opcache_invalidate')) { opcache_invalidate($path, true); }
                $values = self::load($path);
                if (!is_array($values)) { throw new \InvalidArgumentException('Persistent configuration must return an array.'); }
            }
            if (array_key_exists($key, $values) && $values[$key] === $value) { return [false, $values]; }
            $values[$key] = $value;
            $source = Literal::export($values);
            $temporary = $parent . DIRECTORY_SEPARATOR . '.rc-config-' . bin2hex(random_bytes(12)) . '.tmp';
            $stream = @fopen($temporary, 'x+b');
            if ($stream === false) { throw new \RuntimeException('Cannot create configuration temporary file.'); }
            try {
                $mode = is_file($path) ? (fileperms($path) & 0777) : 0600;
                if (!@chmod($temporary, $mode)) { throw new \RuntimeException('Cannot set configuration permissions.'); }
                $offset = 0; $length = strlen($source);
                while ($offset < $length) {
                    $written = fwrite($stream, substr($source, $offset));
                    if ($written === false || $written === 0) { throw new \RuntimeException('Cannot write complete configuration.'); }
                    $offset += $written;
                }
                if (!fflush($stream)) { throw new \RuntimeException('Cannot flush configuration.'); }
            } finally { fclose($stream); }
            self::regular($path);
            $published = @rename($temporary, $path);
            // Windows readers can briefly hold non-delete-sharing handles; never unlink the old file.
            for ($attempt = 0; !$published && PHP_OS_FAMILY === 'Windows' && $attempt < 50; ++$attempt) {
                usleep(10000); $published = @rename($temporary, $path);
            }
            if (!$published) { throw new \RuntimeException('Cannot atomically replace configuration.'); }
            $temporary = null;
            clearstatcache(true, $path);
            if (function_exists('opcache_invalidate')) { opcache_invalidate($path, true); }
            return [true, $values];
        } finally {
            if ($temporary !== null && is_file($temporary)) { unlink($temporary); }
            flock($lock, LOCK_UN); fclose($lock);
        }
    }

    /** Avoid following symlink/hardlink targets when publishing configuration or acquiring its lock. */
    private static function regular(string $path): void
    {
        clearstatcache(true, $path); $info = @lstat($path);
        if ($info !== false && (($info['mode'] & 0170000) !== 0100000 || $info['nlink'] > 1)) {
            throw new \RuntimeException('Configuration updates require ordinary, unlinked files.');
        }
    }
}
