<?php

namespace RC\Model;

if (!class_exists(\MongoDB\Laravel\Eloquent\Model::class)) {
    \RC\Helper\Db\Laravel::requirePackage('mongodb');
}

/** Optional document model. Extend this class in apps/{app}/model; the RC bootstrap supplies connections/events. */
abstract class MongoDB extends \MongoDB\Laravel\Eloquent\Model
{
    protected static $resolver;
    protected static $dispatcher;
}
