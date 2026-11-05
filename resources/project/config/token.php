<?php

// 未配置部署密钥时拒绝签发，不内置可用于生产的演示密钥。
return [
    'default' => rcEnv('token.default', 'api'),
    'api' => [
        'signer' => rcEnv('token.signer', 'HS256'),
        'type' => rcEnv('token.type', 'Bearer'),
        'keyName' => rcEnv('token.key_name', 'token'),
        'access_secret_key' => rcEnv('token.access_secret_key', ''),
        'access_expired' => (int) rcEnv('token.access_expired', 7200),
        'refresh_secret_key' => rcEnv('token.refresh_secret_key', ''),
        'refresh_expired' => (int) rcEnv('token.refresh_expired', 604800),
        'refresh_disable' => (bool) rcEnv('token.refresh_disable', false),
        'iss' => rcEnv('token.iss', 'rcmaker'),
        'leeway' => (int) rcEnv('token.leeway', 60),
        'is_single_device' => (bool) rcEnv('token.is_single_device', true),
        'cache_token_time' => (int) rcEnv('token.cache_token_time', 604800),
        'cache_token_prefix' => rcEnv('token.cache_token_prefix', 'RC:AUTH:TOKEN:'),
        'access_private_key' => null,
        'access_public_key' => null,
        'refresh_private_key' => null,
        'refresh_public_key' => null,
    ],
];
