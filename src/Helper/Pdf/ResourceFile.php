<?php

declare(strict_types=1);

namespace RC\Helper\Pdf;

use Com\Tecnick\File\Exception as FileException;
use Com\Tecnick\File\File;
use RC\Cli\Filesystem;

/** Add read-only, explicitly allowlisted PHAR assets to the upstream file sandbox. */
final class ResourceFile extends File
{
    private const READ_CACHE_MAX_FILES = 32;
    private const READ_CACHE_MAX_BYTES = 8 * 1024 * 1024;

    private array $pharRoots = [];
    private array $readCache = [];
    private int $readCacheBytes = 0;

    /** Pass trusted application/font roots, never request-provided paths, in allowedPaths. */
    public function __construct(array $options)
    {
        $this->setPharRoots($options['allowedPaths'] ?? []);
        parent::__construct(...$options);
    }

    /** Keep the PHAR and native allowlists aligned when upstream callers change them. */
    public function setAllowedPaths(array $allowedPaths): static
    {
        parent::setAllowedPaths($allowedPaths);
        $this->setPharRoots($allowedPaths);
        $this->clearReadCache();
        return $this;
    }

    /** A host-policy change must apply even to EPS content already loaded by this document. */
    public function setAllowedHosts(array $allowedHosts): static
    {
        parent::setAllowedHosts($allowedHosts);
        $this->clearReadCache();
        return $this;
    }

    /** Authentication, redirects and other changed transfer options require a fresh EPS read. */
    public function setCurlOpts(array $curlopts): static
    {
        parent::setCurlOpts($curlopts);
        $this->clearReadCache();
        return $this;
    }

    /** Keep cached reads subject to the caller's current path comparison policy. */
    public function setCaseSensitivePaths(?bool $caseSensitivePaths): static
    {
        parent::setCaseSensitivePaths($caseSensitivePaths);
        $this->clearReadCache();
        return $this;
    }

    /** Validate first; a rejected limit must leave the active policy and its cache unchanged. */
    public function setMaxRemoteSize(int $maxRemoteSize): static
    {
        parent::setMaxRemoteSize($maxRemoteSize);
        $this->clearReadCache();
        return $this;
    }

    /** Discard this reader's EPS source snapshots, for example when explicitly refreshing an asset. */
    public function clearReadCache(): void
    {
        $this->readCache = [];
        $this->readCacheBytes = 0;
    }

    /** Reuse bounded EPS source bytes; regular getFileData() calls retain upstream read behavior. */
    public function getCachedFileData(string $file): string|false
    {
        $local = $file;
        if ($this->isValidFile($local)) {
            $identity = 'file:' . $this->resolveValidatedPath($local);
        } else {
            $url = $file;
            if (!$this->isValidURL($url)) {
                return $this->getFileData($file);
            }
            $identity = 'url:' . $url;
        }
        $key = hash('sha256', $identity);
        if (isset($this->readCache[$key])) {
            $data = $this->readCache[$key];
            unset($this->readCache[$key]);
            $this->readCache[$key] = $data;
            return $data;
        }

        $data = $this->getFileData($file);
        if (!is_string($data) || $data === '' || strlen($data) > self::READ_CACHE_MAX_BYTES) {
            return $data;
        }
        $bytes = strlen($data);
        while (count($this->readCache) >= self::READ_CACHE_MAX_FILES
            || $this->readCacheBytes + $bytes > self::READ_CACHE_MAX_BYTES) {
            $oldest = array_key_first($this->readCache);
            $this->readCacheBytes -= strlen($this->readCache[$oldest]);
            unset($this->readCache[$oldest]);
        }
        $this->readCache[$key] = $data;
        $this->readCacheBytes += $bytes;
        return $data;
    }

    /** Validate archive references before touching the stream wrapper. */
    public function isValidFile(string &$file): bool
    {
        if (!str_starts_with($file, 'phar://')) {
            return parent::isValidFile($file);
        }
        // PHAR open can treat '?' as a query alias rather than part of an exact entry name.
        if (preg_match('/[\x00-\x1f\x7f?]/', $file) || $this->hasDoubleDots($file)) {
            return false;
        }
        $normalized = str_replace('\\', '/', $file);
        foreach ($this->pharRoots as $root) {
            if (str_starts_with($normalized, $root . '/') && is_file($normalized)) {
                $file = $normalized;
                return true;
            }
        }
        return false;
    }

    /** Preserve the exact archive entry already checked by isValidFile(), including trailing spaces. */
    protected function resolveValidatedPath(string $file): string
    {
        return str_starts_with($file, 'phar://') ? $file : parent::resolveValidatedPath($file);
    }

    /** Compare native paths without turning POSIX filename bytes into Windows separators. */
    protected function normalizePathForComparison(string $path): string
    {
        if ($path === '' || $path === '*' || str_contains($path, '://')) {
            return $path;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            return parent::normalizePathForComparison($path);
        }
        if (!str_starts_with($path, '/')) {
            $cwd = getcwd();
            if ($cwd === false) { return ''; }
            $path = rtrim($cwd, '/') . '/' . $path;
        }
        if (PHP_OS_FAMILY === 'Linux') {
            $path = '/' . ltrim($path, '/');
        }
        return $this->normalizeUnicode(Filesystem::normalizedAbsolute($path));
    }

    /** Unicode-equivalent names can be distinct files on Linux and Windows; retain upstream macOS handling. */
    protected function normalizeUnicode(string $str): string
    {
        return PHP_OS_FAMILY === 'Darwin' ? parent::normalizeUnicode($str) : $str;
    }

    /** PDF output is never allowed to write into the application's executable archive. */
    public function fopenLocal(string $file, string $mode): mixed
    {
        if (str_starts_with($file, 'phar://') && !in_array($mode, ['r', 'rb'], true)) {
            throw new FileException('PHAR PDF resources are read-only.');
        }
        return parent::fopenLocal($file, $mode);
    }

    /** Retain only explicit archive roots, without broadening native filesystem access. */
    private function setPharRoots(array $paths): void
    {
        $this->pharRoots = [];
        foreach ($paths as $path) {
            if (is_string($path) && str_starts_with($path, 'phar://') && !$this->hasDoubleDots($path)) {
                $this->pharRoots[] = rtrim(str_replace('\\', '/', $path), '/');
            }
        }
    }
}
