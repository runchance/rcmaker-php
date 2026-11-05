<?php

namespace RC\Helper\Db\think\facade;

\RC\Helper\Db\Think::requirePackage();
// Compatibility with explicit legacy facade imports, not connection-specific SDB state.
class_alias(\think\facade\Db::class, __NAMESPACE__ . '\\Db');
