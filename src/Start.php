<?php

// Composer also loads this file while activating the installation plugin.
// Legacy application entries define ROOT_PATH before loading Composer.
if (defined('ROOT_PATH')) {
    require_once __DIR__ . '/Runtime.php';
}
