<?php

declare(strict_types=1);

namespace RC\Project;

/** Structured Composer changes, preserving unrelated fields and object/array types. */
final class Manifest
{
    public const PACKAGE = 'runchance/rcmaker-php';
    public const LEGACY = 'runchance/rcmaker-framework';

    /** Parse a root composer.json without executing autoload files or scripts. */
    public static function decode(?string $json): \stdClass
    {
        $value = $json === null ? new \stdClass() : json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        if (!$value instanceof \stdClass) {
            throw new \RuntimeException('composer.json must contain a JSON object.');
        }
        if (($value->name ?? '') === self::PACKAGE) {
            throw new \RuntimeException('The root project cannot require itself.');
        }
        return $value;
    }

    /** Merge only the standard app/support namespaces; conflicting paths require manual review. */
    public static function autoload(\stdClass $manifest): void
    {
        $autoload = self::object($manifest, 'autoload');
        $map = self::object($autoload, 'psr-4');
        foreach (['app\\' => 'apps/', 'support\\' => 'support/'] as $prefix => $path) {
            if (isset($map->$prefix) && $map->$prefix !== $path && $map->$prefix !== [$path]) {
                throw new \RuntimeException('Existing PSR-4 mapping differs for ' . $prefix . '; configure it manually before initialization.');
            }
            $map->$prefix ??= $path;
        }
    }

    /** Return an object field, rejecting malformed manifests instead of overwriting user data. */
    public static function object(\stdClass $parent, string $name): \stdClass
    {
        if (!property_exists($parent, $name)) {
            $parent->$name = new \stdClass();
        }
        if (!$parent->$name instanceof \stdClass) {
            throw new \RuntimeException('composer.json field must be an object: ' . $name);
        }
        return $parent->$name;
    }

    /** Serialize a manifest with readable formatting and a final newline. */
    public static function encode(\stdClass $manifest): string
    {
        return json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    /** Resolve a conventional or explicitly configured relative vendor directory. */
    public static function vendorDirectory(\stdClass $manifest): string
    {
        $path = $manifest->config->{'vendor-dir'} ?? 'vendor';
        if (!is_string($path) || !preg_match('~\A[\w.-]+(?:/[\w.-]+)*\z~D', $path) || in_array('..', explode('/', $path), true)) {
            throw new \RuntimeException('Initialization requires a relative vendor-dir inside the project.');
        }
        return $path;
    }
}
