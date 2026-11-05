<?php

declare(strict_types=1);

namespace RC\StaticFile;

use RC\Config;
use RuntimeException;

require_once __DIR__ . '/Cipher.php';

/** Resolve developer profiles and CLI-managed profiles once per process, never per-request key derivation. */
final class Registry
{
    private static ?array $settings = null;
    private static array $ciphers = [];
    private static array $buildBindings = [];

    /** Developer config overrides standalone generated profiles; PHAR-only build selections take final precedence. */
    public static function settings(): array
    {
        if (self::$settings !== null) { return self::$settings; }
        $generated = Config::get('static_assets_generated') ?? [];
        $manual = Config::get('static_encryption') ?? [];
        foreach ([$generated, $manual] as $settings) {
            if (!is_array($settings) || !is_array($settings['profiles'] ?? []) || !is_array($settings['applications'] ?? [])) {
                throw new RuntimeException('Static encryption config requires profiles/applications arrays.');
            }
        }
        $build = [];
        if (class_exists(\Phar::class, false) && \Phar::running(false) !== '') {
            $build = Config::get('static_assets_build') ?? [];
            if (!is_array($build) || ($build !== [] && (($build['version'] ?? null) !== 1 || !is_array($build['profiles'] ?? null) || !is_array($build['applications'] ?? null)))) {
                throw new RuntimeException('Invalid packaged static encryption catalog.');
            }
        }
        self::$buildBindings = $build['applications'] ?? [];
        return self::$settings = ['profiles' => array_replace($generated['profiles'] ?? [], $manual['profiles'] ?? [], $build['profiles'] ?? []),
            'applications' => array_replace($generated['applications'] ?? [], $manual['applications'] ?? [])];
    }

    /** Apply CLI application bindings without rewriting the developer's app.php expressions or paths. */
    public static function apply(array $config): array
    {
        $bindings = self::settings()['applications'];
        if ($bindings === [] && self::$buildBindings === []) { return $config; }
        $default = $config['default_app'] ?? 'index';
        $defaultBinding = !array_key_exists('static_encryption', $config)
            && isset($bindings[$default]) && !array_key_exists('static_encryption', is_array($config['app'][$default] ?? null) ? $config['app'][$default] : []);
        if ($defaultBinding) { $config['static_encryption'] = $bindings[$default]; }
        foreach ($config['app'] ?? [] as $name => $app) {
            if (!is_array($app) || array_key_exists('static_encryption', $app)) { continue; }
            if (isset($bindings[$name])) { $config['app'][$name]['static_encryption'] = $bindings[$name]; }
            elseif ($defaultBinding && $name !== $default) { $config['app'][$name]['static_encryption'] = false; }
        }
        if (isset(self::$buildBindings[$default])) {
            $inherited = $config['static_encryption'] ?? false;
            foreach ($config['app'] ?? [] as $name => $app) {
                if (is_array($app) && !array_key_exists('static_encryption', $app)) { $config['app'][$name]['static_encryption'] = $inherited; }
            }
            $config['static_encryption'] = self::$buildBindings[$default];
        }
        foreach (self::$buildBindings as $name => $profile) {
            if (!is_string($profile)) { throw new RuntimeException('Packaged static bindings must contain profile names.'); }
            if (isset($config['app'][$name]) && is_array($config['app'][$name])) { $config['app'][$name]['static_encryption'] = $profile; }
        }
        return $config;
    }

    /** Return no cipher for existing plaintext apps; explicit profile names are validated when actually used. */
    public static function forConfig(array $config): ?Cipher
    {
        $profile = $config['static_encryption'] ?? null;
        if ($profile === null || $profile === false || $profile === '') { return null; }
        if (!is_string($profile)) { throw new RuntimeException('static_encryption must be a profile name or false.'); }
        if (isset(self::$ciphers[$profile])) { return self::$ciphers[$profile]; }
        $settings = self::settings();
        if (!is_array($settings['profiles'][$profile] ?? null)) { throw new RuntimeException('Static encryption profile is not configured: ' . $profile); }
        return self::$ciphers[$profile] = new Cipher($settings['profiles'][$profile]);
    }

    /** Build-only protection skips PHP; keep the original enable_static_php decision rather than encrypting/executing it as an asset. */
    public static function staticPhpPassthrough(array $config): bool
    {
        $profile = $config['static_encryption'] ?? null;
        return is_string($profile) && (self::settings()['profiles'][$profile]['static_php_passthrough'] ?? false) === true;
    }

    /** Refresh before static reload/pre-fork or after a successful CLI publication, not in the HTTP hot path. */
    public static function reset(bool $reload = false): void
    {
        self::$settings = null; self::$ciphers = []; self::$buildBindings = [];
        if ($reload) {
            Config::get('static_assets_generated', null, true); Config::get('static_encryption', null, true);
            if (class_exists(\Phar::class, false) && \Phar::running(false) !== '') { Config::get('static_assets_build', null, true); }
        }
    }
}
