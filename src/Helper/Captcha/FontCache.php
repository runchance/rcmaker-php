<?php

declare(strict_types=1);

namespace RC\Helper\Captcha;

/** Give FreeType checked filesystem copies of PHAR fonts; ordinary filesystem fonts are unchanged. */
final class FontCache
{
    /** Resolve one font; its content hash determines the cache filename, not caller-controlled path text. */
    public static function resolve(string $font): string
    {
        if (!str_starts_with($font, 'phar://')) {
            return $font;
        }
        $bytes = @file_get_contents($font);
        if ($bytes === false || $bytes === '') {
            throw new \RuntimeException('Unable to read the packaged captcha font.');
        }
        $directory = rtrim(\runtime_path(), '/\\') . '/captcha-fonts';
        if (is_link($directory) || (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory))) {
            throw new \RuntimeException('Unable to create a private captcha font directory.');
        }
        $hash = hash('sha256', $bytes);
        $target = $directory . '/' . $hash . '.ttf';
        $lockPath = $target . '.lock';
        if (is_link($target) || is_link($lockPath)) {
            throw new \RuntimeException('Captcha font cache cannot use symbolic links.');
        }
        if (is_file($target) && @hash_file('sha256', $target) === $hash) {
            return $target;
        }
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) {
            throw new \RuntimeException('Unable to open the captcha font cache lock.');
        }
        $temporary = null;
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Unable to lock the captcha font cache.');
            }
            if (is_link($target)) {
                throw new \RuntimeException('Captcha font cache cannot use symbolic links.');
            }
            if (is_file($target) && @hash_file('sha256', $target) === $hash) {
                return $target;
            }
            $temporary = $target . '.' . bin2hex(random_bytes(12)) . '.tmp';
            $handle = @fopen($temporary, 'x+b');
            if ($handle === false) {
                throw new \RuntimeException('Unable to stage the captcha font.');
            }
            try {
                $offset = 0;
                while ($offset < strlen($bytes)) {
                    $written = fwrite($handle, substr($bytes, $offset));
                    if ($written === false || $written === 0) {
                        throw new \RuntimeException('Unable to write the captcha font.');
                    }
                    $offset += $written;
                }
                if (!fflush($handle)) {
                    throw new \RuntimeException('Unable to flush the captcha font.');
                }
            } finally {
                fclose($handle);
            }
            if (!@rename($temporary, $target) && @hash_file('sha256', $target) !== $hash) {
                throw new \RuntimeException('Unable to publish the captcha font.');
            }
            return $target;
        } finally {
            if ($temporary !== null && is_file($temporary)) {
                @unlink($temporary);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
