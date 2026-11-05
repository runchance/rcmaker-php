<?php

declare(strict_types=1);

namespace RC\Helper\View;

require_once __DIR__ . '/bootstrap.php';

/** Treat PHAR templates as immutable files, not Smarty's writable/recompiled stream resource. */
class SmartyFile extends \Smarty\Resource\FilePlugin
{
    /** Native populate() retains its timestamps and security-policy checks for archive files too. */
    public function getFilePath($file, \Smarty\Smarty $smarty, bool $isConfig = false)
    {
        if (str_starts_with($file, 'phar://')) {
            if (PHP_OS_FAMILY === 'Windows') { $file = str_replace('\\', '/', $file); }
            return is_file($file) ? $file : false;
        }
        return parent::getFilePath($file, $smarty, $isConfig);
    }
}
