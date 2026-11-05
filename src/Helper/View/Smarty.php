<?php

declare(strict_types=1);

namespace RC\Helper\View;

use RC\View;

require_once __DIR__ . '/bootstrap.php';

/** Smarty 5 adapter. Register native plugins through view.options.configure when needed. */
class Smarty implements View
{
    use AssignedValues;

    public static $_version = '5.8.5';
    private static array $engines = [];

    /** Reuse compilation services per app/config, never the previous template's assigned data. */
    public static function render($template, $vars, $app = 'default')
    {
        $data = static::consume($vars);
        try {
            $app = Runtime::app($app); $name = Runtime::name($template) . '.' . Runtime::suffix();
            $directory = Runtime::directory($app);
            $options = Runtime::options();
            $root = $options['template_dir'] ?? $directory;
            if (!is_string($root)) { throw new \InvalidArgumentException('Smarty template_dir must be one local or PHAR root.'); }
            $path = Runtime::file($root, $name);
            if (!isset(self::$engines[$app]) || self::$engines[$app][0] !== $directory || self::$engines[$app][1] !== $options) {
                $engine = self::createEngine($directory, $options);
                self::$engines[$app] = [$directory, $options, $engine];
            }
            return self::$engines[$app][2]->createTemplate('file:' . $path, $data)->fetch();
        } finally { static::clear(); }
    }

    /** Apply supported native setters rather than creating ignored dynamic properties. */
    private static function createEngine(string $directory, array $options): \Smarty\Smarty
    {
        if (!defined('Smarty\\Smarty::SMARTY_VERSION') || \Smarty\Smarty::SMARTY_VERSION !== '5.8.5') {
            throw new \RuntimeException('RCMAKER requires stable smarty/smarty 5.8.5.');
        }
        $engine = new SmartyEngine();
        $cache = \runtime_path() . '/views/smarty/' . substr(hash('sha256', $directory), 0, 20);
        $options = array_replace(['template_dir' => $directory, 'compile_dir' => $cache . '/compile', 'cache_dir' => $cache . '/cache'], $options);
        $configure = $options['configure'] ?? null; unset($options['configure']);
        foreach ($options as $key => $value) {
            if (!is_string($key)) { throw new \InvalidArgumentException('Smarty options require named keys.'); }
            $setter = 'set' . str_replace(' ', '', ucwords(str_replace('_', ' ', $key)));
            if (method_exists($engine, $setter)) { $engine->{$setter}($value); }
            elseif (property_exists($engine, $key) && (new \ReflectionProperty($engine, $key))->isPublic()) { $engine->{$key} = $value; }
            else { throw new \InvalidArgumentException('Unsupported Smarty 5 option: ' . $key); }
        }
        if ($configure !== null) {
            if (!is_callable($configure)) { throw new \InvalidArgumentException('Smarty configure must be callable.'); }
            $configure($engine);
        }
        return $engine;
    }
}
