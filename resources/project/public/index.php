<?php

// FPM / PHP-MOD entry. Configure the web server document root to this public directory.
define('RUN_PATH', __DIR__);
require_once dirname(__DIR__) . '/index.php';
