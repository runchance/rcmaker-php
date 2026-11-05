<?php

define('ROOT_PATH', __DIR__);
require_once ROOT_PATH . '/{{VENDOR_AUTOLOAD}}';
// Also works when another bootless tool loaded Composer earlier in this process.
require_once \Composer\InstalledVersions::getInstallPath('runchance/rcmaker-php') . '/src/Runtime.php';
