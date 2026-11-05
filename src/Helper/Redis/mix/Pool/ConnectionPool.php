<?php

namespace RC\Helper\Redis\mix\Pool;

use RC\Helper\Redis\mix\Driver;
use RC\Helper\Db\mix\ObjectPool\AbstractObjectPool;

/**
 * Class ConnectionPool
 * @package Mix\Redis\Pool
 */
class ConnectionPool extends AbstractObjectPool
{

    /**
     * 借用连接
     * @return Driver
     */
    public function borrow(): object
    {
        return parent::borrow();
    }

}
