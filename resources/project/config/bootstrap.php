<?php

// 框架负责初始化；控制器、模型中不要再次调用 Medoo::start() 等方法。
return rcEnv('bootstrap.load', [
    RC\Session::class,
    RC\Helper\Cache\Raw::class,
    RC\Helper\Db\Medoo::class,
]);
