<?php

namespace RC\Helper\Db\Laravel;

use Illuminate\Database\Connection;
use RC\Helper\Db\Laravel;
use UnitEnum;

use function Illuminate\Support\enum_value;

/** Native query resolution with RC cache synchronization only when connections are explicitly replaced. */
class DatabaseManager extends \Illuminate\Database\DatabaseManager
{
    /** Bind MongoDB cleanup to its owning manager, including a direct connection->disconnect() call. */
    protected function configure(Connection $connection, $type)
    {
        $connection = parent::configure($connection, $type);
        if ($connection instanceof MongoConnection) {
            $connection->setRcmakerDisconnectHandler(function (MongoConnection $closed): void {
                foreach ($this->connections as $name => $cached) { if ($cached === $closed) { unset($this->connections[$name]); } }
                $this->retireRcmakerConnection($closed);
                Laravel::synchronizeConnectionCache($this);
            });
        }
        return $connection;
    }

    /** Purge retires the old object; retained builders cannot reconnect through and reset its replacement. */
    public function purge($name = null)
    {
        $name = enum_value($name) ?: $this->getDefaultConnection();
        $old = $this->connections[$name] ?? null;
        try { parent::purge($name); }
        finally {
            if ($old !== null && !in_array($old, $this->connections, true)) { $this->retireRcmakerConnection($old); }
            Laravel::synchronizeConnectionCache($this);
        }
    }

    /** Publish the configured instance before its event, as native connection() does, so listeners resolve the same object. */
    public function connectUsing(UnitEnum|string $name, array $config, bool $force = false)
    {
        $name = enum_value($name);
        if ($force) { $this->purge($name); }
        if (isset($this->connections[$name])) { throw new \RuntimeException("Cannot establish connection [$name] because another connection with that name already exists."); }
        $connection = $this->configure($this->factory->make($config, $name), null);
        $this->connections[$name] = $connection;
        try {
            $this->dispatchConnectionEstablishedEvent($connection);
            return $this->connections[$name] ?? throw new \LogicException('Managed connection was purged during its establishment event.');
        } finally { Laravel::synchronizeConnectionCache($this); }
    }

    /** Retired objects cannot reopen replacements or clear their callbacks; live query/reconnect paths stay native. */
    private function retireRcmakerConnection(Connection $connection): void
    {
        $connection->unsetTransactionManager();
        $connection->setReconnector(static function (Connection $connection): never {
            throw new \LogicException('This managed connection was purged; resolve a fresh DB/SDB connection.');
        });
    }
}
