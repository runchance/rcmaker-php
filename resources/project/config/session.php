<?php

return [
    'handler' => RC\Http\FileSessionHandler::class,
    'config' => ['file' => ['save_path' => runtime_path() . '/sessions']],
    'session_name' => 'PHPSID',
];
