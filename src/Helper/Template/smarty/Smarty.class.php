<?php

declare(strict_types=1);

/** Legacy explicit include; Smarty 3 internals and SmartyBC are deliberately not restored. */
require_once dirname(__DIR__, 2) . '/View/bootstrap.php';
if (!function_exists('smarty_ucfirst_ascii')) { require_once dirname(__DIR__, 3) . '/ThirdParty/smarty/src/functions.php'; }
if (!class_exists('Smarty', false)) { class_alias(\RC\Helper\View\SmartyEngine::class, 'Smarty'); }
