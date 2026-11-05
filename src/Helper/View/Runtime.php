<?php

declare(strict_types=1);

namespace RC\Helper\View;

use RC\Config;

/** Common boundaries for trusted application templates, not an untrusted-code sandbox. */
final class Runtime
{
    /** Accept nested relative names and literal zero, not resource URLs or traversal components. */
    public static function name($name): string
    {
        if (is_int($name)) { $name = (string) $name; }
        if (!is_string($name) || $name === '' || preg_match('/[\x00-\x1f\x7f:]/', $name)
            || str_starts_with($name, '/') || str_starts_with($name, '\\')) {
            throw new \InvalidArgumentException('View names must be nonempty relative template names.');
        }
        $name = str_replace('\\', '/', $name);
        foreach (explode('/', $name) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new \InvalidArgumentException('View names cannot contain empty, dot or traversal components.');
            }
        }
        return $name;
    }

    /** A null direct-driver application retains the default; application IDs remain one directory component. */
    public static function app($app): string
    {
        $app ??= 'default';
        if (is_int($app)) { $app = (string) $app; }
        if (!is_string($app) || $app === '' || $app === '.' || $app === '..' || preg_match('/[\x00-\x1f\x7f:\/\\\\]/', $app)) {
            throw new \InvalidArgumentException('A view application must be one directory name.');
        }
        return $app;
    }

    /** Keep the HTML default without caching a stale first-render suffix. */
    public static function suffix(): string
    {
        $suffix = Config::get('view', 'suffix');
        if ($suffix === null || $suffix === false) { return 'html'; }
        if (!is_string($suffix) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D', $suffix) || str_contains($suffix, '..')) {
            throw new \InvalidArgumentException('View suffix must be a safe nonempty extension without a leading dot.');
        }
        return $suffix;
    }

    /** Missing configuration means empty options; malformed configured values fail explicitly. */
    public static function options(): array
    {
        $options = Config::get('view', 'options');
        if ($options === null || $options === false) { return []; }
        if (!is_array($options)) { throw new \InvalidArgumentException('View options must be an array.'); }
        return $options;
    }

    /** Use the existing view_path() API, retaining PHAR input support. */
    public static function directory(string $app): string
    {
        $root = \view_path();
        if (!is_string($root) || $root === '' || str_contains($root, "\0")
            || (str_contains($root, '://') && !str_starts_with($root, 'phar://'))) {
            throw new \InvalidArgumentException('View roots must use the local filesystem or PHAR.');
        }
        if (PHP_OS_FAMILY === 'Windows') { $root = str_replace('\\', '/', $root); }
        return rtrim($root, PHP_OS_FAMILY === 'Windows' ? '/\\' : '/') . '/' . $app;
    }

    /** Resolve a requested file without letting a child link escape the selected local root. */
    public static function file(string $root, string $name): string
    {
        if ($root === '' || str_contains($root, "\0") || (str_contains($root, '://') && !str_starts_with($root, 'phar://'))) {
            throw new \InvalidArgumentException('View roots must use the local filesystem or PHAR.');
        }
        if (PHP_OS_FAMILY === 'Windows') { $root = str_replace('\\', '/', $root); }
        $path = rtrim($root, PHP_OS_FAMILY === 'Windows' ? '/\\' : '/') . '/' . $name;
        if (!is_file($path)) { throw new \RuntimeException('View template not found: ' . $path); }
        if (str_starts_with($root, 'phar://')) { return $path; }
        $parent = realpath($root); $resolved = realpath($path);
        if ($parent === false || $resolved === false) { throw new \RuntimeException('Cannot resolve view template.'); }
        $child = $resolved;
        if (PHP_OS_FAMILY === 'Windows') {
            $parent = strtolower(str_replace('\\', '/', $parent));
            $child = strtolower(str_replace('\\', '/', $child));
        }
        if (!str_starts_with($child, rtrim($parent, '/') . '/')) {
            throw new \RuntimeException('View template link points outside its configured root.');
        }
        return $resolved;
    }

    /** Capture synchronous template output without leaking nested removable buffers into the caller. */
    public static function capture(callable $render): string
    {
        $level = ob_get_level();
        ob_start();
        try {
            $render();
            if (ob_get_level() < $level + 1) { throw new \RuntimeException('A template closed the renderer output buffer.'); }
            while (ob_get_level() > $level + 1) {
                self::assertRemovableBuffer();
                echo ob_get_clean();
            }
            self::assertRemovableBuffer();
            return ob_get_clean();
        } finally {
            while (ob_get_level() > $level) {
                self::assertRemovableBuffer();
                ob_end_clean();
            }
        }
    }

    /** Fail rather than loop forever on a buffer PHP does not permit the renderer to remove. */
    private static function assertRemovableBuffer(): void
    {
        if (((ob_get_status()['flags'] ?? 0) & PHP_OUTPUT_HANDLER_REMOVABLE) === 0) {
            throw new \RuntimeException('Templates must not leave non-removable output buffers.');
        }
    }

    /** No named parameters exist for extract() to overwrite, including the include target. */
    public static function includeFile(string $path, array $vars): void
    {
        (static function (): void {
            extract(func_get_arg(1), EXTR_SKIP);
            include func_get_arg(0);
        })($path, $vars);
    }
}
