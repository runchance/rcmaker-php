<?php

namespace RC\Helper\Db\mix\ObjectPool\Exception;

require_once dirname(__DIR__, 2) . '/bootstrap.php';

/** Existing RC timeout type also satisfies the pristine Mix exception contract. */
class WaitTimeoutException extends \Mix\ObjectPool\Exception\WaitTimeoutException {}
