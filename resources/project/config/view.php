<?php

return [
    'handler' => rcEnv('view.handler', RC\Helper\View\Raw::class),
    'suffix' => rcEnv('view.suffix', 'html'),
    'options' => [],
];
