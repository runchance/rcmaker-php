<?php
namespace RC\Helper\Db;
use RC\Bootstrap;
use RC\Config;
use RC\Request;
use RC\Helper\Db\Medoo\Medoo as Db;
class Medoo implements Bootstrap{
	/**
     * @param Worker $worker
     *
     * @return void
     */
	public static $_version = '2.6.1';
	public static $support = ['mysql','sqlite','pgsql','sqlsrv','oracle','sybase'];
	private static $_config = null;

	/**
	 * Translate framework configuration without filtering out new upstream options.
	 * Adapter-specific options belong in driver.<name>.options; native names win.
	 */
	private static function creat_config($config){
		if (!$config || !isset($config['default'], $config['driver'][$config['default']])) {
			return false;
		}
		$result = ['default' => $config['default'], 'connections' => []];
		foreach ($config['driver'] as $driver => $connection) {
			if (!in_array($driver, static::$support, true)) {
				continue;
			}
			$options = $connection['options'] ?? [];
			if (!is_array($options)) {
				throw new \InvalidArgumentException('Medoo connection options must be an array.');
			}
			foreach (['server' => 'host', 'database_name' => 'database', 'database_file' => 'database'] as $legacy => $native) {
				if (array_key_exists($legacy, $options) && !array_key_exists($native, $options)) {
					$options[$native] = $options[$legacy];
				}
			}
			unset($connection['options']);
			$connection = array_replace($connection, $options);
			$connection['type'] = $driver === 'sqlsrv' ? 'mssql' : $driver;
			if (array_key_exists('database_file', $connection) && !isset($connection['database'])) {
				$connection['database'] = $connection['database_file'];
			}
			if (($connection['charset'] ?? null) === '') {
				unset($connection['charset']);
			}
			// Failures must be observable by SDB and AutoForm, rather than empty results.
			$connection['error'] = \PDO::ERRMODE_EXCEPTION;
			if (isset($connection['pdo']) && $connection['pdo'] instanceof \PDO) {
				$connection['pdo']->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
			}
			$result['connections'][$driver] = $connection;
		}
		return $result;
	}

    public static function start()
    {
    	static::$_config = static::$_config ?? static::creat_config(Config::get('db') ?? []);
    	if(!static::$_config){
    		return;
    	}

        $config = static::$_config;
        if ($config['default'] === 'sqlite') {
            $connection = $config['connections']['sqlite'] ?? [];
            $database = $connection['database'] ?? null;
            if (!isset($connection['pdo']) && is_string($database) && $database !== '' && $database !== ':memory:'
                && !str_contains($database, '://') && !str_starts_with($database, 'file:')) {
                $directory = dirname($database);
                if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
                    throw new \RuntimeException('Cannot create the configured SQLite database directory.');
                }
            }
        }
        \database('medoo','',1,Db::class,$config,static::$support);
    }
}
?>
