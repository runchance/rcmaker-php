<?php

declare(strict_types=1);

namespace RC\Helper\Cache\think\cache\driver;

use RC\Helper\Cache\CacheBehavior;
use RuntimeException;
use think\exception\InvalidArgumentException;
use think\exception\InvalidCacheException;

require_once dirname(__DIR__, 3) . '/bootstrap.php';

/** Local file cache with bounded cleanup, locked reads/writes and atomic counters. */
class File extends \think\cache\driver\File
{
    use CacheBehavior;

    private string $storageRoot;
    private int $hashLength;

    /** Configure an external writable cache directory; never use a PHAR or filesystem root. */
    public function __construct(array $options = [])
    {
        $path = $options['path'] ?? '';
        if ($path === '') {
            $path = function_exists('runtime_path') ? \runtime_path() . '/cache' : sys_get_temp_dir() . '/rcmaker/cache';
        }
        if (!is_string($path) || str_contains($path, '://') || str_contains($path, "\0")
            || !preg_match('~^(?:[A-Za-z]:[/\\\\]|/|\\\\\\\\)~', $path)) {
            throw new InvalidArgumentException('File cache path must be an absolute local writable directory.');
        }
        $prefix = (string) ($options['prefix'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_.-]*$/D', $prefix) || in_array($prefix, ['.', '..'], true)) {
            throw new InvalidArgumentException('File cache prefix must be a single safe directory name.');
        }
        $hash = $options['hash_type'] ?? 'md5';
        if (!in_array($hash, hash_algos(), true)) {
            throw new InvalidArgumentException('Unknown file cache hash algorithm.');
        }
        if (!is_dir($path) && !@mkdir($path, 0750, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create file cache directory.');
        }
        $root = realpath($path);
        if ($root === false || dirname($root) === $root) {
            throw new InvalidArgumentException('The filesystem root cannot be used for file caching.');
        }
        $options['path'] = rtrim($root, '/\\') . DIRECTORY_SEPARATOR;
        $options['prefix'] = $prefix;
        parent::__construct($options);
        $this->storageRoot = $options['path'] . ($prefix !== '' ? $prefix . DIRECTORY_SEPARATOR : '');
        $this->hashLength = strlen(hash($hash, ''));
        if ($this->options['data_compress'] && !function_exists('gzcompress')) {
            throw new RuntimeException('Compressed file caching requires the zlib extension.');
        }
    }

    /** Map logical keys to digest filenames; the literal prefix "0" is supported. */
    public function getCacheKey(string $name): string
    {
        $hash = hash($this->options['hash_type'], $name);
        if ($this->options['cache_subdir']) {
            $hash = substr($hash, 0, 2) . DIRECTORY_SEPARATOR . substr($hash, 2);
        }
        return $this->storageRoot . $hash . '.php';
    }

    /** Read a complete entry under a shared lock; expired/corrupt files remain cache misses. */
    protected function getRaw(string $name)
    {
        $this->readTimes++;
        $path = $this->getCacheKey($name);
        if (!$this->safePath($path)) {
            return null;
        }
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            return null;
        }
        try {
            if (!flock($stream, LOCK_SH)) {
                return null;
            }
            return $this->readEntry($stream);
        } finally {
            fclose($stream);
        }
    }

    /** Check presence without confusing a stored false/null with a cache miss. */
    public function has($name): bool
    {
        $missing = new \stdClass();
        return $this->get($name, $missing) !== $missing;
    }

    /** Write using the same inode lock as readers/counters, preserving absolute expiry. */
    public function set($name, $value, $expire = null): bool
    {
        $ttl = $this->getExpireTime($expire ?? $this->options['expire']);
        if ($ttl < 0) {
            return $this->delete($name);
        }
        $payload = $this->encodeEntry($value, $ttl === 0 ? 0 : time() + $ttl);
        return $this->withWriteLock($name, fn ($stream): bool => $this->writeEntry($stream, $payload));
    }

    /** Increment an integer atomically; an existing entry keeps its original expiration. */
    public function inc($name, $step = 1, $expire = null)
    {
        if (!is_int($step)) {
            throw new InvalidArgumentException('Cache counter step must be an integer.');
        }
        $ttl = $expire === null ? null : $this->getExpireTime($expire);
        if ($ttl !== null && $ttl <= 0) {
            throw new InvalidArgumentException('An initial counter TTL must be positive.');
        }
        return $this->withWriteLock($name, function ($stream) use ($step, $ttl) {
            $entry = $this->readEntry($stream);
            $current = $entry === null ? 0 : $this->unserialize($entry['content']);
            if (!is_int($current) || !is_int($next = $current + $step)) {
                throw new InvalidArgumentException('Cache counters must contain integers within the PHP integer range.');
            }
            $payload = $this->encodeEntry($next, $entry['expires_at'] ?? ($ttl === null ? 0 : time() + $ttl));
            return $this->writeEntry($stream, $payload) ? $next : false;
        });
    }

    /** Decrement through the same atomic update path. */
    public function dec($name, $step = 1)
    {
        if (!is_int($step) || $step === PHP_INT_MIN) {
            throw new InvalidArgumentException('Cache counter step is outside the supported integer range.');
        }
        return $this->inc($name, -$step);
    }

    /** Remove a value without unlinking an inode that another writer may have opened. */
    public function delete($name): bool
    {
        $path = $this->getCacheKey($name);
        clearstatcache(true, $path);
        if (!file_exists($path)) {
            return true;
        }
        return $this->withWriteLock($name, fn ($stream): bool => $this->writeEntry($stream, ''));
    }

    /** Pull is one locked read-and-delete, so two consumers cannot both receive a value. */
    public function pull($name, $default = null)
    {
        return $this->withWriteLock($name, function ($stream) use ($name, $default) {
            $entry = $this->readEntry($stream);
            if ($entry === null) {
                return $this->getDefaultValue($name, $default);
            }
            try {
                $value = $this->unserialize($entry['content']);
            } catch (InvalidCacheException) {
                $value = $this->getDefaultValue($name, $default);
            }
            if (!$this->writeEntry($stream, '')) {
                throw new RuntimeException('Unable to remove pulled cache value.');
            }
            return $value;
        });
    }

    /** Replace/delete an existing string only if unchanged; keep its deadline. null means delete, not a stored null. */
    public function compareAndSwap($name, string $expected, ?string $replacement): bool
    {
        $result = $this->withWriteLock($name, function ($stream) use ($expected, $replacement): ?bool {
            $entry = $this->readEntry($stream);
            if ($entry === null) { return null; }
            try { $value = $this->unserialize($entry['content']); }
            catch (InvalidCacheException) { return null; }
            if ($value !== $expected) { return null; }
            return $this->writeEntry($stream, $replacement === null ? '' : $this->encodeEntry($replacement, $entry['expires_at']));
        });
        if ($result === false) { throw new RuntimeException('Unable to confirm conditional file-cache update.'); }
        return $result === true;
    }

    /** Atomically create/replace a string and refresh its TTL; null expects a missing key, not a stored null. */
    public function compareAndSwapWithTtl($name, ?string $expected, string $replacement, int $ttl): bool
    {
        if ($ttl < 1 || $ttl > PHP_INT_MAX - time()) { throw new InvalidArgumentException('Conditional cache TTL must be a positive supported duration.'); }
        $result = $this->withWriteLock($name, function ($stream) use ($expected, $replacement, $ttl): ?bool {
            $entry = $this->readEntry($stream);
            if ($entry === null) {
                if ($expected !== null) { return null; }
            } else {
                if ($expected === null) { return null; }
                try { $value = $this->unserialize($entry['content']); }
                catch (InvalidCacheException) { return null; }
                if ($value !== $expected) { return null; }
            }
            return $this->writeEntry($stream, $this->encodeEntry($replacement, time() + $ttl));
        });
        if ($result === false) { throw new RuntimeException('Unable to confirm expiring file-cache update.'); }
        return $result === true;
    }

    /** Append application array data under a lock, retaining the 1,000-item bound. */
    public function push($name, $value): void
    {
        $this->appendValue($name, $value, 1000);
    }

    /** Append tag membership atomically; do not silently discard keys after 1,000 members. */
    public function append($name, $value): void
    {
        $this->appendValue($name, $value, 0);
    }

    /** Clear only digest-named cache files in this exact prefix, never arbitrary directory contents. */
    public function clear(): bool
    {
        $this->writeTimes++;
        if (!is_dir($this->storageRoot) || is_link(rtrim($this->storageRoot, '/\\'))) {
            return !is_link(rtrim($this->storageRoot, '/\\'));
        }
        $success = true;
        $directories = [$this->storageRoot];
        if ($this->options['cache_subdir']) {
            $directories = [];
            foreach (new \FilesystemIterator($this->storageRoot) as $item) {
                if ($item->isDir() && !$item->isLink() && preg_match('/^[a-f0-9]{2}$/D', $item->getFilename())) {
                    $directories[] = $item->getPathname() . DIRECTORY_SEPARATOR;
                }
            }
        }
        foreach ($directories as $directory) {
            foreach (new \FilesystemIterator($directory) as $item) {
                if ($item->isFile() && $this->safePath($item->getPathname())) {
                    $success = $this->clearFile($item->getPathname()) && $success;
                }
            }
        }
        return $success;
    }

    /** Validate physical tag members before deleting, including tampered tag metadata. */
    public function clearTag($keys): void
    {
        foreach ($keys as $path) {
            if (!is_string($path) || !$this->safePath($path)) {
                throw new InvalidArgumentException('Cache tag contains a path outside this cache store.');
            }
            if (!$this->clearFile($path)) {
                throw new RuntimeException('Unable to clear tagged cache file.');
            }
        }
    }

    /** Execute an update while holding one exclusive file lock; false means an I/O failure. */
    private function withWriteLock(string $name, \Closure $operation): mixed
    {
        $this->writeTimes++;
        $path = $this->getCacheKey($name);
        if (!$this->safePath($path)) {
            return false;
        }
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            return false;
        }
        if (!$this->safePath($path)) {
            return false;
        }
        $stream = @fopen($path, 'c+b');
        if ($stream === false) {
            return false;
        }
        try {
            return flock($stream, LOCK_EX) ? $operation($stream) : false;
        } finally {
            fclose($stream);
        }
    }

    /** Parse new absolute-expiry headers and existing ThinkCache duration headers. */
    private function readEntry($stream): ?array
    {
        rewind($stream);
        $header = fread($stream, 32);
        if (!preg_match('/\A<\?php\n\/\/([0-9]{12}|#[0-9]{11})\n exit\(\);\?>\n\z/', $header, $match)) {
            return null;
        }
        $stamp = $match[1];
        $expiry = $stamp[0] === '#' ? (int) substr($stamp, 1) : (int) $stamp;
        if ($stamp[0] !== '#' && $expiry !== 0) {
            $expiry += fstat($stream)['mtime'];
        }
        if ($expiry !== 0 && $expiry <= time()) {
            return null;
        }
        $payload = stream_get_contents($stream);
        if ($payload === false) {
            return null;
        }
        if ($this->options['data_compress']) {
            $payload = @gzuncompress($payload);
        }
        return is_string($payload) ? ['content' => $payload, 'expires_at' => $expiry] : null;
    }

    /** Serialize a complete entry; the # marker distinguishes absolute time from old TTL headers. */
    private function encodeEntry(mixed $value, int $expiresAt): string
    {
        if ($expiresAt < 0 || $expiresAt > 99999999999) {
            throw new InvalidArgumentException('File cache expiration is outside the supported range.');
        }
        $payload = $this->serialize($value);
        if ($this->options['data_compress']) {
            $payload = gzcompress($payload, 3);
        }
        return "<?php\n//#" . sprintf('%011d', $expiresAt) . "\n exit();?>\n" . $payload;
    }

    /** Replace the locked file completely, handling short writes without exposing stale suffixes. */
    private function writeEntry($stream, string $data): bool
    {
        rewind($stream);
        $length = strlen($data);
        $written = 0;
        while ($written < $length) {
            $count = fwrite($stream, substr($data, $written));
            if ($count === false || $count === 0) {
                ftruncate($stream, 0);
                return false;
            }
            $written += $count;
        }
        return ftruncate($stream, $length) && fflush($stream);
    }

    /** Mutate arrays once per lock, preserving the expiration of existing entries. */
    private function appendValue(string $name, mixed $value, int $limit): void
    {
        $result = $this->withWriteLock($name, function ($stream) use ($value, $limit): bool {
            $entry = $this->readEntry($stream);
            $items = $entry === null ? [] : $this->unserialize($entry['content']);
            if (!is_array($items)) {
                throw new InvalidArgumentException('Only an array cache value can be appended.');
            }
            if (!in_array($value, $items, true)) {
                $items[] = $value;
                if ($limit > 0 && count($items) > $limit) {
                    array_shift($items);
                }
            }
            return $this->writeEntry($stream, $this->encodeEntry($items, $entry['expires_at'] ?? 0));
        });
        if (!$result) {
            throw new RuntimeException('Unable to append file cache data.');
        }
    }

    /** Restrict physical paths to the configured digest layout and reject symlink traversal. */
    private function safePath(string $path): bool
    {
        if (!str_starts_with($path, $this->storageRoot)) {
            return false;
        }
        $relative = substr($path, strlen($this->storageRoot));
        $pattern = $this->options['cache_subdir']
            ? '[a-f0-9]{2}[/\\\\][a-f0-9]{' . ($this->hashLength - 2) . '}'
            : '[a-f0-9]{' . $this->hashLength . '}';
        return (bool) preg_match('~^' . $pattern . '\.php$~D', $relative)
            && !is_link($path) && !is_link(dirname($path))
            && !is_link(rtrim($this->storageRoot, '/\\'));
    }

    /** Only cache headers or our empty deletion tombstones qualify for physical cleanup. */
    private function clearFile(string $path): bool
    {
        if (!is_file($path)) {
            return true;
        }
        $header = @file_get_contents($path, false, null, 0, 8);
        if ($header !== '' && $header !== "<?php\n//") {
            return true;
        }
        return @unlink($path);
    }
}
