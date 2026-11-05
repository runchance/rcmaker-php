<?php

declare(strict_types=1);

namespace RC\Cli;

use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

require_once __DIR__ . '/ArtifactDownload.php';
require_once __DIR__ . '/ArtifactBinary.php';

final class ArtifactRepository
{
    public const BASE_URL = 'https://rcmaker.runchance.com/download';
    public const DEFAULT_PHP_VERSION = '8.5';

    private $output;
    private string $baseUrl;
    private static array $activeLocks = [];

    /** Default to the official repository; an explicit HTTPS mirror is a developer-owned trust decision. */
    public function __construct(?callable $output = null, ?string $baseUrl = null)
    {
        $this->output = $output;
        $this->baseUrl = rtrim($baseUrl ?? self::BASE_URL, '/');
        ArtifactDownload::validateUrl($this->baseUrl);
        if (isset(parse_url($this->baseUrl)['query'])) {
            throw new InvalidArgumentException('Artifact repository base URL cannot contain a query.');
        }
    }

    public static function phpVersions(): array
    {
        return [self::DEFAULT_PHP_VERSION];
    }

    public static function normalizePhpVersion(string $version): string
    {
        $version = trim($version);
        if (!in_array($version, self::phpVersions(), true)) {
            throw new InvalidArgumentException('No published PHP artifact for version: ' . $version . '; available series: ' . implode(', ', self::phpVersions()));
        }
        return $version;
    }

    public static function currentPlatform(): string
    {
        return match (PHP_OS_FAMILY) {
            'Linux' => 'linux',
            'Darwin' => 'macos',
            'Windows' => 'windows',
            default => throw new RuntimeException('Unsupported host platform: ' . PHP_OS_FAMILY),
        };
    }

    public static function normalizePlatform(string $platform): string
    {
        $platform = strtolower(trim($platform));
        if ($platform === '' || $platform === 'auto') {
            return self::currentPlatform();
        }
        $platform = match ($platform) {
            'darwin', 'osx', 'mac', 'macosx' => 'macos',
            'win', 'win32', 'win64' => 'windows',
            default => $platform,
        };
        if (!in_array($platform, ['linux', 'macos', 'windows'], true)) {
            throw new InvalidArgumentException('Unsupported platform: ' . $platform);
        }
        return $platform;
    }

    public static function normalizeArch(string $arch): string
    {
        $arch = strtolower(trim($arch));
        if ($arch === '' || $arch === 'auto') {
            $arch = strtolower((string)php_uname('m'));
        }
        $arch = match ($arch) {
            'amd64', 'x64', 'x86-64' => 'x86_64',
            'arm64' => 'aarch64',
            default => $arch,
        };
        if (!in_array($arch, ['x86_64', 'aarch64'], true)) {
            throw new InvalidArgumentException('Unsupported architecture: ' . $arch);
        }
        return $arch;
    }

    public static function assertTarget(string $platform, string $arch): void
    {
        if (!in_array($platform, ['linux', 'macos', 'windows'], true) || !in_array($arch, ['x86_64', 'aarch64'], true)) {
            throw new InvalidArgumentException('Unsupported artifact platform or architecture.');
        }
        if ($platform === 'windows' && $arch !== 'x86_64') {
            throw new InvalidArgumentException('Windows artifacts currently support x86_64 only.');
        }
    }

    public static function runtimeArchive(string $version, string $platform, string $arch): string
    {
        [$version, $platform, $arch] = self::target($version, $platform, $arch);
        return "php{$version}-{$platform}-{$arch}.zip";
    }

    public static function microArchive(string $version, string $platform, string $arch): string
    {
        [$version, $platform, $arch] = self::target($version, $platform, $arch);
        return "php{$version}-micro-{$platform}-{$arch}.zip";
    }

    public static function beastArchive(string $platform, string $arch): string
    {
        [, $platform, $arch] = self::target(self::DEFAULT_PHP_VERSION, $platform, $arch);
        return "rcmakerbeast-{$platform}-{$arch}.zip";
    }

    public static function runtimeEntry(string $platform): string
    {
        $platform = self::normalizePlatform($platform);
        return $platform === 'windows' ? 'php.exe' : 'php';
    }

    public static function beastEntry(string $platform): string
    {
        $platform = self::normalizePlatform($platform);
        return $platform === 'windows' ? 'rcmakerbeast.exe' : 'rcmakerbeast';
    }

    /** Reuse identity/hash-checked cache, or safely obtain one artifact; sha256 pins the ZIP, not the entry. */
    public function ensure(string $archiveName, string $expectedEntry, string $targetPath, ?string $sha256 = null, bool $refresh = false): string
    {
        $identity = self::identity($archiveName, $expectedEntry);
        $sha256 = ArtifactDownload::digest($sha256);
        $targetPath = Filesystem::fileTarget($targetPath);
        [$metadataPath, $lockPath] = self::cacheSidecars($targetPath);
        $metadataPath = Filesystem::fileTarget($metadataPath);
        $url = $this->baseUrl . '/' . rawurlencode($archiveName);
        $lock = self::lock($lockPath);
        $archivePath = $prepared = null;
        try {
            if (!$refresh && is_file($targetPath)) {
                if (is_file($metadataPath)) {
                    if ($this->cacheMatches($targetPath, $metadataPath, $url, $archiveName, $expectedEntry, $identity, $sha256)) {
                        self::executable($targetPath, $identity['platform']);
                        $this->emit('Use checked cache ' . basename($targetPath) . ' ...');
                        return $targetPath;
                    }
                    $this->emit('Artifact cache identity/hash mismatch; refresh from repository ...');
                } elseif ($sha256 === null) {
                    ArtifactBinary::assertTarget($targetPath, $identity['platform'], $identity['arch']);
                    self::executable($targetPath, $identity['platform']);
                    $metadata = self::record($targetPath, $url, $archiveName, $expectedEntry, null, 'local-existing');
                    self::writeMetadata($metadataPath, $metadata);
                    $this->emit('Use local artifact ' . basename($targetPath) . ' (format/CPU checked; origin and PHP version not authenticated).');
                    return $targetPath;
                }
            }
            $archivePath = tempnam(sys_get_temp_dir(), 'rcmaker-artifact-');
            if ($archivePath === false) { throw new RuntimeException('Cannot create temporary artifact archive.'); }
            $this->emit('Downloading ' . $url . ' ...');
            $this->downloadFile($url, $archivePath, $sha256 === null ? [] : ['sha256' => $sha256]);
            $archiveHash = hash_file('sha256', $archivePath);
            $prepared = $this->extractSingleFile($archivePath, $expectedEntry, $targetPath, $identity);
            // Publish metadata first: interruption can only cause a cache miss, never bless old/wrong bytes.
            self::writeMetadata($metadataPath, self::record($prepared, $url, $archiveName, $expectedEntry, $archiveHash, 'https'));
            Filesystem::publishFile($prepared, $targetPath); $prepared = null;
            return $targetPath;
        } finally {
            if (is_string($prepared) && is_file($prepared)) { @unlink($prepared); }
            if (is_string($archivePath) && is_file($archivePath)) { @unlink($archivePath); }
            flock($lock, LOCK_UN); fclose($lock); unset(self::$activeLocks[$lockPath]);
        }
    }

    /**
     * Download an artifact to a local file using cURL or PHP streams.
     */
    public function downloadFile(string $url, string $targetPath, array $options = []): void
    {
        ArtifactDownload::run($url, $targetPath, $options);
    }

    /** Generated sidecars let collectors exclude exactly the selected runtime's metadata and lock. */
    public static function cacheSidecars(string $targetPath): array
    {
        $targetPath = Filesystem::fileTarget($targetPath);
        $name = PHP_OS_FAMILY === 'Windows' ? strtolower(basename($targetPath)) : basename($targetPath);
        return [$targetPath . '.rcmaker-artifact.json', dirname($targetPath) . '/.rcmaker-artifact-' . substr(hash('sha256', $name), 0, 24) . '.lock'];
    }

    /** Stage exactly one ordinary entry, checking declared size, streamed CRC and executable identity. */
    private function extractSingleFile(string $archivePath, string $expectedEntry, string $targetPath, array $identity): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException("The host PHP must have the 'zip' extension enabled.");
        }
        $zip = new ZipArchive();
        if (!is_file($archivePath) || filesize($archivePath) < 1 || filesize($archivePath) > ArtifactDownload::MAX_BYTES) {
            throw new RuntimeException('Artifact ZIP has invalid size.');
        }
        $result = @$zip->open($archivePath, ZipArchive::RDONLY | ZipArchive::CHECKCONS);
        if ($result !== true) {
            throw new RuntimeException("Failed to open artifact archive (ZipArchive code {$result}).");
        }
        try {
            if ($zip->numFiles !== 1) {
                throw new RuntimeException('Artifact archive must contain exactly one file.');
            }
            $entry = $zip->getNameIndex(0);
            if ($entry !== $expectedEntry) {
                throw new RuntimeException("Artifact entry mismatch: expected {$expectedEntry}, found " . (string)$entry);
            }
            $stat = $zip->statIndex(0);
            $size = $stat['size'] ?? 0;
            if (!is_int($size) || $size < 64 || $size > ArtifactBinary::MAX_BYTES || ($stat['encryption_method'] ?? 0) !== 0) {
                throw new RuntimeException('Artifact entry has invalid size or encryption.');
            }
            $system = $attributes = 0;
            if ($zip->getExternalAttributesIndex(0, $system, $attributes) && !in_array(($attributes >> 16) & 0170000, [0, 0100000], true)) {
                throw new RuntimeException('Artifact ZIP entry must be an ordinary file.');
            }
            $input = @$zip->getStream($expectedEntry);
            if (!is_resource($input)) {
                throw new RuntimeException('Failed to read artifact entry: ' . $expectedEntry);
            }
            $temporary = null; $output = null;
            try {
                [$temporary, $output] = Filesystem::temporaryFile($targetPath, 'rcmaker-extract');
                $crc = hash_init('crc32b'); $copied = 0;
                while (!feof($input)) {
                    $chunk = @fread($input, 65536);
                    if ($chunk === false || ($chunk === '' && !feof($input))) { throw new RuntimeException('Cannot read artifact ZIP entry.'); }
                    if (strlen($chunk) > $size - $copied) { throw new RuntimeException('Artifact ZIP entry exceeds its declared size.'); }
                    Filesystem::writeToStream($output, $chunk); hash_update($crc, $chunk); $copied += strlen($chunk);
                }
                if ($copied !== $size || !hash_equals(substr(sprintf('%08x', $stat['crc']), -8), hash_final($crc))) { throw new RuntimeException('Artifact ZIP entry size/CRC mismatch.'); }
                if (!fflush($output)) { throw new RuntimeException('Cannot flush extracted artifact.'); }
                fclose($output); $output = null;
                ArtifactBinary::assertTarget($temporary, $identity['platform'], $identity['arch']);
                if (!@chmod($temporary, $identity['platform'] === 'windows' ? 0644 : 0755)) { throw new RuntimeException('Cannot set executable artifact permissions.'); }
                return $temporary;
            } catch (\Throwable $error) {
                if (is_resource($output)) { fclose($output); $output = null; }
                if (is_string($temporary) && is_file($temporary)) { @unlink($temporary); }
                throw $error;
            } finally { fclose($input); if (is_resource($output)) { fclose($output); } }
        } finally { $zip->close(); }
    }

    /** Validate public artifact selectors consistently; armv8l is a 32-bit ABI, not AArch64. */
    private static function target(string $version, string $platform, string $arch): array
    {
        $version = self::normalizePhpVersion($version); $platform = self::normalizePlatform($platform); $arch = self::normalizeArch($arch);
        self::assertTarget($platform, $arch);
        return [$version, $platform, $arch];
    }

    /** Bind names to the actual published layout, never an arbitrary ZIP entry or URL path. */
    private static function identity(string $archive, string $entry): array
    {
        if (preg_match('/\Aphp([0-9]+\.[0-9]+)(-micro)?-(linux|macos|windows)-(x86_64|aarch64)\.zip\z/D', $archive, $match)) {
            self::normalizePhpVersion($match[1]);
            $platform = $match[3]; $arch = $match[4];
            $expected = $match[2] === '-micro' ? 'micro.sfx' : self::runtimeEntry($platform);
        } elseif (preg_match('/\Arcmakerbeast-(linux|macos|windows)-(x86_64|aarch64)\.zip\z/D', $archive, $match)) {
            $platform = $match[1]; $arch = $match[2]; $expected = self::beastEntry($platform);
        } else { throw new InvalidArgumentException('Unsupported artifact archive name.'); }
        self::assertTarget($platform, $arch);
        if ($entry !== $expected) { throw new InvalidArgumentException('Artifact archive and entry do not match.'); }
        return ['platform' => $platform, 'arch' => $arch];
    }

    /** Legacy copies may lose their owner execute bit; do not broaden an existing cache's other permissions. */
    private static function executable(string $path, string $platform): void
    {
        if (PHP_OS_FAMILY !== 'Windows' && $platform !== 'windows' && !is_executable($path)) {
            if (!@chmod($path, (fileperms($path) & 0777) | 0100) || !is_executable($path)) {
                throw new RuntimeException('Cannot enable execution of the local artifact.');
            }
        }
    }

    /** A stable, bounded cooperative lock prevents two ensures from publishing mismatched cache metadata. */
    private static function lock(string $path)
    {
        if (isset(self::$activeLocks[$path])) { throw new RuntimeException('Recursive artifact cache operation.'); }
        $path = Filesystem::fileTarget($path); Filesystem::mkdir(dirname($path));
        $lock = @fopen($path, 'x+b');
        if (!is_resource($lock)) { $lock = @fopen($path, 'r+b'); }
        if (!is_resource($lock)) { throw new RuntimeException('Cannot open artifact cache lock.'); }
        $deadline = hrtime(true) / 1e9 + 10;
        try {
            while (!flock($lock, LOCK_EX | LOCK_NB)) {
                if (hrtime(true) / 1e9 >= $deadline) { throw new RuntimeException('Artifact cache is busy; retry after the other operation finishes.'); }
                usleep(50000);
            }
            Filesystem::fileTarget($path);
            $opened = fstat($lock); $current = lstat($path);
            if ($opened === false || $current === false || $opened['ino'] !== $current['ino'] || $opened['dev'] !== $current['dev']) { throw new RuntimeException('Artifact lock changed during open.'); }
            self::$activeLocks[$path] = true;
            return $lock;
        } catch (\Throwable $error) { fclose($lock); throw $error; }
    }

    /** Cache metadata detects accidental substitution/corruption; it is not a vendor signature. */
    private static function record(string $path, string $url, string $archive, string $entry, ?string $archiveHash, string $source): array
    {
        $hash = hash_file('sha256', $path); $size = filesize($path);
        if (!is_string($hash) || !is_int($size)) { throw new RuntimeException('Cannot fingerprint artifact cache.'); }
        return ['schema' => 1, 'url' => $url, 'archive' => $archive, 'entry' => $entry, 'archive_sha256' => $archiveHash,
            'entry_sha256' => $hash, 'entry_bytes' => $size, 'source' => $source];
    }

    /** Require both cache identity and actual bytes; a bad stamp cannot fall back to legacy adoption. */
    private function cacheMatches(string $path, string $metadataPath, string $url, string $archive, string $entry, array $identity, ?string $pin): bool
    {
        try {
            $json = @file_get_contents($metadataPath, false, null, 0, 8193);
            if (!is_string($json) || strlen($json) > 8192) { return false; }
            $metadata = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($metadata) || ($metadata['schema'] ?? null) !== 1 || ($metadata['url'] ?? null) !== $url
                || ($metadata['archive'] ?? null) !== $archive || ($metadata['entry'] ?? null) !== $entry
                || !in_array($metadata['source'] ?? null, ['https', 'local-existing'], true)
                || ($pin !== null && ($metadata['archive_sha256'] ?? null) !== $pin)) { return false; }
            ArtifactBinary::assertTarget($path, $identity['platform'], $identity['arch']);
            return ($metadata['entry_bytes'] ?? null) === filesize($path) && is_string($metadata['entry_sha256'] ?? null)
                && hash_equals($metadata['entry_sha256'], hash_file('sha256', $path));
        } catch (\Throwable) { return false; }
    }

    /** A write-ahead stamp is safe: until the binary rename succeeds the hash check only yields a miss. */
    private static function writeMetadata(string $path, array $metadata): void
    {
        [$temporary, $output] = Filesystem::temporaryFile($path, 'rcmaker-provenance');
        try {
            Filesystem::writeToStream($output, json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
            if (!fflush($output)) { throw new RuntimeException('Cannot flush artifact metadata.'); }
            fclose($output); $output = null;
            Filesystem::publishFile($temporary, $path);
        } finally {
            if (is_resource($output)) { fclose($output); }
            if (is_file($temporary)) { @unlink($temporary); }
        }
    }

    private function emit(string $message): void
    {
        if ($this->output !== null) {
            ($this->output)($message);
        }
    }
}
