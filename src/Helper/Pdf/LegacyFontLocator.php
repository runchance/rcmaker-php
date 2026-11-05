<?php

declare(strict_types=1);

namespace RC\Helper\Pdf;

use Com\Tecnick\Pdf\Font\Font;

/** Keep native lookup first, supplementing Composer distributions with RC's converted fonts and legacy data. */
final class LegacyFontLocator extends Font
{
    /** Return only supplemental files; null leaves native lookup and errors unchanged. */
    public function locate(string $bundledDirectory): ?array
    {
        $names = array_values(array_unique([strtolower($this->data['key']), strtolower($this->data['family'])]));
        $nativeDirectories = $this->findFontDirectories();
        $directories = [];
        foreach ($nativeDirectories as $directory) {
            $directories[] = $directory;
            // glob() does not support PHAR; retain the same one-level search without extracting assets.
            if (!str_starts_with($directory, 'phar://') || !is_dir($directory)) { continue; }
            foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $entry) {
                if ($entry->isDir() && !$entry->isLink()) { $directories[] = $entry->getPathname(); }
            }
        }
        $directories = array_values(array_unique($directories));
        foreach ($names as $index => $name) {
            foreach ($directories as $directory) {
                $path = $directory.'/'.$name.'.json';
                if (!is_readable($path)) { continue; }
                if (in_array($directory, $nativeDirectories, true)) { return null; }
                return ['file' => $path, 'fallback' => $index !== 0, 'legacy' => false];
            }
        }
        foreach ($names as $index => $name) {
            if (preg_match('/\A[a-z0-9_-]+\z/D', $name) !== 1) { continue; }
            $path = $bundledDirectory.'/'.$name.'.json';
            if (is_readable($path)) {
                return ['file' => $path, 'fallback' => $index !== 0, 'legacy' => false];
            }
        }
        foreach ($names as $index => $name) {
            if (preg_match('/\A[a-z0-9_-]+\z/D', $name) !== 1) { continue; }
            foreach ($directories as $directory) {
                $path = $directory.'/'.$name.'.php';
                if (is_readable($path)) { return ['file' => $path, 'fallback' => $index !== 0, 'legacy' => true]; }
            }
        }
        return null;
    }
}
