<?php

namespace RC\Helper;

/**
 * One process-wide selected shared segment, using the historical zlib/string format.
 * Configure with new Shmop(['id' => 123, 'size' => 4096]); arrays are stored as JSON strings.
 * All cooperating processes must use the same IPC key and local lock directory.
 */
class Shmop
{
    protected static $id;
    protected static $shmId = [];
    protected static $dataSize;
    protected static $size = 1024;
    protected static $permission = 0600;
    protected static $config = [];

    private static $lockStream;
    private static int $lockPid = 0;
    private static ?string $lockKey = null;
    private static string $lockPath = '';
    private static array $lockIdentity = [];
    private static int $busyPid = 0;
    private static bool $busy = false;

    /** Select a complete configuration; omitted settings never leak from a previous selection. */
    public function __construct($config = [])
    {
        if (!is_array($config)) { throw new \InvalidArgumentException('Shmop configuration must be an array.'); }
        $previous = static::$config;
        static::$config = $config;
        try { static::init(); }
        catch (\Throwable $error) { static::$config = $previous; throw $error; }
    }

    /** Validate/select without creating memory or opening a persistent descriptor. */
    public static function init()
    {
        foreach (['shmop_open', 'gzcompress', 'gzuncompress'] as $function) {
            if (!function_exists($function)) { throw new \RuntimeException('Shmop requires the shmop and zlib PHP extensions.'); }
        }
        $options = self::options(static::$config);
        $previous = static::$id;
        try {
            if ($options['id'] === null) { static::generateId(); }
            $id = self::key($options['id'] ?? static::$id);
        } catch (\Throwable $error) { static::$id = $previous; throw $error; }
        static::$config = $options;
        static::$id = $id;
        static::$size = $options['size'];
        static::$permission = $options['permission'];
        static::$dataSize = null;
        if (PHP_OS_FAMILY !== 'Windows') { static::$shmId = []; }
    }

    /** Probe writable attachment; missing/inaccessible segments raise an error without creating data. */
    public static function checkShmopArea()
    {
        [$id, $options] = self::context();
        self::locked($id, $options, LOCK_SH, static function () use ($id, $options): void {
            if (self::attachment($id, $options, true) === false) {
                throw new \RuntimeException('Cannot open the selected shared memory segment for writing.');
            }
        });
    }

    /** Preserve the native read-only handle/false result; callers must release returned handles. */
    public static function exists($id)
    {
        if (self::$busy && self::$busyPid === getmypid()) { throw new \LogicException('Shared memory lock operations are not reentrant.'); }
        $id = self::key($id);
        if (!function_exists('shmop_open')) { throw new \RuntimeException('Shmop probing requires the shmop PHP extension.'); }
        return @shmop_open($id, 'a', 0, 0);
    }

    /** Store a string/scalar or JSON array; null/false remain no-ops and oversized values never overwrite data. */
    public static function write($data)
    {
        [$id, $options] = self::context();
        if ($data === null || $data === false) { return false; }
        if (is_array($data)) { $data = json_encode($data, JSON_THROW_ON_ERROR); }
        elseif (is_scalar($data) || $data instanceof \Stringable) { $data = (string) $data; }
        else { throw new \InvalidArgumentException('Shared memory values must be strings, scalars or JSON arrays.'); }
        if (strlen($data) > $options['max_data_size']) { throw new \LengthException('Shared memory value exceeds max_data_size.'); }
        $compressed = gzcompress($data, $options['compression_level']);
        if ($compressed === false) { throw new \RuntimeException('Cannot compress the shared memory value.'); }
        $bytes = strlen($compressed);
        if ($bytes > $options['size']) { throw new \LengthException('Compressed value does not fit the configured shared memory segment.'); }
        return self::locked($id, $options, LOCK_EX, static function () use ($id, $options, $compressed, $bytes): bool {
            $handle = self::attachment($id, $options, true, true);
            if ($handle === false) { throw new \RuntimeException('Cannot create or open the selected shared memory segment.'); }
            self::checkSize($handle, $options['size']);
            if (shmop_write($handle, $compressed, 0) !== $bytes) { throw new \RuntimeException('Shared memory write was incomplete.'); }
            if (static::$id === $id) { static::$dataSize = $bytes; }
            return true;
        });
    }

    /** Read exact bytes/JSON text; null means no accessible segment or an unwritten zero-filled segment. */
    public static function read()
    {
        [$id, $options] = self::context();
        return self::locked($id, $options, LOCK_SH, static function () use ($id, $options): ?string {
            $handle = self::attachment($id, $options, false);
            if ($handle === false) { return null; }
            self::checkSize($handle, $options['size']);
            $compressed = shmop_read($handle, 0, $options['size']);
            if (strspn($compressed, "\0") === strlen($compressed)) { return null; }
            $data = @gzuncompress($compressed, $options['max_data_size']);
            if ($data === false) { throw new \RuntimeException('Shared memory data is corrupt or exceeds max_data_size.'); }
            return $data;
        });
    }

    /** Request native deletion; Windows also clears the value because its PHP shim retains named mappings. */
    public static function delete()
    {
        [$id, $options] = self::context();
        return self::locked($id, $options, LOCK_EX, static function () use ($id, $options): bool {
            $handle = self::attachment($id, $options, true);
            if ($handle === false) { return false; }
            if (PHP_OS_FAMILY === 'Windows') {
                self::checkSize($handle, $options['size']);
                $size = shmop_size($handle);
                if (shmop_write($handle, str_repeat("\0", $size), 0) !== $size) {
                    throw new \RuntimeException('Cannot clear the Windows shared memory segment.');
                }
            }
            if (!shmop_delete($handle)) { throw new \RuntimeException('Cannot confirm shared memory deletion.'); }
            unset(static::$shmId[$id]);
            if (static::$id === $id) { static::$dataSize = null; }
            return true;
        });
    }

    /** Release the current PHP attachment and cached lock descriptor, never the shared value itself. */
    public static function close()
    {
        if (self::$busy && self::$busyPid === getmypid()) { throw new \LogicException('Cannot close shared memory while an operation holds its lock.'); }
        unset(static::$shmId[static::$id]);
        self::releaseLock();
    }

    /** Return the selected signed 32-bit IPC key, or null before initialization. */
    public static function getId()
    {
        return static::$id;
    }

    /** Use a physical backing file for ftok; PHAR virtual paths do not have native filesystem inodes. */
    protected static function generateId()
    {
        if (!function_exists('ftok')) { throw new \RuntimeException('Set an explicit Shmop id when ftok is unavailable.'); }
        $archive = class_exists(\Phar::class, false) ? \Phar::running(false) : '';
        $id = @ftok($archive !== '' ? $archive : __FILE__, 'b');
        if ($id === -1 || $id === 0) { throw new \RuntimeException('Cannot derive a shared memory key; configure an explicit id.'); }
        static::$id = $id;
    }

    /** Lazily initialize, then snapshot before JSON/Stringable callbacks can select a different segment. */
    private static function context(): array
    {
        if (static::$id === null) { static::init(); }
        return [static::$id, static::$config];
    }

    /** Normalize signed/unsigned spellings to one non-private native key and lock-file name. */
    private static function key(mixed $id): int
    {
        $id = self::integer($id, 'id');
        if ($id < -2147483648 || $id > 4294967295 || $id === 0) { throw new \InvalidArgumentException('Shmop id must be a non-zero 32-bit IPC key.'); }
        return $id > 2147483647 ? $id - 4294967296 : $id;
    }

    /** Accept integer settings or decimal environment strings, without float truncation. */
    private static function integer(mixed $value, string $name): int
    {
        if (is_int($value)) { return $value; }
        if (is_string($value) && ($integer = filter_var($value, FILTER_VALIDATE_INT)) !== false) { return $integer; }
        throw new \InvalidArgumentException('Shmop ' . $name . ' must be an integer.');
    }

    /** Check all options before mutating the selected configuration. */
    private static function options(array $config): array
    {
        $options = array_replace(['id' => null, 'size' => 1024, 'permission' => 0600,
            'max_data_size' => 16777216, 'compression_level' => 6, 'lock_dir' => sys_get_temp_dir()], $config);
        foreach (['size', 'permission', 'max_data_size', 'compression_level'] as $name) { $options[$name] = self::integer($options[$name], $name); }
        if ($options['id'] !== null) { $options['id'] = self::key($options['id']); }
        if ($options['size'] < 1 || $options['max_data_size'] < 1 || $options['permission'] < 0 || $options['permission'] > 0777
            || $options['compression_level'] < -1 || $options['compression_level'] > 9) { throw new \InvalidArgumentException('Invalid Shmop size, permissions or compression level.'); }
        $path = $options['lock_dir'];
        if (!is_string($path) || $path === '' || str_contains($path, "\0") || str_contains($path, '://')
            || !preg_match('~\A(?:/|[A-Za-z]:[\\\\/]|\\\\\\\\)~', $path)) { throw new \InvalidArgumentException('Shmop lock_dir must be an absolute local directory.'); }
        return $options;
    }

    /** Refuse implicit resizing/adoption of a segment created with a different size. */
    private static function checkSize(\Shmop $handle, int $size): void
    {
        if (shmop_size($handle) !== $size) { throw new \LogicException('Shared memory size differs; use matching configuration or explicitly delete/recreate it.'); }
    }

    /** Reattach on Unix to observe delete/recreate; reuse Windows mappings to avoid its repeated-open handle leak. */
    private static function attachment(int $id, array $options, bool $writable, bool $create = false): \Shmop|false
    {
        if (PHP_OS_FAMILY === 'Windows' && isset(static::$shmId[$id])) { return static::$shmId[$id]; }
        $handle = @shmop_open($id, $writable || PHP_OS_FAMILY === 'Windows' ? 'w' : 'a', 0, 0);
        if ($handle === false && $create) {
            if (($options['permission'] & 0600) !== 0600) { throw new \InvalidArgumentException('Creating shared memory requires owner read/write permissions.'); }
            $handle = @shmop_open($id, 'n', $options['permission'], $options['size']);
        }
        if ($handle !== false && PHP_OS_FAMILY === 'Windows') { static::$shmId[$id] = $handle; }
        return $handle;
    }

    /** Hold one lock per operation; callbacks during acquisition/native warnings cannot reenter it. */
    private static function locked(int $id, array $options, int $mode, \Closure $operation): mixed
    {
        $pid = getmypid();
        if (self::$busy && self::$busyPid === $pid) { throw new \LogicException('Shared memory lock operations are not reentrant.'); }
        self::$busyPid = $pid;
        self::$busy = true;
        try {
            $stream = self::lockStream($id, $options, $pid);
            try {
                if (!flock($stream, $mode)) { throw new \RuntimeException('Cannot acquire the shared memory lock.'); }
            } catch (\Throwable $error) {
                try { self::releaseLock(); } catch (\Throwable) { /* Keep the acquisition failure. */ }
                throw $error;
            }
            $failure = null;
            try {
                // Check after acquiring, not before waiting: the pathname must still name this locked inode.
                clearstatcache(true, self::$lockPath);
                $current = @lstat(self::$lockPath);
                if ($current === false || ($current['mode'] & 0170000) !== 0100000 || $current['size'] !== 0
                    || $current['dev'] !== self::$lockIdentity['dev'] || $current['ino'] !== self::$lockIdentity['ino']) {
                    throw new \RuntimeException('Shared memory lock identity changed.');
                }
                return $operation();
            } catch (\Throwable $error) {
                $failure = $error;
                throw $error;
            } finally {
                try {
                    if (!flock($stream, LOCK_UN)) { throw new \RuntimeException('Cannot release the shared memory lock.'); }
                } catch (\Throwable $error) {
                    try { self::releaseLock(); } catch (\Throwable) { /* Keep the first operation or unlock failure. */ }
                    if ($failure === null) { throw $error; }
                }
            }
        } finally { self::$busy = false; }
    }

    /** Cache at most one descriptor per process; a fork always drops its inherited open-file description. */
    private static function lockStream(int $id, array $options, int $pid): mixed
    {
        $key = $options['lock_dir'] . "\0" . $id;
        if (self::$lockPid === $pid && self::$lockKey === $key && is_resource(self::$lockStream)) { return self::$lockStream; }
        self::releaseLock();
        $directory = realpath($options['lock_dir']);
        if ($directory === false || !is_dir($directory)) { throw new \RuntimeException('Shmop lock_dir must exist.'); }
        $path = $directory . DIRECTORY_SEPARATOR . 'rcmaker-shmop-' . sprintf('%08x', $id & 0xffffffff) . '.lock';
        clearstatcache(true, $path);
        if (!file_exists($path) && !is_link($path)) {
            $temporary = tempnam($directory, '.rc-shmop-');
            if ($temporary === false) { throw new \RuntimeException('Cannot create a private shared memory lock.'); }
            $failure = null;
            try {
                if (realpath(dirname($temporary)) !== $directory) { throw new \RuntimeException('Shared memory lock creation fell back to another directory.'); }
                if (!@link($temporary, $path) && !is_file($path)) { throw new \RuntimeException('Cannot publish the shared memory lock.'); }
            } catch (\Throwable $error) {
                $failure = $error;
                throw $error;
            } finally {
                try {
                    if (!unlink($temporary)) { throw new \RuntimeException('Cannot remove the temporary shared memory lock.'); }
                } catch (\Throwable $error) {
                    if ($failure === null) { throw $error; }
                }
            }
        }
        clearstatcache(true, $path);
        if (is_link($path) || !is_file($path)) { throw new \RuntimeException('Shared memory lock must be a regular non-symlink file.'); }
        // POSIX child exec must not retain our cached descriptor and prolong a parent's file lock.
        $stream = @fopen($path, PHP_OS_FAMILY === 'Windows' ? 'r+b' : 'r+be');
        if ($stream === false) { throw new \RuntimeException('Cannot open the shared memory lock.'); }
        try {
            $opened = fstat($stream);
            if ($opened === false || ($opened['mode'] & 0170000) !== 0100000 || $opened['size'] !== 0) {
                throw new \RuntimeException('Shared memory lock must be an empty regular file.');
            }
            self::$lockIdentity = ['dev' => $opened['dev'], 'ino' => $opened['ino']];
            self::$lockPath = $path;
            self::$lockKey = $key;
            self::$lockPid = $pid;
            return self::$lockStream = $stream;
        } catch (\Throwable $error) {
            try { fclose($stream); } catch (\Throwable) { /* Keep the handle validation failure. */ }
            throw $error;
        }
    }

    /** Closing only our descriptor releases resources without unlinking the shared lock inode. */
    private static function releaseLock(): void
    {
        $stream = self::$lockStream;
        self::$lockStream = null;
        self::$lockKey = null;
        self::$lockIdentity = [];
        self::$lockPath = '';
        self::$lockPid = 0;
        if (is_resource($stream) && !fclose($stream)) { throw new \RuntimeException('Cannot close the shared memory lock.'); }
    }
}
