<?php

namespace RC\Helper\Db\think;

\RC\Helper\Db\Think::requirePackage();
// Legacy model name only; new applications should extend RC\Model\Think.
class_alias(\think\Model::class, __NAMESPACE__ . '\\Model');
