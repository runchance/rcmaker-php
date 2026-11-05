<?php
namespace RC;
use RC\Container;
/** Resolve and validate process middleware at startup; request callbacks reuse the compiled callable lists. */
class Middleware{
	protected static $_instances = [];


    /** Load one complete config snapshot; rejection leaves the previous registry intact instead of partially disabling guards. */
    public static function load($all_middlewares){
        // Config::get() returns false when the optional middleware file is absent.
        if ($all_middlewares === false || $all_middlewares === null) { $all_middlewares = []; }
        if (!is_array($all_middlewares)) { throw new \InvalidArgumentException('Middleware configuration must be an array.'); }
        $instances = [];
        foreach ($all_middlewares as $type => $middlewaretypes) {
            if (!in_array($type, ['middleware', 'static_middleware'], true) || !is_array($middlewaretypes)) {
                throw new \InvalidArgumentException('Invalid middleware configuration section: ' . $type);
            }
            if ($type === 'static_middleware') {
                foreach ($middlewaretypes as $item) { $instances['__static__'][] = self::resolve($item); }
                continue;
            }
            foreach ($middlewaretypes as $app => $middlewares) {
                if (!is_array($middlewares)) { throw new \InvalidArgumentException('Middleware entries must be arrays: ' . $app); }
                $path = str_starts_with((string) $app, 'path:') ? self::configuredPath(substr($app, 5)) : null;
                if ($app === '__path__') { throw new \InvalidArgumentException('__path__ is reserved for URL middleware.'); }
                foreach ($middlewares as $item) {
                    $callable = self::resolve($item);
                    if ($path !== null) {
                        $instances['__path__'][$path][] = $callable;
                    } else {
                        $instances[$app][] = $callable;
                    }
                }
            }
        }
        static::$_instances = $instances;
    }

    /** Support class strings and the existing support/middleware handle shorthand, never arbitrary relative includes. */
    private static function resolve($item): array
    {
        if (is_array($item)) {
            $handle = $item['handle'] ?? null;
            if (!is_string($handle) || !preg_match('~\A[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(?:[\\\\/][A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*\z~D', $handle)) {
                throw new \InvalidArgumentException('Middleware handle must be a relative class name.');
            }
            $handle = str_replace('/', '\\', $handle);
            $class = 'support\\middleware\\' . $handle;
            if (!class_exists($class)) {
                Container::loadClass(BASE_PATH . '/support/middleware/' . str_replace('\\', '/', $handle) . '.php', $class);
            }
        } else {
            $class = $item;
        }
        self::validateClass($class);
        $callable = [Container::get($class), 'handle'];
        if (!is_callable($callable)) { throw new \InvalidArgumentException('Middleware handle is not callable.'); }
        return $callable;
    }

    /** Internal declaration check shared by config and routes; never instantiate resources before worker startup. */
    public static function validateClass($class): void
    {
        if (!is_string($class) || $class === '' || !class_exists($class) || !method_exists($class, 'handle')) {
            throw new \InvalidArgumentException('Middleware must name an existing class with a public handle method.');
        }
        $method = new \ReflectionMethod($class, 'handle');
        if (!$method->isPublic() || $method->getNumberOfRequiredParameters() > 2) {
            throw new \InvalidArgumentException('Middleware handle must be public and accept the request/next invocation.');
        }
        $definition = new \ReflectionClass($class);
        if (!Container::has($class) && (!$definition->isInstantiable()
            || ($definition->getConstructor()?->getNumberOfRequiredParameters() ?? 0) > 0)) {
            throw new \InvalidArgumentException('Middleware must be constructible without required arguments or already registered in Container.');
        }
    }

    /** With no argument return the prefix-to-list registry; with a URL return matching callables in reducer order. */
    public static function getPathMiddleware(?string $path = null): array
    {
        $policies = static::$_instances['__path__'] ?? [];
        if ($path === null) { return $policies; }
        if (!$policies) { return []; }
        $canonical = self::normalizePath($path);
        $matches = [];
        foreach ($policies as $prefix => $middlewares) {
            if (self::matchesPath($path, $prefix) || self::matchesPath($canonical, $prefix)) {
                array_push($matches, ...$middlewares);
            }
        }
        return array_reverse($matches);
    }

    /** Validate configuration before publishing a snapshot; '/' intentionally protects every framework URL. */
    private static function configuredPath(string $path): string
    {
        if ($path === '' || $path[0] !== '/' || preg_match('~[\\x00-\\x20\\x7f?#\\\\\\\\]~', $path)
            || preg_match('~[\\x00-\\x1f\\x7f?#]~', rawurldecode($path))) {
            throw new \InvalidArgumentException('Path middleware requires an absolute URL path without query or fragment.');
        }
        return self::normalizePath($path);
    }

    /** Compare complete path segments, including Windows disk aliases, never '/admin-other' as '/admin'. */
    private static function matchesPath(string $path, string $prefix): bool
    {
        if ($prefix === '/') { return true; }
        $length = strlen($prefix);
        $same = PHP_OS_FAMILY === 'Windows' ? strncasecmp($path, $prefix, $length) === 0 : strncmp($path, $prefix, $length) === 0;
        return $same && (strlen($path) === $length || ($path[$length] ?? '') === '/');
    }

    /** Policy view only: preserve dispatch semantics but guard decoded, repeated-slash and disk dot-segment aliases too. */
    private static function normalizePath(string $path): string
    {
        $segments = [];
        foreach (explode('/', str_replace('\\', '/', rawurldecode($path))) as $segment) {
            if ($segment === '' || $segment === '.') { continue; }
            if ($segment === '..') { array_pop($segments); } else { $segments[] = $segment; }
        }
        return '/' . implode('/', $segments);
    }

    public static function getMiddleware($app) {
        $global_middleware = static::$_instances[''] ?? [];
        if ($app === '') {
            return \array_reverse($global_middleware);
        }
        $app_middleware = static::$_instances[$app] ?? [];
        return \array_reverse(\array_merge($global_middleware, $app_middleware, ));
    }

    /** Custom static routes keep their app guards; global and static guards are each included exactly once. */
    public static function getStaticMiddleware($app = '__static__'): array
    {
        $global = static::$_instances[''] ?? [];
        $application = $app === '' || $app === '__static__' ? [] : (static::$_instances[$app] ?? []);
        return array_reverse(array_merge($global, $application, static::$_instances['__static__'] ?? []));
    }

    public static function hasMiddleware($app){
        return isset(static::$_instances[$app]);
    }

}
?>
