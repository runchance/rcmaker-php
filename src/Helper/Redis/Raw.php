<?php
namespace RC\Helper\Redis;
use RC\Bootstrap;
use RC\Config;
use RC\Request;
class Raw implements Bootstrap{
	public static $_version = '1.0.0';
	private static $_config = null;
	private static $default_config =[
		'type'=>'common',
		'host'=>'127.0.0.1',
		'port'=>6379,
		'password'=>null,
		'database'=>0,
		'timeout'=>5,
		'retryInterval'=>null,
		'readTimeout'=>null,
		'persistent'=>false,
		'select'=>'x',
		'prefix'=>'',
	];

	private static function creat_config($config){
		$_config = [];
		if(!$config){
			return false;
		}
		if(isset($config['default_frame'])){
			unset($config['default_frame']);
		}
		foreach($config as $driver=>$conf){
			if (!is_array($conf)) {
				throw new \InvalidArgumentException('Redis connection configuration must be an array.');
			}
			if(isset($_config['connections'][$driver])){
				continue;
			}
			$_config['connections'][$driver] = array_replace(static::$default_config, $conf);
		}
		return $_config;
	}
	public static function start()
    {
    	static::$_config = static::$_config ?? static::creat_config(Config::get('redis') ?? []);
    	if(!static::$_config){
    		return;
    	}
        $config = static::$_config;
        if (!extension_loaded('redis') && !class_exists(\Predis\Client::class)) {
            throw new \RuntimeException('Raw Redis requires ext-redis or the stable predis/predis Composer package.');
        }
        $class = extension_loaded('redis') ? \Redis::class : \Predis\Client::class;
        \redis('raw', '', 1, $class, \RedisCluster::class, $config);
    }
}
?>
