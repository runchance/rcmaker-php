<?php

declare(strict_types=1);

namespace RC\Helper\View;

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Component;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Engines\FileEngine;
use Illuminate\View\Engines\PhpEngine;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use RC\View;

/** Optional Illuminate View 13 adapter, sharing the supported major version with Eloquent. */
class Blade implements View
{
    use AssignedValues;

    public static $_version = '13.x (Composer)';
    private static array $engines = [];

    /** Render a native .blade.php view; ordinary application data is never shared on the factory. */
    public static function render($template, $vars, $app = 'default')
    {
        $data = static::consume($vars);
        try {
            if (!class_exists(Factory::class)) {
                throw new \RuntimeException('Install stable illuminate/view 13.40.0 to use this view handler.');
            }
            $app = Runtime::app($app);
            $name = Runtime::name(str_replace('.', '/', Runtime::name($template)));
            $directory = Runtime::directory($app); $options = Runtime::options();
            Runtime::file($directory, $name . '.blade.php');
            if (!isset(self::$engines[$app]) || self::$engines[$app][0] !== $directory || self::$engines[$app][1] !== $options) {
                self::$engines[$app] = [$directory, $options, self::createEngine($directory, $options)];
            }
            [$factory, $compilerEngine] = self::$engines[$app][2];
            $previous = Container::getInstance();
            Container::setInstance($factory->getContainer());
            Component::forgetFactory(); Component::flushCache();
            try { return $factory->make(str_replace('/', '.', $name), $data)->render(); }
            finally {
                $factory->flushStateIfDoneRendering(); $compilerEngine->forgetCompiledOrNotExpired();
                Component::forgetFactory(); Container::setInstance($previous);
            }
        } finally { static::clear(); }
    }

    /** Bind standalone native view services without replacing the application's global container. */
    private static function createEngine(string $directory, array $options): array
    {
        $unknown = array_diff(array_keys($options), ['cache_path', 'configure', 'cache', 'check_timestamps']);
        if ($unknown !== []) { throw new \InvalidArgumentException('Unsupported Blade option: ' . implode(', ', $unknown)); }
        $cache = $options['cache_path'] ?? \runtime_path() . '/views/blade/' . substr(hash('sha256', $directory), 0, 20);
        if (!is_string($cache) || $cache === '' || str_contains($cache, '://')) { throw new \InvalidArgumentException('Blade cache_path must be a local directory.'); }
        if (!\RC\Config::mkdir($cache)) { throw new \RuntimeException('Cannot create the Blade compile-cache directory.'); }
        $files = new Filesystem(); $container = new Container();
        $compiler = new BladeCompiler($files, $cache, '', $options['cache'] ?? true, 'php', $options['check_timestamps'] ?? true);
        $engine = new CompilerEngine($compiler, $files); $resolver = new EngineResolver();
        $resolver->register('blade', static fn() => $engine);
        $resolver->register('php', static fn() => new PhpEngine($files));
        $resolver->register('file', static fn() => new FileEngine($files));
        $factory = new Factory($resolver, new FileViewFinder($files, [$directory]), new Dispatcher($container));
        $factory->setContainer($container);
        foreach (['view', Factory::class, \Illuminate\Contracts\View\Factory::class] as $binding) { $container->instance($binding, $factory); }
        $container->instance('blade.compiler', $compiler);
        if (isset($options['configure'])) {
            if (!is_callable($options['configure'])) { throw new \InvalidArgumentException('Blade configure must be callable.'); }
            $options['configure']($compiler, $factory, $container);
        }
        return [$factory, $engine];
    }
}
