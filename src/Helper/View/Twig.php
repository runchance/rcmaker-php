<?php

declare(strict_types=1);

namespace RC\Helper\View;

use RC\View;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/** Optional native Twig 3; install twig/twig through Composer before selecting this handler. */
class Twig implements View
{
    use AssignedValues;

    public static $_version = '3.30.0';
    private static array $engines = [];

    /** Reuse native environments only while their application root and options are unchanged. */
    public static function render($template, $vars, $app = 'default')
    {
        $data = static::consume($vars);
        try {
            if (!class_exists(Environment::class) || Environment::VERSION !== '3.30.0') {
                throw new \RuntimeException('Install stable twig/twig 3.30.0 to use this view handler.');
            }
            $app = Runtime::app($app); $name = Runtime::name($template) . '.' . Runtime::suffix();
            $directory = Runtime::directory($app); $options = Runtime::options();
            Runtime::file($directory, $name);
            if (!isset(self::$engines[$app]) || self::$engines[$app][0] !== $directory || self::$engines[$app][1] !== $options) {
                $nativeOptions = $options; $configure = $nativeOptions['configure'] ?? null; unset($nativeOptions['configure']);
                $environment = new Environment(new FilesystemLoader($directory), $nativeOptions);
                if ($configure !== null) {
                    if (!is_callable($configure)) { throw new \InvalidArgumentException('Twig configure must be callable.'); }
                    $configure($environment);
                }
                self::$engines[$app] = [$directory, $options, $environment];
            }
            return self::$engines[$app][2]->render($name, $data);
        } finally { static::clear(); }
    }
}
