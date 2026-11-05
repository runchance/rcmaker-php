<?php
namespace RC\Helper\Cache;
use RC\Bootstrap;
use RC\Config;
use RC\Request;
use RC\Helper\Cache\think\facade\Cache;
class Raw implements Bootstrap{
	public static $_version = '3.0.1';
	private static $_config = null;
	public static $support = ['File','Memcache','Memcached','Redis'];
	public static function start()
    {
        static::$_config = static::$_config ?? Config::get('cache') ?? [];
    	if(!static::$_config){
    		return;
    	}
        $config = static::$_config;
        \cache('raw', '', 1, Cache::class, $config);
    }
}
?>
