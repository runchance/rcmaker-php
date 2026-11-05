<?php

declare(strict_types=1);

namespace RC\Cli;

use RuntimeException;

/** Preserve the source-pinned public slider engine, not application PHP or the RC adapter being protected. */
final class EncryptionSources
{
    private const SHA256 = 'd3120493acd58db5de00f5ff0477d47f1e201b1a60e235f2392d4eae2acddd6d';
    private const BYTES = 90765;

    /** Capture verified bytes only from an already-private staging tree; never rewrite the live project here. */
    public static function capture(string $source): array
    {
        $directory = is_dir($source);
        if (!$directory && !is_file($source)) { throw new RuntimeException('Encryption staging input is missing.'); }
        $files = $directory ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS))
            : [new \SplFileInfo($source)];
        $preserved = [];
        foreach ($files as $file) {
            if (!$file->isFile() || $file->isLink() || ($directory && $file->getFilename() !== 'Captcha.php')) { continue; }
            $path = $file->getPathname();
            $bytes = file_get_contents($path, false, null, 0, self::BYTES + 1);
            if ($bytes === false) { throw new RuntimeException('Cannot inspect source-verified encryption input.'); }
            if (strlen($bytes) === self::BYTES && hash('sha256', $bytes) === self::SHA256) {
                $preserved[$path] = ['bytes' => $bytes, 'mode' => $file->getPerms() & 0777];
            } elseif (str_ends_with(str_replace('\\', '/', $path), '/ThirdParty/zxf-captcha/src/Captcha.php')) {
                throw new RuntimeException('Restore the original zxf/captcha 1.1.0 core before encrypting this project.');
            }
        }
        return $preserved;
    }

    /** Restore only captured public bytes before the existing transactional publication point. */
    public static function restore(array $sources): int
    {
        foreach ($sources as $path => $source) {
            $path = Filesystem::fileTarget($path); $temporary = $stream = null;
            if (!is_string($source['bytes'] ?? null) || hash('sha256', $source['bytes']) !== self::SHA256) { throw new RuntimeException('Invalid source-preservation record.'); }
            try {
                [$temporary, $stream] = Filesystem::temporaryFile($path, 'rcmaker-public-source');
                Filesystem::writeToStream($stream, $source['bytes']);
                if (!fflush($stream)) { throw new RuntimeException('Cannot flush the original public engine.'); }
                $closed = fclose($stream); $stream = null;
                if (!$closed || !chmod($temporary, $source['mode'])) { throw new RuntimeException('Cannot finish the original public engine.'); }
                Filesystem::publishFile($temporary, $path); $temporary = null;
                if (hash_file('sha256', $path) !== self::SHA256) { throw new RuntimeException('Original public engine was not preserved.'); }
            } finally {
                if (is_resource($stream)) { fclose($stream); }
                if ($temporary !== null && file_exists($temporary)) { Filesystem::removeFile($temporary); }
            }
        }
        return count($sources);
    }
}
