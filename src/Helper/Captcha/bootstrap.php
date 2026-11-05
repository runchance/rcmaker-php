<?php

declare(strict_types=1);

/** Load compatible Composer classes first, otherwise the unchanged bundled Gregwar sources. */
if (!class_exists(\Gregwar\Captcha\CaptchaBuilder::class)) {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Gregwar\\Captcha\\';
        if (str_starts_with($class, $prefix)) {
            $relative = substr($class, strlen($prefix));
            if (preg_match('/^[A-Za-z0-9_\\\\]+$/D', $relative)) {
                $file = dirname(__DIR__, 2) . '/ThirdParty/gregwar-captcha/src/Gregwar/Captcha/'
                    . str_replace('\\', '/', $relative) . '.php';
                if (is_file($file)) {
                    require_once $file;
                }
            }
        }
    });
}
if (!method_exists(\Gregwar\Captcha\CaptchaBuilder::class, 'getImageType')) {
    throw new \RuntimeException('RC captcha requires compatible gregwar/captcha 2.1.x sources.');
}
