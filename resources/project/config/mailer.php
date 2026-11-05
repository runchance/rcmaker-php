<?php

// 按需配置真实 SMTP；默认启动不会发送邮件。
return ['default' => [
    'Mailer' => 'smtp',
    'Host' => rcEnv('mailer.host', ''),
    'SMTPAuth' => true,
    'Username' => rcEnv('mailer.username', ''),
    'Password' => rcEnv('mailer.password', ''),
    'SMTPSecure' => 'tls',
    'Port' => (int) rcEnv('mailer.port', 587),
    'SMTPDebug' => 0,
    'Debugoutput' => 'error_log',
    'Exception' => true,
    'Language' => 'zh_cn',
    'CharSet' => 'utf-8',
]];
