<?php

declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/View/bootstrap.php';
if (!class_exists('RC\\Helper\\Template\\think\\template\\exception\\TemplateNotFoundException', false)) {
    class_alias(\think\template\exception\TemplateNotFoundException::class, 'RC\\Helper\\Template\\think\\template\\exception\\TemplateNotFoundException');
}
