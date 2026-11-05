<?php

declare(strict_types=1);

namespace RC\Project;

/** Creates application-owned files from the package's single template source. */
final class Initializer
{
    /** Build a preview; no writes occur and existing application files are kept byte-for-byte. */
    public function plan(ProjectFiles $files): array
    {
        $before = $files->read('composer.json');
        $manifest = Manifest::decode($before);
        if (isset($manifest->require->{Manifest::LEGACY}) || isset($manifest->{'require-dev'}->{Manifest::LEGACY})) {
            throw new \RuntimeException('Legacy framework dependency detected; run migrate --dry-run first.');
        }
        $require = Manifest::object($manifest, 'require');
        $alreadyRequired = isset($require->{Manifest::PACKAGE});
        if (!$alreadyRequired) {
            $require->php ??= Platform::PHP_CONSTRAINT;
            $require->{Manifest::PACKAGE} = '*@stable';
            $manifest->{'minimum-stability'} ??= 'stable';
            $manifest->{'prefer-stable'} ??= true;
        }
        Manifest::autoload($manifest);
        $vendor = Manifest::vendorDirectory($manifest);
        $changes = [];
        $kept = [];
        $templateRoot = dirname(__DIR__, 2) . '/resources/project';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($templateRoot, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if (!$entry->isFile() || $entry->isLink()) {
                throw new \RuntimeException('Invalid project template.');
            }
            $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($templateRoot) + 1));
            if ($relative === 'windows.bat' && PHP_OS_FAMILY !== 'Windows') {
                continue;
            }
            $path = $files->path($relative);
            if (file_exists($path)) {
                if (!is_file($path)) {
                    throw new \RuntimeException('Template target is not a file: ' . $relative);
                }
                $kept[] = $relative;
                continue;
            }
            $contents = file_get_contents($entry->getPathname());
            if ($contents === false) {
                throw new \RuntimeException('Cannot read project template: ' . $relative);
            }
            if ($relative === 'index.php') {
                $contents = str_replace('{{VENDOR_AUTOLOAD}}', $vendor . '/autoload.php', $contents);
            } elseif ($relative === 'windows.bat') {
                $contents = str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $contents));
            }
            $changes[$relative] = ['before' => null, 'after' => $contents];
        }
        // Do not read an existing .env: its contents are private and initialization preserves it.
        $env = $files->path('.env');
        if (!file_exists($env)) {
            $changes['.env'] = ['before' => null, 'after' => file_get_contents($templateRoot . '/.env.example')];
        } elseif (!is_file($env)) {
            throw new \RuntimeException('.env must be a regular file.');
        } else {
            $kept[] = '.env';
        }
        $after = Manifest::encode($manifest);
        // Avoid rewriting composer.json merely to reformat it on a repeated init.
        if ($before === null || json_decode($before) != $manifest) {
            $changes['composer.json'] = ['before' => $before, 'after' => $after];
        }
        ksort($changes);
        sort($kept);
        return ['changes' => $changes, 'kept' => $kept, 'manifest' => $manifest];
    }

    /** Decide whether automatic first-install initialization can safely be offered. */
    public function isFresh(ProjectFiles $files): bool
    {
        foreach (['index.php', 'windows.php', '.env', 'apps', 'config', 'support', 'public'] as $path) {
            if (file_exists($files->path($path))) {
                return false;
            }
        }
        return true;
    }
}
