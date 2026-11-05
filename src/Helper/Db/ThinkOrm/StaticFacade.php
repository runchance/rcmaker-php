<?php

namespace RC\Helper\Db\ThinkOrm;

\RC\Helper\Db\Think::requirePackage();

/** Connection-bound legacy class strings for DB('think')::$method(); SDB uses native connections instead. */
abstract class StaticFacade extends \think\Facade
{
    private static array $classes = [];
    private static array $managers = [];
    private static int $sequence = 0;

    /** Internal factory: one class per configured driver/ID, retaining the actual framework cache slot by reference. */
    public static function rcmakerBind(\think\db\ConnectionInterface &$connection, array $config, string $type, int|string $id, string $key): string
    {
        if (isset(self::$classes[$type][$key])) {
            return self::$classes[$type][$key];
        }
        $short = 'Connection_' . ++self::$sequence;
        $class = __NAMESPACE__ . '\\' . $short;
        if (class_exists($class, false)) {
            throw new \LogicException('Reserved ThinkORM connection facade already exists.');
        }
        $manager = new FacadeManager($connection, $config, $type, $id, $key);
        // Only a fixed namespace and an internal integer enter PHP source, never configuration or user input.
        eval('namespace RC\\Helper\\Db\\ThinkOrm; final class ' . $short . ' extends StaticFacade {}');
        self::$managers[$class] = $manager;
        return self::$classes[$type][$key] = $class;
    }

    /** Preserve $db::instance() while keeping its subsequent ->table()/connect() calls in the selected group. */
    public static function instance(...$args): \think\DbManager
    {
        return self::$managers[static::class] ?? throw new \LogicException('ThinkORM facade is not bound to a connection.');
    }

    /** Keep inherited Facade factory dispatch bound without constructing global/model managers again. */
    protected static function createFacade(string $class = '', array $args = [], bool $newInstance = false): \think\DbManager
    {
        return static::instance(...$args);
    }

    /** Forward query, transaction and manager APIs with the upstream Facade's named/scalar argument semantics. */
    public static function __callStatic($method, $params): mixed
    {
        return call_user_func_array([static::instance(), $method], $params);
    }
}
