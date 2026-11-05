<?php

// 按需配置真实 SMTP；默认启动不会发送邮件。
return ['default' => [
    'Mailer' => rcEnv('mailer.driver', 'smtp'),
    'Host' => rcEnv('mailer.host', ''),
    'SMTPAuth' => (bool) rcEnv('mailer.smtp_auth', true),
    'Username' => rcEnv('mailer.username', ''),
    'Password' => rcEnv('mailer.password', ''),
    'SMTPSecure' => rcEnv('mailer.encryption', 'tls'),
    'Port' => (int) rcEnv('mailer.port', 587),
    'SMTPDebug' => (int) rcEnv('mailer.debug', 0),
    'Debugoutput' => rcEnv('mailer.debug_output', 'error_log'),
    'Exception' => (bool) rcEnv('mailer.exceptions', true),
    'Language' => rcEnv('mailer.language', 'zh_cn'),
    'CharSet' => rcEnv('mailer.charset', 'utf-8'),
]];
