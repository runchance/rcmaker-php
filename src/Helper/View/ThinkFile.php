<?php

declare(strict_types=1);

namespace RC\Helper\View;

use RC\Config;

require_once __DIR__ . '/bootstrap.php';

/** Native ThinkTemplate storage with complete-file publication for concurrent worker compilation. */
class ThinkFile extends \think\template\driver\File
{
    /** Compile to a private sibling first, then atomically replace the published PHP file. */
    public function write(string $cacheFile, string $content): void
    {
        $directory = dirname($cacheFile);
        if (str_contains($directory, '://') || !Config::mkdir($directory)) {
            throw new \RuntimeException('Template compile cache requires a writable local directory.');
        }
        $temporary = $cacheFile . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $handle = fopen($temporary, 'xb');
        if ($handle === false) { throw new \RuntimeException('Cannot create a template compile-cache file.'); }
        try {
            if (!chmod($temporary, 0600)) { throw new \RuntimeException('Cannot protect the template compile cache.'); }
            $length = strlen($content); $offset = 0;
            while ($offset < $length) {
                $written = fwrite($handle, substr($content, $offset));
                if ($written === false || $written === 0) { throw new \RuntimeException('Incomplete template compile-cache write.'); }
                $offset += $written;
            }
            if (!fflush($handle)) { throw new \RuntimeException('Cannot flush template compile cache.'); }
            fclose($handle); $handle = null;
            if (!rename($temporary, $cacheFile)) { throw new \RuntimeException('Cannot publish template compile cache.'); }
            if (function_exists('opcache_invalidate')) { opcache_invalidate($cacheFile, true); }
        } finally {
            if (is_resource($handle)) { fclose($handle); }
            if (is_file($temporary)) { unlink($temporary); }
        }
    }
}
