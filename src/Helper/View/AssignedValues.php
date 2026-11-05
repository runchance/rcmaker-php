<?php

declare(strict_types=1);

namespace RC\Helper\View;

/** One-render assignments; each driver owns its storage and clears it after a failed render too. */
trait AssignedValues
{
    protected static $_vars = [];

    /** Queue values for the next synchronous render; explicit render data has precedence. */
    public static function assign($name, $value = null)
    {
        if (!is_array($name) && !is_string($name) && !is_int($name)) {
            throw new \InvalidArgumentException('View variable names must be strings, integers or an array.');
        }
        static::$_vars = array_merge(static::$_vars, is_array($name) ? $name : [$name => $value]);
    }

    /** Discard assignments explicitly if a code path decides not to render. */
    public static function clear(): void
    {
        static::$_vars = [];
    }

    /** Consume before validation so a missing template cannot leave pending request data behind. */
    protected static function consume($vars): array
    {
        $assigned = static::$_vars;
        static::$_vars = [];
        if (!is_array($vars)) { throw new \InvalidArgumentException('View data must be an array.'); }
        return array_merge($assigned, $vars);
    }
}
