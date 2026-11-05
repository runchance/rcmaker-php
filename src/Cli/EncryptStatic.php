<?php

declare(strict_types=1);

namespace RC\Cli;

use RC\Configuration\Files;
use RC\Configuration\Literal;
use RC\StaticFile\Cipher;
use RC\StaticFile\Registry;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/../StaticFile/Registry.php';

/** Protect static directories at rest. execute($root, $options) publishes assets and their generated key profile together. */
final class EncryptStatic
{
    public const CONFIG = 'static_assets_generated.php';

    /** List configured static roots using the framework's public_path(), not a guessed executable-relative path. */
    public static function applications(array $config): array
    {
        $applications = $config['app'] ?? [];
        $default = (string) ($config['default_app'] ?? 'index');
        $applications[$default] = array_replace($config, $applications[$default] ?? []);
        $result = [];
        foreach ($applications as $name => $application) {
            if (!is_array($application)) { continue; }
            $application = array_replace($config, $application);
            if (empty($application['enable_static_file'])) { continue; }
            $root = (string) ($application['document_root'] ?? '');
            $root = Filesystem::isAbsolute($root) ? $root : public_path() . '/' . $root;
            if (is_dir($root)) { $result[(string) $name] = Filesystem::resolvePath($root); }
        }
        return $result;
    }

    /** Read only CLI-owned literal data; executable developer configuration is never rewritten. */
    public static function generated(string $root): array
    {
        $path = Filesystem::fileTarget($root . '/config/' . self::CONFIG);
        if (!is_file($path)) { return ['profiles' => [], 'applications' => []]; }
        $source = file_get_contents($path);
        if (!is_string($source)) { throw new RuntimeException('Cannot read generated static encryption config.'); }
        Literal::assertSource($source);
        $settings = Files::load($path, true);
        if (!is_array($settings) || !is_array($settings['profiles'] ?? null) || !is_array($settings['applications'] ?? null)) {
            throw new RuntimeException('Generated static encryption config must contain profiles/applications arrays.');
        }
        return $settings;
    }

    /** Encrypt all/selected extensions, optionally rotate keys, and transactionally replace confirmed outputs. */
    public static function execute(string $root, array $options, ?callable $output = null): array
    {
        $root = Filesystem::resolvePath($root);
        if (trim((string) ($options['input'] ?? '')) === '') { throw new RuntimeException('Static input directory is required.'); }
        $rawInput = Filesystem::absolute((string) $options['input'], $root);
        if (Filesystem::isLinkPath($rawInput)) { throw new RuntimeException('Static input cannot be a directory link.'); }
        $input = OutputBatch::target($rawInput, true);
        if (!is_dir($input)) { throw new RuntimeException('Static input must be an existing directory.'); }
        $destination = OutputBatch::target(Filesystem::absolute((string) ($options['output'] ?? $input), $root), true);
        $same = Filesystem::pathsEqual($input, $destination);
        if (!$same && (Filesystem::pathContains($input, $destination) || Filesystem::pathContains($destination, $input))) {
            throw new RuntimeException('Static input/output directories must be equal or completely separate.');
        }
        if (Filesystem::pathContains($input, $root) || Filesystem::pathContains($input, $root . '/config')) {
            throw new RuntimeException('Select a static application directory, not a project/config ancestor.');
        }
        $force = (bool) ($options['force'] ?? false);
        if ($same && !$force) { throw new RuntimeException('In-place static encryption requires explicit overwrite confirmation.'); }
        $profileName = (string) ($options['profile'] ?? 'static_assets');
        if (!preg_match('/\A[A-Za-z0-9_.-]{1,64}\z/D', $profileName)) { throw new RuntimeException('Invalid static encryption profile name.'); }
        $application = (string) ($options['application'] ?? '');
        if ($application !== '' && preg_match('/[\x00-\x1f\x7f]/', $application)) { throw new RuntimeException('Invalid application name.'); }
        $configPath = Filesystem::fileTarget($root . '/config/' . self::CONFIG);
        $batch = new OutputBatch(); $warnings = [];
        try {
            $batch->guardProject($root); $batch->guardProject($input);
            $configStage = $batch->stage($configPath, false, true);
            $settings = self::generated($root);
            $manualPath = $root . '/config/static_encryption.php';
            $manual = is_file($manualPath) ? Files::load($manualPath) : [];
            if (!is_array($manual)) { throw new RuntimeException('Developer static encryption config must return an array.'); }
            if (isset($manual['profiles'][$profileName])) {
                throw new RuntimeException('This profile is developer-managed in static_encryption.php; choose a different CLI profile.');
            }
            if ($same && $application !== '') {
                $appPath = $root . '/config/app.php';
                $appConfig = is_file($appPath) ? Files::load($appPath) : [];
                $explicit = $appConfig['app'][$application]['static_encryption'] ?? null;
                if ($application === ($appConfig['default_app'] ?? 'index') && array_key_exists('static_encryption', $appConfig)) { $explicit ??= $appConfig['static_encryption']; }
                if ((array_key_exists($application, $manual['applications'] ?? []) && $manual['applications'][$application] !== $profileName)
                    || ($explicit !== null && $explicit !== $profileName)) {
                    throw new RuntimeException('The application has an explicit encryption override; update that developer configuration before automatic binding.');
                }
            }
            $previous = $settings['profiles'][$profileName] ?? null;
            if ($previous !== null && !is_array($previous)) { throw new RuntimeException('Invalid existing static encryption profile.'); }
            $profile = $previous ?? ['algorithm' => 'aes-256-gcm', 'key_id' => 'v1', 'keys' => ['v1' => Cipher::generateKey()], 'max_file_size' => Cipher::DEFAULT_LIMIT];
            $oldCipher = $previous === null ? null : new Cipher($previous);
            $extensions = Cipher::normalizeExtensions($options['extensions'] ?? $profile['extensions'] ?? '*');
            // A shared profile cannot change policy for only one of its directories: that strands other bindings.
            if ($previous !== null) {
                $oldExtensions = Cipher::normalizeExtensions($previous['extensions'] ?? '*');
                if (array_diff($oldExtensions, $extensions) || array_diff($extensions, $oldExtensions)) {
                    throw new RuntimeException('Cannot change an existing profile extension policy; use a new profile with original assets.');
                }
            }
            $profile['extensions'] = $extensions;
            $profile['algorithm'] = (string) ($options['algorithm'] ?? $profile['algorithm']);
            if (array_key_exists('max_file_size', $options)) { $profile['max_file_size'] = $options['max_file_size']; }
            if (!empty($options['rotate'])) {
                $profile['key_id'] = 'k' . bin2hex(random_bytes(6));
                $profile['keys'][$profile['key_id']] = Cipher::generateKey();
            }
            $cipher = new Cipher($profile);
            $inventory = self::inventory($input);
            $directoryStage = $same ? null : $batch->stage($destination, true, $force);
            if ($directoryStage !== null) { Filesystem::mkdir($directoryStage); }
            $counts = ['encrypted' => 0, 'copied' => 0];
            foreach ($inventory as $relative => $original) {
                $source = $input . '/' . $relative;
                $selected = $cipher->requiresEncryption($relative);
                $stream = fopen($source, 'rb');
                if ($stream === false) { throw new RuntimeException('Cannot inspect static source.'); }
                try { $prefix = fread($stream, strlen(Cipher::MAGIC)); } finally { fclose($stream); }
                $encrypted = is_string($prefix) && Cipher::isEncrypted($prefix);
                if ($same && !$selected && !$encrypted) { $counts['copied']++; continue; }
                $target = $same ? $batch->stage($source, false, true, false) : $directoryStage . '/' . $relative;
                Filesystem::mkdir(dirname($target));
                if ($selected || $encrypted) {
                    $bytes = $cipher->protectFile($source, $oldCipher);
                    if (file_put_contents($target, $bytes) !== strlen($bytes)) {
                        throw new RuntimeException('Cannot verify or prepare encrypted static asset.');
                    }
                    $counts['encrypted']++;
                } else {
                    if (!copy($source, $target)) { throw new RuntimeException('Cannot copy unselected static asset.'); }
                    $counts['copied']++;
                }
                if (!chmod($target, $original['mode'] & 0777) || !touch($target, $original['mtime'])) {
                    throw new RuntimeException('Cannot preserve static asset permissions/timestamp.');
                }
            }
            if ($counts['encrypted'] === 0) { throw new RuntimeException('No files matched the selected extensions.'); }
            if (self::inventory($input, $batch->generatedPaths()) !== $inventory) { throw new RuntimeException('Static source changed while encrypting; no outputs were published.'); }
            $settings['profiles'][$profileName] = $profile;
            if ($same && $application !== '') { $settings['applications'][$application] = $profileName; }
            $configSource = Literal::export($settings);
            if (file_put_contents($configStage, $configSource) !== strlen($configSource) || !chmod($configStage, 0600)) {
                throw new RuntimeException('Cannot prepare static encryption key config.');
            }
            $output && $output('Publish authenticated static assets and key configuration ...');
            $batch->commit();
            if (function_exists('opcache_invalidate')) { opcache_invalidate($configPath, true); }
        } finally { $warnings = $batch->close(); }
        foreach ($warnings as $warning) { $output && $output($warning); }
        Registry::reset(true);
        return ['output' => $destination, 'profile' => $profileName, 'config' => $configPath,
            'bound' => $same && $application !== '', 'algorithm' => $profile['algorithm']] + $counts;
    }

    /** Fingerprint a plain directory without following links; never include executable PHP source as static ciphertext. */
    private static function inventory(string $root, array $exclude = []): array
    {
        $result = [];
        $directory = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS);
        $filter = new \RecursiveCallbackFilterIterator($directory, static function (\SplFileInfo $file) use ($exclude): bool {
            foreach ($exclude as $path) { if (Filesystem::pathContains($path, $file->getPathname())) { return false; } }
            return true;
        });
        $iterator = new \RecursiveIteratorIterator($filter, \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (Filesystem::isLinkPath($path)) { throw new RuntimeException('Static encryption does not follow symbolic links/junctions.'); }
            if ($file->isDir()) { continue; }
            if (!$file->isFile()) { throw new RuntimeException('Static input contains a non-regular entry.'); }
            $extension = strtolower($file->getExtension());
            if (preg_match('/\A(?:php\d*|phtml|phar|sfx)\z/D', $extension) || $file->getFilename() === '.env') {
                throw new RuntimeException('Static directories must not contain PHP executables or .env files; use PHP source protection separately.');
            }
            $hash = hash_file('sha256', $path);
            if ($hash === false) { throw new RuntimeException('Cannot fingerprint static input.'); }
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root) + 1));
            $result[$relative] = ['sha256' => $hash, 'mode' => $file->getPerms(), 'mtime' => $file->getMTime()];
        }
        ksort($result); return $result;
    }
}
