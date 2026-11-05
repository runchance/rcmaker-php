<?php

declare(strict_types=1);

namespace zxf\Captcha;

// The two upstream fallback sites must not silently switch to a predictable RNG.
if (!function_exists(__NAMESPACE__ . '\\mt_rand')) {
    function mt_rand(int $min, int $max): never
    {
        throw new \RuntimeException('Secure randomness is unavailable; slider generation was aborted.');
    }
}
if (!class_exists(Captcha::class)) {
    require_once dirname(__DIR__, 2) . '/ThirdParty/zxf-captcha/src/Captcha.php';
}
