<?php

namespace RC\Helper\Pinyin;

use InvalidArgumentException;
use Overtrue\Pinyin\Contracts\ConverterInterface;
use Overtrue\Pinyin\Converters\AbstractConverter;
use ReflectionClass;
use RuntimeException;

/** Version-pinned PHAR bridge; ordinary filesystem conversion does not change native global configuration. */
final class Runtime
{
    private static bool $ready = false;

    /** Select a fresh adapter without mutating the native process-wide factory default. */
    public static function converter(string $strategy): ConverterInterface
    {
        if (!in_array($strategy, ['cached', 'memory', 'smart'], true)) { throw new InvalidArgumentException('Unknown pinyin converter strategy.'); }
        self::boot();
        return match ($strategy) {
            'cached' => new CachedConverter(),
            'memory' => new MemoryConverter(),
            'smart' => new SmartConverter(),
        };
    }

    /** Native 6.0.1 glob() cannot enumerate phar://; supply its immutable, naturally sorted segment paths once. */
    private static function boot(): void
    {
        if (self::$ready) { return; }
        $native = new ReflectionClass(AbstractConverter::class);
        $file = $native->getFileName();
        if (is_string($file) && str_starts_with($file, 'phar://')) {
            $root = dirname($file, 3) . '/data';
            $paths = [];
            foreach (new \DirectoryIterator($root) as $entry) {
                if ($entry->isFile() && preg_match('/\Awords-[0-9]+\.php\z/D', $entry->getFilename())) {
                    $paths[] = $entry->getPathname();
                }
            }
            sort($paths, SORT_NATURAL);
            if (count($paths) !== 10 || !is_file($root . '/chars.php') || !is_file($root . '/surnames.php')) {
                throw new RuntimeException('Pinyin 6.0.1 runtime dictionaries are incomplete in the archive.');
            }
            // This private upstream field is deliberately isolated behind the exact 6.0.1 Composer constraint.
            $property = $native->getProperty('wordSegmentPaths');
            if (!$property->isStatic() || !$property->isPrivate()) { throw new RuntimeException('Unsupported native pinyin dictionary-path contract.'); }
            $property->setValue(null, $paths);
            // A raw native call may already have cached an empty dictionary before RC bootstrap.
            \Overtrue\Pinyin\Converters\CachedConverter::clearCache();
            \Overtrue\Pinyin\Converters\SmartConverter::clearCache();
        }
        self::$ready = true;
    }
}
