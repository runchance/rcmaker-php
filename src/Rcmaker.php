<?php
	namespace RC;
	use RC\Config;
	use RC\Model;
	use RC\View;
	use RC\Count;
	use RC\Container;
	use RC\Controller;
	use RC\Middleware;
	use RC\Request;
	use RC\Response;
	use RC\Route;
	use RC\Worker;
	class Rcmaker{
		public static function start(){
			static $requests;
			static::prepareBinaryCli();
			\rc_apply_memory_limit();
			if(static::shouldStartInteractive()){
				exit(\RC\Cli\Interactive::run());
			}
			if(\RC\Cli\WindowsRuntime::shouldHandle()){
				exit(\RC\Cli\WindowsRuntime::run());
			}
			if(defined('IS_SCRIPT')){
			    $id = 999999;
		        $requests[$id] = $requests[$id] ?? new Request($id);
			    foreach ((Config::get('autoload') ?? []) as $file) {
			        include_once $file;
			    }
			    foreach ((Config::get('bootstrap') ?? []) as $class_name) {
					$class_name::start($requests[$id]);
				}
				return null;
			}
			if(IS_CLI){
				Worker::load();
				return null;
			}
			
			if(!RUN_PATH){
				return null;
			}
			\error_reporting(Config::get('app','error_types') ?? E_ALL &~E_NOTICE &~E_DEPRECATED);
			\set_error_handler(function ($level, $message, $file = '', $line = 0) {
		        if (\error_reporting() & $level) {
		            throw new \ErrorException($message, 0, $level, $file, $line);
		        }
		        return false;
		    });
			Request::configureTrustedProxies(Config::get('app', 'trusted_proxies') ?? [],
				Config::get('app', 'trusted_proxy_header') ?? 'x-forwarded-for');
		    $id = 999999;
		    $requests[$id] = $requests[$id] ?? new Request($id);
		    $responses[$id] = $responses[$id] ?? new Response($id);
			try {
				$requests[$id]->set(null, $id, $responses[$id]);
				$responses[$id]->set(null, $id, $requests[$id]);
				foreach ((Config::get('autoload') ?? []) as $file) {
			        include_once $file;
			    }
			    foreach ((Config::get('bootstrap') ?? []) as $class_name) {
					$class_name::start($requests[$id]);
				}
				Middleware::load(Config::get('middleware',null,true) ?? []);
				if(Config::get('app','with_custom_route')===true){
					Route::init();
				}
				if ($timezone = Config::get('app','default_timezone')) {
				    \date_default_timezone_set($timezone);
				}
				if(Config::get('app','count')===true){
					Stopwatch::$_framework = stopwatch('__frame__');
				}

				Controller::call($requests[$id],$responses[$id]);

			} catch (\Throwable $e) {
				$e = $requests[$id]->prepareErrorResponse($e);
	        	$render = Controller::exceptionResponse($e,$requests[$id],$responses[$id]);
	        	if(is_array($render)){
	        		list($code,$headers,$message) = $render;
					$responses[$id]->bad($requests[$id],$code,$message,$headers);
	        	}else{
	        		$responses[$id]->bad($requests[$id],500,$e->getMessage());
	        	}
	        } finally {
				$requests[$id]->unset($id);
				$responses[$id]->unset($id);
			}
		}

		private static function shouldStartInteractive(){
			if(!IS_CLI){
				return false;
			}
			global $argv;
			if(strtolower(trim((string)($argv[1] ?? ''))) !== 'interact'){
				return false;
			}
			$entry = strtolower(basename(str_replace('\\', '/', (string)($argv[0] ?? ''))));
			return in_array($entry, ['index.php', 'windows.php'], true) || PHP_SAPI === 'micro';
		}

		/**
		 * Prepares the standalone Micro executable before command dispatch.
		 *
		 * A binary launched without arguments behaves like `start`. On Windows,
		 * the process codepage is switched to UTF-8 before any Banner is printed.
		 */
		private static function prepareBinaryCli(){
			if(!IS_CLI || PHP_SAPI !== 'micro'){
				return;
			}
			if(PHP_OS_FAMILY === 'Windows' && function_exists('sapi_windows_cp_set')){
				@\sapi_windows_cp_set(65001);
			}

			global $argv, $argc;
			if(!static::shouldDefaultBinaryToStart(PHP_SAPI, $argv[1] ?? '')){
				return;
			}
			if(!is_array($argv)){
				$argv = [$_SERVER['SCRIPT_FILENAME'] ?? 'rcmaker'];
			}
			$argv[1] = 'start';
			$argc = count($argv);
			$_SERVER['argv'] = $argv;
			$_SERVER['argc'] = $argc;
		}

		/**
		 * Returns whether a CLI invocation needs the standalone default command.
		 */
		private static function shouldDefaultBinaryToStart($sapi, $command){
			return $sapi === 'micro' && trim((string)$command) === '';
		}

	}
?>
