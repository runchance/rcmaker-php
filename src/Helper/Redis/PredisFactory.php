<?php

declare(strict_types=1);

namespace RC\Helper\Redis;

/** Optional Predis adapter: preserve literal-zero credentials without changing Composer-owned source. */
final class PredisFactory extends \Predis\Connection\Factory
{
    /** Pass the same defaults to discovered Cluster nodes as to seed connections. */
    public function __construct(array $defaults = [])
    {
        $this->setDefaultParameters($defaults);
    }

    /** Use explicit AUTH only for credentials that upstream empty() misclassifies; retain native HELLO/SELECT setup. */
    protected function prepareConnection(\Predis\Connection\NodeConnectionInterface $connection)
    {
        $parameters = $connection->getParameters();
        $password = $parameters->password;
        $username = $parameters->username;
        if ($password !== null && $password !== '' && ($password === '0' || $username === '0')) {
            $arguments = $username !== null && $username !== '' ? [$username, $password] : [$password];
            $connection->addConnectCommand(new \Predis\Command\RawCommand('AUTH', $arguments));
            // The native HELLO must not overwrite username "0" or omit the preceding explicit AUTH.
            $parameters->password = null;
            try { parent::prepareConnection($connection); }
            finally { $parameters->password = $password; }
            return;
        }
        parent::prepareConnection($connection);
    }
}
