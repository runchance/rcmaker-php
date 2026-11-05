<?php

declare(strict_types=1);

namespace RC\Runtime;

use RC\Config;
use RC\Container;

/** Cold filesystem fallback for model()/M(); loaded classes remain on the existing Container::make() fast path. */
final class ModelFactory
{
    /** Load a validated application source path, e.g. model($request, 'Report') or an explicit model class. */
    public static function load(string $class, string $model, string $app, string $namespace, array $constructor): object
    {
        self::validateNamespace($namespace);
        self::validateNamespace($app);
        self::validateNamespace(ltrim($model, '\\'));
        $root = Config::get('app', 'apps_path') ?? BASE_PATH . '/apps';
        if (!is_string($root) || $root === '' || str_contains($root, "\0")) {
            throw new \InvalidArgumentException('Model apps_path must be a non-empty filesystem or PHAR path.');
        }
        if (PHP_OS_FAMILY === 'Windows' || stripos($root, 'phar://') === 0) { $root = str_replace('\\', '/', $root); }
        $directory = rtrim($root, '/') . '/' . str_replace('\\', '/', $app) . '/model/';
        if (!str_contains($model, '\\')) {
            $file = $directory . $model . '.php';
        } else {
            $relative = ltrim($class, '\\');
            $prefix = $namespace . '\\' . $app . '\\model\\';
            $legacy = $directory . str_replace('\\', '/', $relative) . '.php';
            if (strncasecmp($relative, $prefix, strlen($prefix)) === 0) {
                $canonical = $directory . str_replace('\\', '/', substr($relative, strlen($prefix))) . '.php';
                // Prefer a real conventional file; a wrong declaration must not fall through into another source file.
                $file = is_file($canonical) ? $canonical : $legacy;
            } else {
                $file = $legacy;
            }
        }
        if (!Container::loadClass($file, $class)) {
            throw new \Exception($file . ' can not be load!');
        }
        return Container::make($class, $constructor);
    }

    /** PHP identifier bytes exclude dots, stream syntax, NUL and empty segments before any fallback include. */
    private static function validateNamespace(string $name): void
    {
        foreach (explode('\\', $name) as $part) {
            if (preg_match('/\A[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*\z/D', $part) !== 1) {
                throw new \InvalidArgumentException('Model names must contain valid PHP namespace identifiers.');
            }
        }
    }
}
