<?php

namespace RC\Helper\Redis\mix;

require_once dirname(__DIR__, 2) . '/Db/mix/bootstrap.php';

/** Existing RC loggers satisfy the pristine stable Mix trace contract. */
interface LoggerInterface extends \Mix\Redis\LoggerInterface {}
