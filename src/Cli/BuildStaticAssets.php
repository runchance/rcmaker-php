<?php

declare(strict_types=1);

namespace RC\Cli;

use RC\Configuration\Literal;
use RC\StaticFile\Cipher;
use RC\StaticFile\Registry;
use RuntimeException;

require_once __DIR__ . '/EncryptStatic.php';

/** Prepare static protection only in a private binary-build tree; never publish changes to project sources. */
final class BuildStaticAssets
{
    public const CONFIG = 'static_assets_build.php';

    /** Resolve configured application roots through public_path(), retaining logical paths for the package collector. */
    public static function applications(string $root, #[\SensitiveParameter] array $config): array
    {
        $config = Registry::apply($config);
        $applications = $config['app'] ?? [];
        $default = (string) ($config['default_app'] ?? 'index');
        $applications[$default] = array_replace($config, $applications[$default] ?? []);
        $result = [];
        foreach ($applications as $name => $application) {
            if (!is_array($application)) { continue; }
            $application = array_replace($config, $application);
            if (empty($application['enable_static_file'])) { continue; }
            $directory = (string) ($application['document_root'] ?? '');
            $directory = Filesystem::isAbsolute($directory) ? $directory : public_path() . '/' . $directory;
            if (!is_dir($directory)) { continue; }
            $result[(string) $name] = ['directory' => Filesystem::normalizedAbsolute($directory, $root),
                'source_cipher' => Registry::forConfig($application)];
        }
        return $result;
    }

    /** Encrypt selected collected assets, preserving PHP entry files and source profiles; return non-secret statistics. */
    public static function prepare(string $root, string $staged, #[\SensitiveParameter] array $selection, ?callable $output = null): array
    {
        $root = Filesystem::resolvePath($root);
        $staged = Filesystem::resolvePath($staged);
        if (Filesystem::pathContains($staged, $root)) { throw new RuntimeException('Static build staging must not contain project sources.'); }
        $available = $selection['applications'] ?? [];
        if (!is_array($available)) { throw new RuntimeException('Static build applications must be an array.'); }
        $selected = $selection['selected'] ?? array_keys($available);
        if (!is_array($selected) || $selected === []) { throw new RuntimeException('Select at least one static application for binary protection.'); }
        $selected = array_values(array_unique($selected));
        $roots = [];
        foreach ($available as $name => $application) {
            if (!is_array($application)) { throw new RuntimeException('Static build application definitions must be arrays.'); }
            $directory = Filesystem::normalizedAbsolute((string) ($application['directory'] ?? ''), $root);
            $chosen = in_array($name, $selected, true) || in_array((string) $name, $selected, true);
            if ($chosen && (!is_dir($directory) || !Filesystem::pathContains($root, $directory) || Filesystem::pathsEqual($root, $directory)
                || !Filesystem::pathsEqual($directory, Filesystem::resolvePath($directory)))) {
                throw new RuntimeException('Selected static roots must be ordinary directories inside the project; external roots/aliases cannot be embedded automatically.');
            }
            $relative = Filesystem::pathContains($root, $directory) ? substr($directory, strlen(rtrim($root, '/')) + 1) : null;
            $roots[(string) $name] = ['directory' => $directory, 'relative' => $relative, 'selected' => $chosen,
                'source_cipher' => $application['source_cipher'] ?? null];
        }
        foreach ($selected as $name) { if (!isset($roots[$name])) { throw new RuntimeException('Unknown static build application.'); } }
        foreach ($roots as $first) {
            foreach ($roots as $second) {
                if ($first['selected'] !== $second['selected'] && (Filesystem::pathContains($first['directory'], $second['directory'])
                    || Filesystem::pathContains($second['directory'], $first['directory']))) {
                    throw new RuntimeException('Overlapping static roots must be selected together; choose all or isolate their document roots.');
                }
            }
        }
        uasort($roots, static fn(array $a, array $b): int => strlen($b['directory']) <=> strlen($a['directory']));
        $profileName = 'binary_' . bin2hex(random_bytes(12));
        $profile = ['algorithm' => $selection['algorithm'] ?? 'aes-256-gcm', 'key_id' => 'v1', 'keys' => ['v1' => Cipher::generateKey()],
            'extensions' => Cipher::normalizeExtensions($selection['extensions'] ?? '*'), 'max_file_size' => $selection['max_file_size'] ?? Cipher::DEFAULT_LIMIT,
            'static_php_passthrough' => true];
        $cipher = new Cipher($profile);
        $seen = []; $encrypted = 0; $bindings = [];
        foreach ($roots as $name => $application) {
            if (!$application['selected']) { continue; }
            $directory = Filesystem::resolvePath($staged . '/' . $application['relative']);
            if (!Filesystem::pathContains($staged, $directory) || !is_dir($directory)) { throw new RuntimeException('Selected static application is missing or excluded from the build.'); }
            $bindings[$name] = $profileName;
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                $path = $file->getPathname();
                if (!$file->isFile() || Filesystem::isLinkPath($path)) { throw new RuntimeException('Static build input must contain ordinary files.'); }
                $key = Filesystem::normalizedAbsolute($path);
                if (PHP_OS_FAMILY === 'Windows') { $key = strtolower($key); }
                if (isset($seen[$key])) { continue; }
                $seen[$key] = true;
                if (preg_match('/\A(?:php\d*|phtml|phar|sfx)\z/iD', $file->getExtension())) { continue; }
                $prefix = file_get_contents($path, false, null, 0, strlen(Cipher::MAGIC));
                if (!$cipher->requiresEncryption($path) && (!is_string($prefix) || !Cipher::isEncrypted($prefix))) { continue; }
                $bytes = $cipher->protectFile($path, $application['source_cipher']);
                $mode = $file->getPerms(); $mtime = $file->getMTime();
                if (file_put_contents($path, $bytes) !== strlen($bytes) || !chmod($path, $mode & 0777) || !touch($path, $mtime)) {
                    throw new RuntimeException('Cannot prepare protected static build asset.');
                }
                $encrypted++;
            }
        }
        $catalog = ['version' => 1, 'profiles' => [$profileName => $profile], 'applications' => $bindings];
        Filesystem::mkdir($staged . '/config');
        $path = $staged . '/config/' . self::CONFIG; $source = Literal::export($catalog);
        if (file_put_contents($path, $source) !== strlen($source) || !chmod($path, 0600)) { throw new RuntimeException('Cannot prepare packaged static key configuration.'); }
        $output && $output('Protected ' . $encrypted . ' staged static asset(s); original project files unchanged.');
        return ['applications' => array_keys($bindings), 'files' => $encrypted, 'algorithm' => $profile['algorithm'], 'extensions' => $profile['extensions']];
    }
}
