<?php

// 仅构建时使用，不控制应用运行时；可通过 php index.php interact 保存选择。
// 完整模式保持兼容。custom 的空列表表示只保留核心能力，原生视图始终保留。
return [
    'binary' => [
        'mode' => 'full',
        'components' => ['pdf', 'pinyin', 'excel', 'captcha', 'templates', 'mailer', 'qrcode'],
        // 默认移除源项目构建工具，保留 Banner、Windows 进程管理等运行依赖。
        // 仅当业务直接调用 RC\Cli 下的工具类时设为 true；interact 仍只用于源码项目。
        'keep_cli_tools' => false,
    ],
];
