<?php
// Workerman/Webman-derived storage contract, MIT; original sources stay unchanged. See Workerman/MIT-LICENSE.txt.

declare(strict_types=1);

namespace RC\Http;

use Workerman\Protocols\Http\Session;
use Workerman\Protocols\Http\Session\SessionHandlerInterface;

/** Instance-owned file sessions, preserving session_<SID> payloads without the upstream shared static directory. */
class FileSessionHandler implements SessionHandlerInterface
{
    protected static string $sessionSavePath = '';
    protected static string $sessionFilePrefix = 'session_';
    private string $directory;
    private int $lifetime;
    private int $maxBytes;
    private array $locks = [];
    private int $ownerPid;
    private bool $busy = false;

    /** Framework bootstrap supplies runtime_path()/sessions; direct users should explicitly set save_path. */
    public function __construct(array $config = [])
    {
        $this->directory = self::prepareDirectory($config['save_path'] ?? (static::$sessionSavePath ?: sys_get_temp_dir() . '/rcmaker-sessions'));
        $this->lifetime = self::positive($config['lifetime'] ?? Session::$lifetime, 'lifetime');
        $this->maxBytes = self::positive($config['max_data_size'] ?? 1048576, 'max_data_size');
        $this->ownerPid = getmypid();
    }

    /** Preserve the public default-path setter; it affects future instances only. */
    public static function sessionSavePath(string $path): string
    {
        if ($path !== '') { static::$sessionSavePath = self::prepareDirectory($path); }
        return static::$sessionSavePath;
    }

    /** Legacy initialization remains available but does not import PHP's shared native save_path. */
    public static function init() { static::sessionSavePath(sys_get_temp_dir() . '/rcmaker-sessions'); }

    /** Constructor already owns the path; an open call cannot redirect an existing session backend. */
    public function open(string $savePath, string $name): bool { return true; }

    /** Atomic replacement lets readers use one stable descriptor; expired data is left for coordinated GC. */
    public function read(string $sessionId): string|false
    {
        $path = $this->path($sessionId); clearstatcache(true, $path);
        if (is_link($path)) { throw new \RuntimeException('Session files cannot be symbolic links.'); }
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            // A writer may recreate the path after fopen failed; classify misses under its stripe lock.
            $this->locked($sessionId, function (string $path) use (&$stream): bool {
                $stream = @fopen($path, 'rb');
                if ($stream === false) {
                    clearstatcache(true, $this->directory);
                    if (!is_dir($this->directory) || !is_readable($this->directory)
                        || PHP_OS_FAMILY !== 'Windows' && !is_executable($this->directory) || file_exists($path)) {
                        throw new \RuntimeException('Cannot read the session file.');
                    }
                }
                return true;
            });
            if ($stream === false) { return false; }
        }
        try {
            $stat = fstat($stream);
            if ($stat === false || ($stat['mode'] & 0170000) !== 0100000) { throw new \RuntimeException('Session path is not a regular file.'); }
            if ($stat['mtime'] <= time() - $this->lifetime) { return false; }
            if ($stat['size'] > $this->maxBytes) { throw new \UnexpectedValueException('Stored session exceeds max_data_size.'); }
            $raw = stream_get_contents($stream, $this->maxBytes === PHP_INT_MAX ? PHP_INT_MAX : $this->maxBytes + 1);
            if ($raw === false) { throw new \RuntimeException('Cannot read the session payload.'); }
            if (strlen($raw) > $this->maxBytes) { throw new \UnexpectedValueException('Stored session exceeds max_data_size.'); }
            return $raw;
        } finally { fclose($stream); }
    }

    /** Publish complete bytes with same-directory rename; failed writes never replace the previous session. */
    public function write(string $sessionId, string $sessionData): bool
    {
        if (strlen($sessionData) > $this->maxBytes) { throw new \InvalidArgumentException('Session exceeds max_data_size.'); }
        return $this->locked($sessionId, function (string $path) use ($sessionData): bool {
            $temporary = $this->directory . '.rc-session-tmp-' . bin2hex(random_bytes(16)); $stream = null;
            try {
                $stream = fopen($temporary, 'x+b');
                if ($stream === false) { throw new \RuntimeException('Cannot create a temporary session file.'); }
                if (PHP_OS_FAMILY !== 'Windows' && !chmod($temporary, 0600)) { throw new \RuntimeException('Cannot restrict session file permissions.'); }
                $offset = 0; $length = strlen($sessionData);
                while ($offset < $length) {
                    $written = fwrite($stream, substr($sessionData, $offset));
                    if ($written === false || $written === 0) { throw new \RuntimeException('Incomplete session file write.'); }
                    $offset += $written;
                }
                if (!fflush($stream)) { throw new \RuntimeException('Cannot flush session data.'); }
                fclose($stream); $stream = null;
                if (!rename($temporary, $path)) { throw new \RuntimeException('Cannot publish the session file.'); }
                clearstatcache(true, $path);
                return true;
            } finally {
                if (is_resource($stream)) { fclose($stream); }
                if (is_file($temporary)) { unlink($temporary); }
            }
        });
    }

    /** Refresh/delete/GC coordinate with writers; this is not a full read-modify-write session lock. */
    public function updateTimestamp(string $sessionId, string $data = ''): bool
    {
        return $this->locked($sessionId, function (string $path): bool {
            clearstatcache(true, $path);
            if (!is_file($path)) {
                if (file_exists($path)) { throw new \RuntimeException('Session path is not a regular file.'); }
                return false;
            }
            $modified = filemtime($path);
            if ($modified === false) { throw new \RuntimeException('Cannot inspect the session timestamp.'); }
            if ($modified <= time() - $this->lifetime) { return false; }
            if (!touch($path)) { throw new \RuntimeException('Cannot refresh the session file.'); }
            clearstatcache(true, $path); return true;
        });
    }

    /** Deleting an absent session succeeds, but directories/symlinks and IO failures are never hidden. */
    public function destroy(string $sessionId): bool
    {
        return $this->locked($sessionId, static function (string $path): bool {
            clearstatcache(true, $path);
            if (!file_exists($path)) { return true; }
            if (!is_file($path) || !unlink($path)) { throw new \RuntimeException('Cannot delete the session file.'); }
            clearstatcache(true, $path); return true;
        });
    }

    /** Inspect only valid session filenames; take the writer's lock before rechecking age and removing a file. */
    public function gc(int $maxLifetime): bool
    {
        if ($maxLifetime < 1) { throw new \InvalidArgumentException('Session GC lifetime must be positive.'); }
        foreach (new \FilesystemIterator($this->directory, \FilesystemIterator::SKIP_DOTS) as $file) {
            if (!str_starts_with($file->getFilename(), static::$sessionFilePrefix)) { continue; }
            $id = substr($file->getFilename(), strlen(static::$sessionFilePrefix));
            if (!self::validId($id) || $file->isLink()) { continue; }
            $this->locked($id, static function (string $path) use ($maxLifetime): bool {
                clearstatcache(true, $path);
                if (is_file($path)) {
                    $modified = filemtime($path);
                    if ($modified === false) { throw new \RuntimeException('Cannot inspect the session timestamp.'); }
                    if ($modified <= time() - $maxLifetime && !unlink($path)) {
                        throw new \RuntimeException('Cannot collect the expired session file.');
                    }
                }
                return true;
            });
        }
        return true;
    }

    /** Release only this instance's bounded set of lock descriptors; later operations can reopen them. */
    public function close(): bool
    {
        if ($this->busy) { throw new \LogicException('Cannot close an active file session operation.'); }
        foreach ($this->locks as $stream) { if (is_resource($stream)) { fclose($stream); } }
        $this->locks = []; return true;
    }

    /** At most 64 stripe files per directory avoid unbounded, unsafe lock-file deletion races. */
    private function locked(string $id, \Closure $operation): bool
    {
        $path = $this->path($id);
        if ($this->ownerPid !== getmypid()) { $this->busy = false; $this->close(); $this->ownerPid = getmypid(); }
        if ($this->busy) { throw new \LogicException('Reentrant file session operation.'); }
        clearstatcache(true, $path);
        if (is_link($path)) { throw new \RuntimeException('Session files cannot be symbolic links.'); }
        $key = crc32($id) & 63;
        if (!isset($this->locks[$key])) {
            $lockPath = $this->directory . '.rc-session-lock-' . $key;
            if (is_link($lockPath)) { throw new \RuntimeException('Session locks cannot be symbolic links.'); }
            $stream = fopen($lockPath, 'c+b');
            if ($stream === false) { throw new \RuntimeException('Cannot open the session lock.'); }
            $this->locks[$key] = $stream;
        }
        $stream = $this->locks[$key];
        if (!flock($stream, LOCK_EX)) { throw new \RuntimeException('Cannot lock the session file.'); }
        $this->busy = true;
        try {
            clearstatcache(true, $path);
            if (is_link($path)) { throw new \RuntimeException('Session files cannot be symbolic links.'); }
            return $operation($path);
        }
        finally { $this->busy = false; flock($stream, LOCK_UN); }
    }

    /** Identifiers can never escape the captured directory. */
    private function path(string $id): string
    {
        if (!self::validId($id)) { throw new \InvalidArgumentException('Invalid session identifier.'); }
        return $this->directory . static::$sessionFilePrefix . $id;
    }

    /** Same strict alphabet as native/RC session IDs, without accepting a trailing newline. */
    private static function validId(string $id): bool { return preg_match('/\A[a-zA-Z0-9,-]{16,256}\z/', $id) === 1; }

    /** Local directories only; preserve PHAR's external runtime choice made by the framework path helper. */
    private static function prepareDirectory(mixed $path): string
    {
        if (!is_string($path) || $path === '' || str_contains($path, "\0") || str_contains($path, '://')) {
            throw new \InvalidArgumentException('Session save_path must be a local filesystem directory.');
        }
        if (!is_dir($path)) {
            try { $created = mkdir($path, 0700, true); }
            catch (\Throwable $error) { if (!is_dir($path)) { throw $error; } $created = true; }
            if (!$created && !is_dir($path)) { throw new \RuntimeException('Cannot create session save_path.'); }
        }
        $resolved = realpath($path);
        if ($resolved === false) { throw new \RuntimeException('Cannot resolve session save_path.'); }
        return rtrim($resolved, '/\\') . DIRECTORY_SEPARATOR;
    }

    /** Reject truncated, negative or disabled storage bounds. */
    private static function positive(mixed $value, string $name): int
    {
        if ((!is_int($value) && !is_string($value)) || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            throw new \InvalidArgumentException('Invalid file session ' . $name . '.');
        }
        return (int) $value;
    }

    /** A clone must not close the original instance's live descriptors. */
    public function __clone(): void { $this->locks = []; throw new \LogicException('File session handlers cannot be cloned.'); }

    /** Active backends are not application data. */
    public function __serialize(): array { throw new \LogicException('File session handlers cannot be serialized.'); }

    /** Deserialization cannot acquire paths or lock descriptors. */
    public function __unserialize(array $data): void { throw new \LogicException('File session handlers cannot be unserialized.'); }

    /** No garbage collection or writes during handler destruction, only owned descriptor cleanup. */
    public function __destruct() { if (!$this->busy) { $this->close(); } }
}
