<?php

declare(strict_types=1);

namespace RC\Helper\View;

use RC\View;

/** ThinkTemplate adapter: fresh render data, native tag compilation, shared compiled-file cache. */
class ThinkPHP implements View
{
    use AssignedValues;

    public static $_version = '3.0.2';

    /** Capture the native echo-based fetch contract without retaining mutable engine data. */
    public static function render($template, $vars, $app = 'default')
    {
        $data = static::consume($vars);
        try {
            $directory = Runtime::directory(Runtime::app($app));
            $options = array_replace([
                'view_path' => $directory . '/',
                'cache_path' => \runtime_path() . '/views/think/' . substr(hash('sha256', $directory), 0, 20) . '/',
                'view_suffix' => Runtime::suffix(),
            ], Runtime::options());
            $configure = $options['configure'] ?? null; unset($options['configure']);
            $suffix = $options['view_suffix']; $viewRoot = $options['view_path'];
            if (!is_string($suffix) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D', $suffix) || str_contains($suffix, '..') || !is_string($viewRoot)) {
                throw new \InvalidArgumentException('Invalid ThinkTemplate path or suffix.');
            }
            if (!is_string($options['cache_path']) || str_contains($options['cache_path'], '://')) {
                throw new \InvalidArgumentException('ThinkTemplate cache_path must be a local directory.');
            }
            $options['cache_path'] = rtrim($options['cache_path'], PHP_OS_FAMILY === 'Windows' ? '/\\' : '/') . '/';
            $path = Runtime::file($viewRoot, Runtime::name($template) . '.' . $suffix);
            $engine = new ThinkEngine($options);
            if ($configure !== null) {
                if (!is_callable($configure)) { throw new \InvalidArgumentException('ThinkTemplate configure must be callable.'); }
                $configure($engine);
            }
            return Runtime::capture(static fn() => $engine->fetch($path, $data));
        } finally { static::clear(); }
    }
}
