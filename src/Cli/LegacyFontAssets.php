<?php

declare(strict_types=1);

namespace RC\Cli;

use RC\Helper\Pdf\LegacyFontDefinition;
use RuntimeException;

/** Convert only data-only legacy font definitions inside an already-private CLI staging tree. */
final class LegacyFontAssets
{
    /** Prepare one sidecar at a time; return paths, not every font's expanded width tables, for final stamping. */
    public static function prepare(string $source): array
    {
        require_once dirname(__DIR__).'/Helper/Pdf/LegacyFontDefinition.php';
        $files = is_dir($source) ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS))
            : [new \SplFileInfo($source)];
        $records = [];
        foreach ($files as $file) {
            if (!$file->isFile() || $file->isLink() || strtolower($file->getExtension()) !== 'php') { continue; }
            $path = $file->getPathname();
            $prefix = file_get_contents($path, false, null, 0, 8192);
            if ($prefix === false) { throw new RuntimeException('Cannot inspect staged PHP input.'); }
            $sidecar = $path.LegacyFontDefinition::SIDECAR_SUFFIX;
            if (LegacyFontDefinition::isProtected($prefix)) {
                if (!file_exists($sidecar)) { continue; }
                self::loadReader();
                $json = self::readSidecar($sidecar); $bytes = file_get_contents($path);
                if ($bytes === false) { throw new RuntimeException('Cannot inspect protected font input.'); }
                LegacyFontDefinition::fromMetadata($bytes, $json);
                $records[] = ['file' => $path, 'sidecar' => $sidecar, 'created' => false];
                continue;
            }
            if (!LegacyFontDefinition::mayContainData($prefix)) { continue; }
            self::loadReader();
            $bytes = file_get_contents($path, false, null, 0, LegacyFontDefinition::MAX_SOURCE_BYTES + 1);
            if ($bytes === false) { throw new RuntimeException('Cannot read staged font input.'); }
            try { $font = LegacyFontDefinition::decode($bytes); }
            catch (\Com\Tecnick\Pdf\Font\Exception) { continue; }
            $created = !file_exists($sidecar);
            if (!$created) { LegacyFontDefinition::readMetadata(self::readSidecar($sidecar)); }
            $hash = hash('sha256', $bytes);
            self::writeSidecar($sidecar, LegacyFontDefinition::metadata($font, $hash, $hash));
            $records[] = ['file' => $path, 'sidecar' => $sidecar, 'created' => $created];
        }
        return $records;
    }

    /** Bind metadata to real encoder output; existing protected inputs retain matching records on repeated encryption. */
    public static function finalize(array $records): void
    {
        foreach ($records as $record) {
            $data = LegacyFontDefinition::readMetadata(self::readSidecar($record['sidecar']));
            $bytes = file_get_contents(Filesystem::fileTarget($record['file']));
            if ($bytes === false || !LegacyFontDefinition::isProtected($bytes)) { throw new RuntimeException('Legacy font source was not protected by the encoder.'); }
            $hash = hash('sha256', $bytes);
            if ($data['file_sha256'] === $hash) { continue; }
            self::writeSidecar($record['sidecar'], LegacyFontDefinition::metadata($data['font'], $data['source_sha256'], $hash));
        }
    }

    /** Load only the font reader dependency graph, without constructing a document or reading project configuration. */
    private static function loadReader(): void
    {
        require_once dirname(__DIR__).'/Helper/Pdf/autoload.php';
        require_once dirname(__DIR__).'/Helper/Pdf/LegacyFontEncoding.php';
        require_once dirname(__DIR__).'/Helper/Pdf/LegacyFontDefinition.php';
    }

    /** A reserved metadata name cannot be a link, a directory, or an unrelated file silently overwritten. */
    private static function readSidecar(string $path): string
    {
        $path = Filesystem::fileTarget($path);
        $bytes = file_get_contents($path);
        if ($bytes === false) { throw new RuntimeException('Cannot read staged font metadata.'); }
        return $bytes;
    }

    /** Use existing file publication primitives; these paths must belong to the caller's private workspace. */
    private static function writeSidecar(string $path, string $json): void
    {
        $path = Filesystem::fileTarget($path); $temporary = $stream = null;
        $mode = file_exists($path) ? fileperms($path) & 0777 : 0600;
        try {
            [$temporary, $stream] = Filesystem::temporaryFile($path, 'rcmaker-font-metadata');
            Filesystem::writeToStream($stream, $json);
            if (!fflush($stream)) { throw new RuntimeException('Cannot flush font metadata.'); }
            $closed = fclose($stream); $stream = null;
            if (!$closed || !chmod($temporary, $mode)) { throw new RuntimeException('Cannot finish font metadata.'); }
            Filesystem::publishFile($temporary, $path); $temporary = null;
        } finally {
            if (is_resource($stream)) { fclose($stream); }
            if ($temporary !== null && file_exists($temporary)) { Filesystem::removeFile($temporary); }
        }
    }
}
