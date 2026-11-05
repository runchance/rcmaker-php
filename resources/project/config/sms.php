<?php

return [
    'type' => (int) rcEnv('sms.type', 1),
    'length' => (int) rcEnv('sms.length', 4),
    'expire' => (int) rcEnv('sms.expire', 180),
    'ipCheck' => (bool) rcEnv('sms.ip_check', true),
    'mobileKey' => rcEnv('sms.mobile_key', 'mobile'),
    'codeKey' => rcEnv('sms.code_key', 'code'),
    'autoDelete' => (bool) rcEnv('sms.auto_delete', true),
];
