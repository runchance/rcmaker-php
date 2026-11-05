<?php

// image 为图形验证码，slider 为离线滑块；自定义连接继续在本文件增加。
return [
    'default' => [
        'provider' => rcEnv('captcha.provider', 'image'),
        'expire' => (int) rcEnv('captcha.expire', 300),
        'namePrefix' => rcEnv('captcha.name_prefix', 'RC_CAPTCHA_'),
        'length' => (int) rcEnv('captcha.length', 5),
        'store' => rcEnv('captcha.store', 'cache'),
        'phrase' => [
            'width' => (int) rcEnv('captcha.image_width', 150),
            'height' => (int) rcEnv('captcha.image_height', 40),
            'font' => rcEnv('captcha.font', null),
            'fingerprint' => null,
        ],
        'charset' => rcEnv('captcha.charset', 'abcdefghijklmnpqrstuvwxyz123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ'),
        'autoDelete' => (bool) rcEnv('captcha.auto_delete', true),
        'return' => rcEnv('captcha.return', 'image'),
        'token_expire' => (int) rcEnv('captcha.token_expire', 60),
        'width' => (int) rcEnv('captcha.width', 280),
        'height' => (int) rcEnv('captcha.height', 160),
        'tolerance' => (int) rcEnv('captcha.tolerance', 3),
        'rate_limit' => [
            'window' => (int) rcEnv('captcha.rate_window', 60),
            'max_requests' => (int) rcEnv('captcha.rate_max_requests', 30),
        ],
        'backgrounds' => rcEnv('captcha.backgrounds', []),
    ],
];
