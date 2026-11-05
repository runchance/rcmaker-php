<?php

declare(strict_types=1);

namespace RC\Helper\View;

use RC\View;

/** Trusted PHP templates: render('page', ['name' => $name], 'index'). Escape untrusted values in the template. */
class Raw implements View
{
    use AssignedValues;

    public static $_version = '0.1';

    /** Isolate view variables from the include path and the caller's output-buffer state. */
    public static function render($template, $vars, $app = 'default')
    {
        $data = static::consume($vars);
        try {
            $name = Runtime::name($template);
            $directory = Runtime::directory(Runtime::app($app));
            $path = Runtime::file($directory, $name . '.' . Runtime::suffix());
            return Runtime::capture(static fn() => Runtime::includeFile($path, $data));
        } finally {
            static::clear();
        }
    }
}
