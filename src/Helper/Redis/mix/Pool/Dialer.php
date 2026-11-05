<?php

namespace RC\Helper\Redis\mix\Pool;

use RC\Helper\Db\mix\ObjectPool\DialerInterface;
use RC\Helper\Redis\mix\Driver;

/** RC dialer passes extended connection settings to every new legacy pool session. */
class Dialer implements DialerInterface
{
    public $host;
    public $port;
    public $password;
    public $database;
    public $timeout;
    public $retryInterval;
    public $readTimeout;
    private array $options;

    /** Preserve positional construction without truncating fractional read timeouts. */
    public function __construct(string $host, int $port, string|array $password, int $database = 0,
        float $timeout = 5.0, int $retryInterval = 0, ?float $readTimeout = null, array $options = [])
    {
        $this->host = $host;
        $this->port = $port;
        $this->password = $password;
        $this->database = $database;
        $this->timeout = $timeout;
        $this->retryInterval = $retryInterval;
        $this->readTimeout = $readTimeout;
        $this->options = $options;
    }

    /** Each borrowed physical session receives the same credentials/TLS/prefix configuration. */
    public function dial(): object
    {
        return new Driver($this->host, $this->port, $this->password, $this->database,
            $this->timeout, $this->retryInterval, $this->readTimeout, $this->options);
    }
}
