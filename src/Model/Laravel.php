<?php
namespace RC\Model;
if (!class_exists(\Illuminate\Database\Eloquent\Model::class)) {
    \RC\Helper\Db\Laravel::requirePackage();
}
/** Application models extend this class; the framework bootstrap provides their resolver and optional events. */
class Laravel extends \Illuminate\Database\Eloquent\Model{
    /** RC models share their own resolver, without replacing unrelated native Eloquent applications. */
    protected static $resolver;
    /** Optional RC event dispatcher; this intentionally shadows the upstream base model's static state. */
    protected static $dispatcher;
}
?>
