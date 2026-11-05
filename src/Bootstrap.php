<?php
namespace RC;
interface Bootstrap
{
    /**
     * onWorkerStart
     *
     * @param $worker Worker
     * @return mixed
     */
    public static function start();
}
