<?php

namespace RC\Helper\Db\think\db;

\RC\Helper\Db\Think::requirePackage();
// Preserve existing Raw objects while leaving SQL generation in upstream ThinkORM.
class_alias(\think\db\Raw::class, __NAMESPACE__ . '\\Raw');
