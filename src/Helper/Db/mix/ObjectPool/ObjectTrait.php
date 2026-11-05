<?php

namespace RC\Helper\Db\mix\ObjectPool;

require_once dirname(__DIR__) . '/bootstrap.php';

/** Legacy RC trait name with pristine Mix ownership/return hooks. */
trait ObjectTrait
{
    use \Mix\ObjectPool\ObjectTrait;
}
