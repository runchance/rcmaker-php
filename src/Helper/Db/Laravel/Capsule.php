<?php

namespace RC\Helper\Db\Laravel;

/** Use the native Capsule container with an isolated RC factory; do not set a global Capsule instance. */
class Capsule extends \Illuminate\Database\Capsule\Manager
{
    /** The native manager configures reconnectors, events and transaction callbacks for every named connection. */
    protected function setupManager()
    {
        $this->manager = new DatabaseManager($this->container, new ConnectionFactory($this->container));
    }
}
