<?php

// 默认图形验证码；离线滑块配置见在线验证码组件手册。
return [
    'default' => [
        'expire' => 300,
        'namePrefix' => 'RC_CAPTCHA_',
        'length' => 5,
        'store' => 'cache',
        'phrase' => ['width' => 150, 'height' => 40, 'font' => null, 'fingerprint' => null],
        'charset' => 'abcdefghijklmnpqrstuvwxyz123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ',
        'autoDelete' => true,
        'return' => 'image',
    ],
];
