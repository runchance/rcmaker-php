<?php

namespace RC\Helper\Db\Laravel;

\RC\Helper\Db\Laravel::requirePackage();

/** Connection-bound native Capsule objects preserve legacy :: calls without replacing the global Capsule. */
abstract class LegacyCapsule extends Capsule
{
    private static array $capsules = [];
    private static int $sequence = 0;
    private Capsule $owner;

    /** Internal factory: reuse one object/class per native group; configuration and IDs never enter generated PHP. */
    public static function rcmakerBind(Capsule $owner, string $name): self
    {
        if (isset(self::$capsules[$name])) { return self::$capsules[$name]; }
        $short = 'Capsule_' . ++self::$sequence;
        $class = __NAMESPACE__ . '\\' . $short;
        if (class_exists($class, false)) { throw new \LogicException('Reserved Laravel Capsule class already exists.'); }
        // Redeclaring the inherited property isolates upstream static dispatch from the native/global instance.
        eval('namespace RC\\Helper\\Db\\Laravel; final class ' . $short . ' extends LegacyCapsule { protected static $instance; }');
        return self::$capsules[$name] = new $class($owner, $name);
    }

    /** Reuse initialized native services; no second container setup, Eloquent boot or database bootstrap occurs. */
    public function __construct(Capsule $owner, string $name)
    {
        $this->owner = $owner;
        $this->container = $owner->getContainer();
        $this->manager = new ScopedManager($owner->getDatabaseManager(), $name);
        static::$instance = $this;
    }

    /** Register explicit native configuration under the selected name without switching the global default. */
    public function addConnection(array $config, $name = 'default')
    {
        $this->owner->addConnection($config, $this->manager->rcmakerConnectionName($name));
    }

    /** Framework bootstrap already owns RC model resolution; a legacy repeat must not overwrite native globals. */
    public function bootEloquent()
    {
    }

    /** Keep instance-style native calls as well as inherited static Capsule methods, e.g. $db->transaction(...). */
    public function __call($method, $parameters)
    {
        return $this->getConnection()->$method(...$parameters);
    }
}
