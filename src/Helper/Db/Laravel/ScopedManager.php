<?php

namespace RC\Helper\Db\Laravel;

use UnitEnum;

use function Illuminate\Support\enum_value;

\RC\Helper\Db\Laravel::requirePackage();

/** Legacy Capsule's local default selection; native lifecycle and connection caches remain framework-owned. */
final class ScopedManager extends \Illuminate\Database\DatabaseManager
{
    private \Illuminate\Database\DatabaseManager $delegate;
    private string $boundName;
    private $defaultName = 'default';

    /** Bind an existing manager/group without constructing another factory, model resolver or event dispatcher. */
    public function __construct(\Illuminate\Database\DatabaseManager $delegate, string $name)
    {
        $this->delegate = $delegate;
        $this->boundName = $name;
    }

    /** Internal name translation: the legacy default alias always identifies this Capsule's original group. */
    public function rcmakerConnectionName($name = null)
    {
        $name = enum_value($name) ?: $this->defaultName;
        if ($name === 'default') { return $this->boundName; }
        if ($name === 'default::read' || $name === 'default::write') {
            return $this->boundName . substr($name, strlen('default'));
        }
        return $name;
    }

    /** Resolve queries through the existing native cache; no connection/PDO object is retained by this view. */
    public function connection($name = null)
    {
        return $this->delegate->connection($this->rcmakerConnectionName($name));
    }

    /** Explicit dynamic connections keep upstream build semantics and managed lifecycle protection. */
    public function build(array $config)
    {
        return $this->delegate->build($config);
    }

    /** Explicit replacement targets the selected group without changing the global model default. */
    public function connectUsing(UnitEnum|string $name, array $config, bool $force = false)
    {
        return $this->delegate->connectUsing($this->rcmakerConnectionName($name), $config, $force);
    }

    /** Purge uses the existing RC retirement/cache hook, not a second independent cache. */
    public function purge($name = null)
    {
        return $this->delegate->purge($this->rcmakerConnectionName($name));
    }

    /** Disconnect only the explicitly selected native group; normal SQL object reuse remains upstream behavior. */
    public function disconnect($name = null)
    {
        return $this->delegate->disconnect($this->rcmakerConnectionName($name));
    }

    /** Reconnect uses the existing manager's SQL/MongoDB policy and cannot silently change another group's default. */
    public function reconnect($name = null)
    {
        return $this->delegate->reconnect($this->rcmakerConnectionName($name));
    }

    /** Preserve Capsule's default alias for callers inspecting its manager. */
    public function getDefaultConnection()
    {
        return $this->defaultName;
    }

    /** A local default change, including inherited usingConnection(), never changes model/global resolution. */
    public function setDefaultConnection($name)
    {
        $this->defaultName = enum_value($name);
    }

    /** Extensions are native shared infrastructure, as with Laravel::manager()->extend(). */
    public function extend($name, callable $resolver)
    {
        return $this->delegate->extend($this->rcmakerConnectionName($name), $resolver);
    }

    /** Remove the native resolver explicitly registered under this translated name. */
    public function forgetExtension($name)
    {
        return $this->delegate->forgetExtension($this->rcmakerConnectionName($name));
    }

    /** Diagnostics expose the native manager's cache; connection() selects this Capsule's group. */
    public function getConnections()
    {
        return $this->delegate->getConnections();
    }

    /** Reconnector customization remains an explicit shared-manager operation, not a per-request reset. */
    public function setReconnector(callable $reconnector)
    {
        return $this->delegate->setReconnector($reconnector);
    }

    /** Preserve explicit native infrastructure replacement; this is not needed by business controllers/models. */
    public function setApplication($app)
    {
        $this->delegate->setApplication($app);
        return $this;
    }
}
