<?php

namespace RC\Helper\Redis\mix;

/** One ordinary command owns a driver until the native response and logger have completed. */
class Connection extends AbstractConnection
{
    /** Execute once, retaining scan references and leaving failed commands unreplayed. */
    public function __call($command, $arguments = [])
    {
        $this->assertIdle();
        try { return $this->invoke($command, $arguments); }
        finally { $this->releaseConnection(); }
    }

    /** Return an unused scope safely; moved scopes no longer contain a driver. */
    public function __destruct() { if (!$this->invoking) { $this->releaseConnection(); } }
}
