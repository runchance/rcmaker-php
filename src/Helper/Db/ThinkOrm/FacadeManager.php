<?php

declare(strict_types=1);

namespace RC\Helper\Db\ThinkOrm;

\RC\Helper\Db\Think::requirePackage();

/** Scoped legacy manager: connection/config selection is local; existing upstream observation services stay shared. */
final class FacadeManager extends \think\DbManager
{
    private \think\DbManager $delegate;
    private \think\db\ConnectionInterface $connection;
    private array $initialConfig;
    private string $connectionType;
    private int|string $connectionId;
    private string $scope;

    /** Bind a framework-owned cache slot without calling the parent constructor or registering another model maker. */
    public function __construct(\think\db\ConnectionInterface &$connection, array $config, string $type, int|string $id, string $key)
    {
        $this->delegate = \think\facade\Db::instance();
        $this->connection =& $connection;
        $this->initialConfig = $config;
        $this->config = $config;
        $this->config['default'] = $type;
        $this->connectionType = $type;
        $this->connectionId = $id;
        $this->scope = 'scope:' . bin2hex($type . ':' . $key);
    }

    /** Use $db::connect() for this group; explicit names/config arrays retain native connection selection. */
    public function connect(string|array|null $name = null, bool $force = false)
    {
        $name = empty($name) ? $this->getConfig('default', $this->connectionType) : $name;
        if (is_array($name)) {
            return $this->delegate->connect($name, $force);
        }
        $bound = $name === $this->connectionType;
        if (!$force && $bound) {
            return $this->connection;
        }
        if (!$force && isset($this->instance[$name])) {
            return $this->instance[$name];
        }
        $settings = $this->getConnectionConfig($name);
        if ($settings === ($this->initialConfig['connections'][$name] ?? null)) {
            $connection = $force
                ? \RC\Helper\Db\Think::reconnect($this->initialConfig, $name, $this->connectionId)
                : \RC\Helper\Db\Think::connection($this->initialConfig, $name, $this->connectionId);
        } else {
            $settings['rcmaker_connection_id'] = 'id:' . bin2hex((string) $this->connectionId);
            $settings['rcmaker_facade_scope'] = $this->scope;
            $settings['rcmaker_connection_name'] = 'name:' . bin2hex($name);
            $connection = $this->delegate->connect($settings, $force);
        }
        if ($bound) {
            return $this->connection = $connection;
        }
        return $this->instance[$name] = $connection;
    }

    /** Preserve global native SQL notification hooks; query connections themselves are never rebound to this adapter. */
    public function triggerSql(): void
    {
        $this->delegate->triggerSql();
    }

    /** Keep the upstream shared cache service; use $db::setCache($cache) only when intentionally configuring it. */
    public function setCache(\Psr\SimpleCache\CacheInterface $cache): void
    {
        $this->delegate->setCache($cache);
    }

    /** Keep the upstream shared logger; use $db::setLog($logger) for the native logging service. */
    public function setLog(\Psr\Log\LoggerInterface|\Closure $log): void
    {
        $this->delegate->setLog($log);
    }

    /** Forward explicit native log writes, e.g. $db::log('message', 'sql'). */
    public function log(string $log, string $type = 'sql')
    {
        return $this->delegate->log($log, $type);
    }

    /** Preserve the native deprecated query-log getter and its clear argument. */
    public function getDbLog(bool $clear = false): array
    {
        return $this->delegate->getDbLog($clear);
    }

    /** Preserve the native deprecated counter hook without maintaining a second counter. */
    public function updateQueryTimes(): void
    {
        $this->delegate->updateQueryTimes();
    }

    /** Clear the same upstream diagnostic counter used before this compatibility layer. */
    public function clearQueryTimes(): void
    {
        $this->delegate->clearQueryTimes();
    }

    /** Read the original native diagnostic counter, e.g. $db::getQueryTimes(). */
    public function getQueryTimes(): int
    {
        return $this->delegate->getQueryTimes();
    }

    /** Register native listeners in the existing manager, keeping SDB tracing and model queries observable. */
    public function listen(callable $callback): void
    {
        $this->delegate->listen($callback);
    }

    /** Return the same listener list used by actual native connections. */
    public function getListen(): array
    {
        return $this->delegate->getListen();
    }

    /** Preserve the native all-connections diagnostic view; use connect() to select this scoped connection. */
    public function getInstance(): array
    {
        return $this->delegate->getInstance();
    }

    /** Register existing native manager events, e.g. $db::event('after_insert', $callback). */
    public function event(string $event, callable $callback): void
    {
        $this->delegate->event($event, $callback);
    }

    /** Trigger the existing native manager event service without copying callback state. */
    public function trigger(string $event, $params = null)
    {
        return $this->delegate->trigger($event, $params);
    }
}
