<?php

namespace RC\Helper\Db\mix\ObjectPool;

require_once dirname(__DIR__) . '/bootstrap.php';

/** Legacy RC dialers satisfy the original Mix object-pool contract. */
interface DialerInterface extends \Mix\ObjectPool\DialerInterface {}
