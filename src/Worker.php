<?php
namespace RC;
use RC\Config;
use RC\Container;
use RC\Controller;
use RC\Response;
use RC\Request;
use RC\Middleware;
use RC\ExceptionHandler;
use Workerman\Worker as Workerman;
use Workerman\Timer;
use Workerman\Protocols\Http;
use Workerman\Connection\TcpConnection;
use RC\Helper\GlobalData\Server as GlobalDataServer;
use RC\Stopwatch;
use RC\Cli\Banner;
class Worker{
	protected static $_frame = null;
	protected static $_worker = null;
	protected static $_config = null;
	protected static $_requests = [];
	protected static $_responses = [];
	protected static $_gracefulStopTimer = null;
	protected static $_maxRequestCount = 1000000;
	protected static $_activeRequestCount = 0;
	protected static $_restartPending = false;
	protected static $_pid = null;
	protected static $_count = 0;
	public static $_swoole_table = null;
	public static $_workid = null;
	protected static $config_ssl_map = [
		'local_cert'=>'ssl_cert_file',
		'local_pk'=>'ssl_key_file',
		'verify_peer'=>'ssl_verify_peer',
		'allow_self_signed'=>'ssl_allow_self_signed',
		'cafile'=>'ssl_client_cert_file',
		'disable_compression'=>'ssl_compress',
		'verify_depth'=>'ssl_verify_depth',
		'ciphers'=>'ssl_ciphers'
	];
	protected static $config_exclude = [
	    'listen',
	    'port',
	    'coroutine',
	    'run_model',
	    'transport',
	    'table_size',
	    'protocol',
	    'reloadable',
	    'name',
	    'ssl',
	    'context',
	    'enable_static_file',
	    'enable_static_php',
	    'handler',
	    'bootstrap',
	    'constructor',
	    'autoload',
	    'default_timezone',
	    'type',
	    'memory_limit',
	    'trusted_proxies',
	    'trusted_proxy_header',
	    'default_app'
	];

	public static function getWorker(){
		return static::$_worker;
	}

	public static function isAppProcessConfig($config):bool{
		return is_array($config) && strtolower(trim((string)($config['type'] ?? ''))) === 'app';
	}

	private static function hasAppProcess($processConfig):bool{
		if(!is_array($processConfig)){
			return false;
		}
		foreach($processConfig as $config){
			if(static::isAppProcessConfig($config)){
				return true;
			}
		}
		return false;
	}

	public static function mergeAppProcessConfig($frame, array $processConfig = [], $reload = false):array{
		$configName = $frame === 'swoole' ? 'swoole' : 'worker';
		if($reload){
			Config::get('app', null, true);
		}
		$baseConfig = Config::get($configName, null, (bool)$reload) ?: [];
		$overrides = $processConfig;
		unset($overrides['type'], $overrides['handler']);
		return array_replace($baseConfig, $overrides);
	}

	private static function bootApplicationRuntime($worker, array $runtimeConfig, array $processConfig = [], $processName = null):void{
		static::$_worker = $worker;
		Config::get('app', null, true);
		Controller::setAppProcess($processName, $processConfig['default_app'] ?? null);
		Request::configureTrustedProxies(
			$processConfig['trusted_proxies'] ?? Config::get('app', 'trusted_proxies') ?? [],
			$processConfig['trusted_proxy_header'] ?? Config::get('app', 'trusted_proxy_header') ?? 'x-forwarded-for'
		);
		$errorTypes = Config::get('app','error_types') ?? E_ALL &~E_NOTICE &~E_DEPRECATED;
		\error_reporting($errorTypes);
		$loopPolicyApplied = false;
		$errorPolicyTimer = null;
		if(self::$_frame === 'workerman' && \Fiber::getCurrent() !== null && Workerman::$globalEvent !== null){
			// Workerman 5.2 starts workers in a Fiber; its error level does not propagate back to the event loop.
			$errorPolicyTimer = Timer::add(0, static function() use (&$errorTypes, &$loopPolicyApplied):void{
				\error_reporting($errorTypes);
				$loopPolicyApplied = true;
			}, [], false);
		}
		\set_error_handler(function ($level, $message, $file = '', $line = 0) {
			if (\error_reporting() & $level) {
				throw new \ErrorException($message, 0, $level, $file, $line);
			}
			return false;
		});
		if(isset($processConfig['memory_limit'])){
			\ini_set('memory_limit', (string)$processConfig['memory_limit']);
		}

		$autoloadFiles = array_values(array_unique(array_merge(
			Config::get('autoload') ?? [],
			$processConfig['autoload'] ?? []
		)));
		foreach($autoloadFiles as $file){
			include_once $file;
		}

		$bootstraps = array_values(array_unique(array_merge(
			Config::get('bootstrap') ?? [],
			$processConfig['bootstrap'] ?? []
		)));
		foreach($bootstraps as $className){
			$className::start($worker);
		}

		if($timezone = $processConfig['default_timezone'] ?? Config::get('app','default_timezone')){
			\date_default_timezone_set($timezone);
		}
		Middleware::load(Config::get('middleware', null, true) ?? []);
		static::$_maxRequestCount = max(1, (int)($runtimeConfig['max_request'] ?? static::$_maxRequestCount));
		if(Config::get('app','count') !== true){
			Stopwatch::$_framework = null;
		}
		$errorTypes = \error_reporting();
		if($errorPolicyTimer !== null){
			$onConnect = $worker->onConnect;
			$worker->onConnect = static function($connection) use ($worker, $onConnect, &$errorTypes, &$loopPolicyApplied, $errorPolicyTimer):void{
				// A first accept may run before the zero-delay timer. Remove this wrapper after that one accept.
				if(!$loopPolicyApplied){
					Timer::del($errorPolicyTimer);
					\error_reporting($errorTypes);
					$loopPolicyApplied = true;
				}
				$worker->onConnect = $onConnect;
				if($onConnect !== null){ $onConnect($connection); }
			};
		}
	}

	public static function configureWorkermanAppWorker($worker, array $processConfig = [], $processName = null):void{
		$runtimeConfig = static::mergeAppProcessConfig('workerman', $processConfig);
		$worker->onWorkerStart = function($worker) use (&$runtimeConfig, $processConfig, $processName){
			$runtimeConfig = static::mergeAppProcessConfig('workerman', $processConfig, true);
			$maxPackageSize = $runtimeConfig['max_package_size'] ?? 10 * 1024 * 1024;
			if ((!is_int($maxPackageSize) && !is_string($maxPackageSize))
				|| filter_var($maxPackageSize, FILTER_VALIDATE_INT) === false || (int) $maxPackageSize < 1) {
				throw new \InvalidArgumentException('APP max_package_size must be a positive integer byte count.');
			}
			TcpConnection::$defaultMaxPackageSize = (int) $maxPackageSize;
			Http::requestClass(\RC\Http\Workerman\Request::class);
			static::bootApplicationRuntime($worker, $runtimeConfig, $processConfig, $processName);
			\register_shutdown_function(function ($startTime) {
				if(\time() - $startTime <= 1){
					\sleep(1);
				}
			}, \time());
			$worker->onMessage = function($connection, $request) use (&$runtimeConfig) {
				static::onMessage($connection, $request, $runtimeConfig);
			};
			$onClose = $worker->onClose;
			$worker->onClose = function($connection) use ($onClose) {
				static::onClose($connection);
				if($onClose){
					\call_user_func($onClose, $connection);
				}
			};
			\rc_bind_queue_shutdown($worker);
		};
	}

	private static function ensureDirectory($dir){
		if($dir && !is_dir($dir)){
			mkdir($dir, 0755, true);
		}
	}

	private static function ensureConfigFileDirs($config, $keys){
		foreach($keys as $key){
			if(!empty($config[$key])){
				static::ensureDirectory(dirname($config[$key]));
			}
		}
	}

	private static function loadProcessClass($handler){
		if(!is_string($handler) || $handler === ''){
			return null;
		}
		if(class_exists($handler)){
			return $handler;
		}
		if(strpos($handler, '\\') !== false || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $handler)){
			return null;
		}
		$class_file = BASE_PATH.'/support/process/'.$handler.'.php';
		$class = "support\\process\\".$handler;
		return Container::loadClass($class_file,$class) ? $class : null;
	}

	private static function parseListen($listen){
		$parselisten = explode('://', (string)$listen, 2);
		if(count($parselisten) !== 2 || $parselisten[1] === ''){
			return null;
		}
		$scheme = strtolower($parselisten[0]);
		$target = $parselisten[1];
		if($scheme === 'unix'){
			return [$scheme, $target, 0];
		}
		$pos = strrpos($target, ':');
		if($pos === false){
			return null;
		}
		$address = substr($target, 0, $pos);
		$port = (int)substr($target, $pos + 1);
		if($address === '' || $port <= 0){
			return null;
		}
		return [$scheme, trim($address, '[]'), $port];
	}

	private static function swooleConfigKey($key){
		if($key === 'count'){
			return 'worker_num';
		}
		if($key === 'reusePort'){
			return 'enable_reuse_port';
		}
		return $key;
	}

	private static function shouldWarmupStaticPreload($start_app){
		if(!$start_app){
			return false;
		}
		global $argv;
		if(!isset($argv[1])){
			return false;
		}
		$command = strtolower(trim((string)$argv[1]));
		return in_array($command, ['start', 'restart'], true);
	}

	private static function shouldPrintCustomCliBanner(){
		if(!Banner::enabled()){
			return false;
		}
		global $argv;
		if(!isset($argv[1])){
			return false;
		}
		$command = strtolower(trim((string)$argv[1]));
		return in_array($command, ['start', 'restart'], true);
	}

	private static function resolveWorkermanEventLoopClass(){
		if(Workerman::$eventLoopClass){
			return Workerman::$eventLoopClass;
		}
		if(extension_loaded('event')){
			return '\\Workerman\\Events\\Event';
		}
		if(extension_loaded('libevent')){
			return '\\Workerman\\Events\\Libevent';
		}
		return '\\Workerman\\Events\\Select';
	}

	private static function ensureWorkermanEventLoopClass(){
		Workerman::$eventLoopClass = static::resolveWorkermanEventLoopClass();
		return Workerman::$eventLoopClass;
	}

	private static function workermanEventLoopName(){
		return static::resolveWorkermanEventLoopClass();
	}

	private static function prepareWorkermanCliOutput($start_app, $workerConfig, $processConfig){
		if(!static::shouldPrintCustomCliBanner()){
			return;
		}
		global $argv;
		if(!in_array('-q', $argv, true)){
			$argv[] = '-q';
		}
		Banner::output([
			'runtime.name' => 'Workerman',
			'runtime.version' => Workerman::VERSION,
			'event_loop' => static::workermanEventLoopName(),
		], Banner::workersFromConfig((bool)$start_app, (array)$workerConfig, (array)$processConfig));
	}

	private static function prepareSwooleCliOutput($startApp, $runtimeConfig, $processConfig, $poolProcesses = []){
		if(!static::shouldPrintCustomCliBanner()){
			return;
		}
		$workers = $poolProcesses
			? Banner::workersFromSwoolePool((array)$poolProcesses)
			: Banner::workersFromConfig((bool)$startApp, (array)$runtimeConfig, (array)$processConfig, 'Swoole');
		Banner::output([
			'runtime.name' => 'Swoole',
			'runtime.version' => function_exists('swoole_version') ? swoole_version() : (defined('SWOOLE_VERSION') ? SWOOLE_VERSION : 'unknown'),
			'event_loop' => !empty($runtimeConfig['coroutine']) ? 'Swoole Coroutine' : 'Swoole',
		], $workers);
	}

	
	/** Stop this runtime's master, e.g. stopMaster() from a managed APP; never trust a PID file or arbitrary parent. */
	public static function stopMaster(){
		if(!isset(static::$_worker)){
			return null;
		}
		if(PHP_OS_FAMILY === 'Windows' || !\function_exists('posix_kill') || !\defined('SIGTERM')){
			throw new \LogicException('stopMaster() requires POSIX signals; on Windows stop the supervisor with Ctrl+C.');
		}
		$masterPid = match(self::$_frame){
			'workerman' => \RC\Runtime\WorkermanLifecycle::getMasterPid(),
			'swoole' => static::$_worker->master_pid ?? null,
			default => throw new \LogicException('Cannot identify the active worker runtime; no signal was sent.'),
		};
		if(!is_int($masterPid) || $masterPid < 1){
			throw new \RuntimeException('Cannot identify the active master PID; no signal was sent.');
		}
		if(!\posix_kill($masterPid, \SIGTERM)){
			throw new \RuntimeException('Unable to signal the active master process; check process permissions.');
		}
		return null;
	}
	/** Retire only this APP child: stop accepting, drain received requests/streams and close idle keep-alive sockets. */
	private static function tryToGracefulExit():void{
		if(static::$_gracefulStopTimer !== null){ return; }
		echo 'Request Count >= ' . static::$_maxRequestCount . ' recycle scheduled' . PHP_EOL;
		// Preserve the original stagger so equally busy workers do not all stop listening at the same instant.
		static::$_gracefulStopTimer = Timer::add(rand(1, 10), static::beginRecycle(...), [], false);
	}

	/** Only retiring sockets get this wrapper; ordinary request dispatch has no extra state check. */
	private static function beginRecycle():void{
		static::$_worker->unlisten();
		foreach(static::$_worker->connections as $connection){
			$onMessage = $connection->onMessage;
			if(!is_callable($onMessage)){ continue; }
			$connection->onMessage = static function($connection, $request) use ($onMessage):void{
				// Wait until the last already-buffered request before announcing closure.
				if($connection->getRecvBufferQueueSize() === 0){
					$connection->headers['Connection'] = 'close';
				}
				$onMessage($connection, $request);
			};
		}
		static::$_gracefulStopTimer = Timer::add(0.05, static::drainForRecycle(...));
	}

	/** Run only during retirement; native close flushes buffered bytes after file producers and queued requests finish. */
	private static function drainForRecycle():void{
		if(static::$_activeRequestCount !== 0){ return; }
		foreach(static::$_worker->connections as $connection){
			if(!empty($connection->context->streamSending) || $connection->getRecvBufferQueueSize() !== 0){ continue; }
			$connection->close();
		}
		if(static::$_worker->connections === []){
			Timer::del(static::$_gracefulStopTimer);
			static::$_gracefulStopTimer = null;
			\RC\Runtime\WorkermanLifecycle::stopGracefully();
		}
	}
	private static function releaseConnection($id):void{
		if($id === null){
			return;
		}
		unset(static::$_requests[$id], static::$_responses[$id]);
	}
	public static function onClose($connection):void{
		$id = self::$_frame=='workerman' ? ($connection->id ?? null) : ($connection->fd ?? null);
		static::releaseConnection($id);
	}
	protected static function createRequestScope($id):array{
		if(self::$_frame === 'swoole'){
			return [new Request($id), new Response($id)];
		}
		return [
			static::$_requests[$id] ??= new Request($id),
			static::$_responses[$id] ??= new Response($id),
		];
	}
	protected static function finishRequest():void{
		static::$_activeRequestCount = max(0, static::$_activeRequestCount - 1);
		if(self::$_frame !== 'swoole' || !static::$_restartPending || static::$_activeRequestCount !== 0){
			return;
		}
		static::$_restartPending = false;
		if(is_object(static::$_worker) && method_exists(static::$_worker, 'shutdown')){
			$worker = static::$_worker;
			if(class_exists('\Swoole\Timer')){
				\Swoole\Timer::after(1, function() use ($worker){
					$worker->shutdown();
				});
				return;
			}
			$worker->shutdown();
		}
	}
	public static function onMessage($connection,$request,$config){
		static $request_count = 0;
		static::$_activeRequestCount++;
		if (++$request_count >= static::$_maxRequestCount) {
			if(self::$_frame=='workerman'){
	            static::tryToGracefulExit();
	        }
			if(self::$_frame=='swoole' && !empty($config['coroutine'])){
				static::$_restartPending = true;
			}
			$request_count=0;
        }
        $id = self::$_frame=='workerman' ? $connection->id : $connection->fd;
		list($RCrequest, $RCresponse) = static::createRequestScope($id);
        try {
			$RCrequest->set($request,$id,$RCresponse);
		   	$RCresponse->set($connection,$id,$RCrequest);
			Controller::call($RCrequest,$RCresponse,$config);
        } catch (\Throwable $e){
			$e = $RCrequest->prepareErrorResponse($e);
			$render = Controller::exceptionResponse($e,$RCrequest,$RCresponse);
			if(is_array($render)){
				list($code,$headers,$message) = $render;
				$RCresponse->bad($RCrequest,$code,$message,$headers);
			}else{
				$RCresponse->bad($RCrequest,500,$e->getMessage());
			}
		} finally {
			$RCrequest->unset($id);
			$RCresponse->unset($id);
			static::finishRequest();
        }
        return null;
    }

	public static function load($frame=null){
		$frame = Config::get('app','cli_frame');
		$start_app = Config::get('app','start_app');
		$_logDir = runtime_path() . '/logs';
		static::ensureDirectory($_logDir);
		if(!$frame){
			exit("\033[31;40mno cli mode frame setting!\033[0m\n");
		}
		self::$_frame = $frame;
		if($frame=='swoole'){
			if(!extension_loaded('swoole')){
				exit("\033[31;40mno swoole extension loaded!\033[0m\n");
			}
			cliCheck(['posix_kill','posix_getppid']);
			global $argv;
			$config = self::$_config = Config::get('swoole');
			static::$_maxRequestCount = $config['max_request'] ?? static::$_maxRequestCount;
			static::ensureConfigFileDirs($config, ['pid_file', 'log_file']);
			if(!isset($argv[1]) || ($argv[1]!=='start' && $argv[1]!=='stop' && $argv[1]!=='reload')){
				$usage = "Usage: php yourfile <command> [mode]\nCommands: \nstart\t\tStart worker in DEBUG mode.\n\t\tUse mode -d to start in DAEMON mode.\nstop\t\tStop worker.\nreload\t\tReload codes.\n";
				exit($usage);
			}else{
				$master_pid = \is_file($config['pid_file']) ? \file_get_contents($config['pid_file']) : 0;
				$mode = isset($argv[2]) ? trim($argv[2]) : '';
				if($argv[1]=='stop'){
					if($master_pid==0){
						exit("\033[31;40mno swoole pid file found!\033[0m\n");
						return;
					}
					$sig = \SIGTERM;
					\posix_kill($master_pid, $sig);
					return;
				}
				if($argv[1]=='reload'){
					if($master_pid==0){
						exit("\033[31;40mno swoole pid file found!\033[0m\n");
						return;
					}
					$sig = \SIGUSR1;
					if($config['coroutine']){
						$sig = \SIGUSR2;
					}
					\posix_kill($master_pid, $sig);
					return;
				}
				if($argv[1]=='start' && $mode=='-d'){
					$config['daemonize'] = true;
				}
			}

			if(!class_exists(\Swoole\Http\Server::class)){
				exit("\033[31;40mno swoole class!\033[0m\n");
			}
			if(!$config){
				exit("\033[31;40mno swoole config found!\033[0m\n");
			}

			if(!$config['coroutine']){
				$server = static::$_worker = $start_app ? new \Swoole\WebSocket\Server($config['listen'],$config['port'],$config['run_model'],$config['ssl'] ? SWOOLE_SOCK_TCP | SWOOLE_SSL : SWOOLE_SOCK_TCP) : new \Swoole\WebSocket\Server('127.0.0.1',0,SWOOLE_PROCESS, SWOOLE_SOCK_TCP);
				

				if(isset($config['context']['ssl']) && $config['context']['ssl'] && $start_app){
					$ssl = [];
					foreach($config['context']['ssl'] as $key=>$val){
						if(isset(static::$config_ssl_map[$key])){
							$ssl[static::$config_ssl_map[$key]] = $val;
						}else{
							$ssl[$key] = $val;
						}
					}
					if($ssl){
						$server->set($ssl);
					}
					
				}

				if($start_app){
					foreach($config as $key=>$conf){
					
						if(!in_array($key,static::$config_exclude)){
							if($key=='reactor_num' || $key=='worker_num'){
					    		$config[$key] = $config[$key] ?? cpu_count()*4;
					    	}
							$server->set([
							    $key => $config[$key]
							]);
						}
						
					}
				}else{
					$server->set([
					    'worker_num'=>1,
					]);
				}

				
				
				$processes = array();
				$servers = array();
				$process_config = Config::get('process', null, null, []);
				if(Config::get('app','cli_log') && ($start_app || static::hasAppProcess($process_config))){
					static::$_swoole_table = new \Swoole\Table($config['table_size']);
					static::$_swoole_table->column('app', \Swoole\Table::TYPE_STRING,30);
					static::$_swoole_table->column('path', \Swoole\Table::TYPE_STRING,250);
					static::$_swoole_table->column('ip', \Swoole\Table::TYPE_STRING,30);
					static::$_swoole_table->column('protocol', \Swoole\Table::TYPE_STRING,30);
					static::$_swoole_table->column('time', \Swoole\Table::TYPE_INT);
					static::$_swoole_table->column('referer', \Swoole\Table::TYPE_STRING,255);
					static::$_swoole_table->column('method', \Swoole\Table::TYPE_STRING,20);
					static::$_swoole_table->column('status', \Swoole\Table::TYPE_INT);
					static::$_swoole_table->column('agent', \Swoole\Table::TYPE_STRING,200);
					static::$_swoole_table->create();
					$process_config = array_merge($process_config,['RCmaker_logger'  => [
				       'handler'  => \RC\Helper\Process\Logger::class,
				       'count'  => 1,
				       'reusePort' => true,
				       'constructor'=>['table'=>static::$_swoole_table]
				    ]]);
				    
				}

				if(Config::get('queue','enable')){
				     $process_config = array_merge($process_config, Config::get('queue','consumer_process') ?? []);
			    }

				foreach ($process_config as $proc_name => $pconfig) {
					if(static::isAppProcessConfig($pconfig)){
						exit("\033[31;40mprocess error: app process {$proc_name} requires swoole.coroutine=true for independent workers!\033[0m\n");
					}
					if(!isset($pconfig['handler'])){
						exit("\033[31;40mprocess error: process handler not exists!\033[0m\n");
					}
					$class = static::loadProcessClass($pconfig['handler']);
					if($class === null){
						exit("\033[31;40mprocess error: class {$pconfig['handler']} not exists!\033[0m\n");
					}
					$workmode = null;
					if(!isset($pconfig['listen'])){
						$workmode = 'process';
					}else{
						$listenConfig = static::parseListen($pconfig['listen']);
						if($listenConfig === null){
							exit("\033[31;40mprocess error: listen {$pconfig['listen']} invalid!\033[0m\n");
						}
						list($scheme, $address, $port) = $listenConfig;
						$protocol = null;
						switch($scheme){
							case "websocket": $workmode='websocket'; $protocol='open_websocket_protocol'; break;
							case "http": case "https": $workmode='http'; $protocol=['open_http_protocol','open_http2_protocol']; break;
							case "mqtt": $workmode='mqtt'; $protocol='open_mqtt_protocol';  break;
							case "tcp": $workmode='tcp';  break;
							case "udp": $workmode='udp';  break;
							case "unix": $workmode='unix';  break;
							case "text": $workmode='text';  break;
						}
					}
					if($workmode){
						switch($workmode){
							case 'process':
								$process = new \Swoole\Process(function($process) use ($server,$pconfig,$class){
									$instance = Container::make($class, array_merge(['type'=>'swoole','worker'=>$process,'timer'=>\Swoole\Timer::class],$pconfig['constructor'] ?? []) ?? []);
									
								}, false, 0, true);
								
								foreach(($pconfig['bootstrap'] ?? []) as $bootstrap){
									$bootstrap::start();
								}
								foreach (($pconfig['autoload'] ?? []) as $file) {
							        include_once $file;
							    }
							    if ($timezone = $pconfig['default_timezone'] ?? Config::get('app','default_timezone')) {
								    \date_default_timezone_set($timezone);
								}
								$process->name($proc_name);
								$server->addProcess($process);
								$processes[] = [$proc_name,'process',$pconfig['bootstrap'] ?? []];
							break;
							case 'websocket':case 'http':case 'tcp':case 'udp': case 'https': case 'mqtt': case 'text':
								if(isset($pconfig['ssl'])){
									$transport = $pconfig['ssl'] ? SWOOLE_SOCK_TCP | SWOOLE_SSL : SWOOLE_SOCK_TCP;
								}else{
									$transport = SWOOLE_SOCK_TCP;
								}
								
								if($workmode=='udp'){
									$transport = SWOOLE_SOCK_UDP;
								}
								$servers[$proc_name] = $server->listen($address, $port, $transport);

								if(isset($pconfig['context']['ssl']) && $pconfig['context']['ssl']){
									$ssl = [];
									foreach($pconfig['context']['ssl'] as $key=>$val){
										if(isset(static::$config_ssl_map[$key])){
											$ssl[static::$config_ssl_map[$key]] = $val;
										}else{
											$ssl[$key] = $val;
										}
									}
									if($ssl){
				
										$servers[$proc_name]->set($ssl);
									}
									
								}
								foreach($pconfig as $key=>$conf){
									$orgikey = $key;
									$key = static::swooleConfigKey($key);
									if(!in_array($key,static::$config_exclude) && $key){
										if($key=='reactor_num' || $key=='worker_num'){
											
											$server->set([
											    $key => $start_app ? ($config[$key]+$pconfig[$orgikey]) : ($pconfig[$orgikey]+1) 
											]);
											
								    	}
										$servers[$proc_name]->set([
										    $key => $pconfig[$orgikey]
										]);
									}
								}

								if($workmode=='text'){;
									$servers[$proc_name]->set(array(
									    'open_eof_check' => true,
									    'package_eof' => "\r\n"
									  	
									));
								}

								if($protocol){
									if(is_array($protocol)){
										foreach($protocol as $p){
											$servers[$proc_name]->set([
											    $p => true
											]);
										}
									}else{
										$servers[$proc_name]->set([
										    $protocol => true
										]);
									}
									

									if($workmode=='websocket'){
										$servers[$proc_name]->set([
										    'open_http_protocol' => false,
										    'open_http2_protocol' => false,
										]);
									}
								}
								$instance = Container::make($class, array_merge(['type'=>'swoole','worker'=>$servers[$proc_name],'timer'=>\Swoole\Timer::class],$pconfig['constructor'] ?? []));
								worker_bind($servers[$proc_name], $instance,'swoole');
								$processes[] = [$proc_name,$pconfig['listen'],$pconfig['bootstrap'] ?? []];
								
							break;
						}
					}
				}

				static::prepareSwooleCliOutput($start_app, $config, $process_config);

				$server->on('AfterReload', function () {
					//opcache_clean();
					
				});
				$server->on('BeforeReload', function () {
					
					if(Config::get('app','count')===true){
						Stopwatch::$_framework = null;
						Stopwatch::start('frame');  
					}
					
				});

				$server->on('start',function() use ($config,$process,$processes,$start_app){
					swoole_set_process_name($config['name']);
					if(!static::shouldPrintCustomCliBanner()){
						if($start_app){
							echo "Http(s) Server [".$config['name']."] [".$config['listen'].":".$config['port']."] Is Started\r\n";
						}
						foreach($processes as $process){
							echo 'process ['.$process[0].'] ['.$process[1].'] Is Started'."\r\n";
						}
					}
				});
				$server->on('WorkerStop',function($server,$workid){
					
				});

				$server->on('WorkerStart',function($server,$workid) use ($start_app,$processes,&$config){
					static::$_workid = $workid;
				    Config::get('app',null,true);
				    $config = Config::get('swoole',null,true);
					\error_reporting(Config::get('app','error_types') ?? E_ALL &~E_NOTICE &~E_DEPRECATED);
					\set_error_handler(function ($level, $message, $file = '', $line = 0) {
				        if (\error_reporting() & $level) {
				            throw new \ErrorException($message, 0, $level, $file, $line);
				        }
				        return false;
				    });
				    foreach ((Config::get('autoload') ?? []) as $file) {
				        include_once $file;
				    }
				    foreach ((Config::get('bootstrap') ?? []) as $class_name) {
				        $class_name::start($server);
				    }
				    $bootstraps = [];
				    foreach($processes as $process){
			    		foreach(($process[2] ?? []) as $bootstrap){
			    			if(!in_array($bootstrap,(Config::get('bootstrap') ?? []))){
			    				$bootstraps[$bootstrap] = 'beload';
			    			}
			    		}
				    }
				    foreach(($bootstraps ?? []) as $key=>$name){
				    	$key::start($server);
				    }
				    if($start_app){
				    	Middleware::load(Config::get('middleware',null,true) ?? []);
				    }
				    if ($timezone = Config::get('app','default_timezone')) {
					    \date_default_timezone_set($timezone);
					}
				    if(Config::get('app','count')!==true){
				    	Stopwatch::$_framework = null;
				    }
				});

				$server->on('Request', function ($request, $response) use ($config) {
					self::onMessage($response,$request,$config);
				});
				$server->on('Close', function ($server, $fd) {
					self::releaseConnection($fd);
				});
				$server->on('Message', function ($request, $response){
					return null;
				});
				$server->on('handshake', function ($request, $response) {
					$response->end();
	        		return false;
				});

				if(static::shouldWarmupStaticPreload($start_app || static::hasAppProcess($process_config))){
					Controller::warmupStaticPreload();
				}

				Stopwatch::$_framework = stopwatch('__frame__');
				$server->start();
			}else{
				//多进程管理模块
				$process_count = 0;
				$process = $start_app ? [
					['protocol'=>'http','name'=>$config['name'] ?? 'RC_Swoole','listen'=>$config['listen'],'port'=>$config['port'],'workers'=>$config['worker_num'],'ssl'=>$config['ssl'],'callback'=>function($server,$workid) use (&$config, &$process_count){
						$config = static::mergeAppProcessConfig('swoole', [], true);
						static::bootApplicationRuntime($server, $config);
						\register_shutdown_function(function ($start_time) {
					        if (time() - $start_time <= 1) {
					            sleep(1);
					        }
					    }, time());
						
						$server->set([
						    'open_http_protocol' => true,
						    'open_http2_protocol' => true
						]);

						if(isset($config['context']['ssl']) && $config['context']['ssl']){
							$ssl = [];
							foreach($config['context']['ssl'] as $key=>$val){
								if(isset(static::$config_ssl_map[$key])){
									$ssl[static::$config_ssl_map[$key]] = $val;
								}else{
									$ssl[$key] = $val;
								}
							}
							if($ssl){
								$server->set($ssl);
							}
						}
						foreach($config as $key=>$conf){
							if(!in_array($key,static::$config_exclude)){
								if($key=='reactor_num' || $key=='worker_num'){
						    		$config[$key] = $config[$key] ?? cpu_count()*4;
						    		$process_count+=$config[$key];
						    	}
								$server->set([
								    $key => $config[$key]
								]);
							}
						}
						$server->handle('/', function ($request, $response) use ($workid,$config) {
							self::onMessage($response,$request,$config);
					        
					    });
						return null;
					}],
				] : [];

				$processes = array();
				$servers = array();
				$process_config = Config::get('process', null, null, []);
				if(Config::get('app','cli_log') && ($start_app || static::hasAppProcess($process_config))){
					static::$_swoole_table = new \Swoole\Table($config['table_size']);
					static::$_swoole_table->column('app', \Swoole\Table::TYPE_STRING,30);
					static::$_swoole_table->column('path', \Swoole\Table::TYPE_STRING,250);
					static::$_swoole_table->column('ip', \Swoole\Table::TYPE_STRING,30);
					static::$_swoole_table->column('protocol', \Swoole\Table::TYPE_STRING,30);
					static::$_swoole_table->column('time', \Swoole\Table::TYPE_INT);
					static::$_swoole_table->column('referer', \Swoole\Table::TYPE_STRING,255);
					static::$_swoole_table->column('method', \Swoole\Table::TYPE_STRING,20);
					static::$_swoole_table->column('status', \Swoole\Table::TYPE_INT);
					static::$_swoole_table->column('agent', \Swoole\Table::TYPE_STRING,200);
					static::$_swoole_table->create();
					$process_config = array_merge($process_config,['RCmaker_logger'  => [
				       'handler'  => \RC\Helper\Process\Logger::class,
				       'count'  => 1,
				       'reusePort' => true,
				       'constructor'=>['table'=>static::$_swoole_table]
				    ]]);
				    
				}

				if(Config::get('queue','enable')){
				     $process_config = array_merge($process_config, Config::get('queue','consumer_process') ?? []);
			    }

				if(static::shouldWarmupStaticPreload($start_app || static::hasAppProcess($process_config))){
					Controller::warmupStaticPreload();
				}

	

				foreach ($process_config as $proc_name => $pconfig) {
					$isAppProcess = static::isAppProcessConfig($pconfig);
					if(!$isAppProcess && !isset($pconfig['handler'])){
						exit("\033[31;40mprocess error: process handler not exists!\033[0m\n");
					}
					$class = $isAppProcess ? null : static::loadProcessClass($pconfig['handler']);
					if(!$isAppProcess && $class === null){
						exit("\033[31;40mprocess error: class {$pconfig['handler']} not exists!\033[0m\n");
					}
					$workmode = null;
					if(!isset($pconfig['listen'])){
						$workmode = 'process';
					}else{
						$listenConfig = static::parseListen($pconfig['listen']);
						if($listenConfig === null){
							exit("\033[31;40mprocess error: listen {$pconfig['listen']} invalid!\033[0m\n");
						}
						list($scheme, $address, $port) = $listenConfig;
						if($isAppProcess && !in_array($scheme, ['http', 'https'], true)){
							exit("\033[31;40mprocess error: app process {$proc_name} only supports http or https listen!\033[0m\n");
						}
						$protocol = null;
						switch($scheme){
							case "websocket": $workmode='websocket';  break;
							case "http": case "https": $workmode='http'; break;
							case "mqtt": $workmode='mqtt';   break;
							case "tcp": $workmode='tcp';  break;
							case "udp": $workmode='udp';  break;
							case "unix": $workmode='unix';  break;
							case 'text': $workmode='text';  break;
						}
					}
					if($workmode){
						switch($workmode){
							case 'process':
								$process[] = ['protocol'=>'process','name'=>$proc_name,'workers'=>$pconfig['count'] ?? 1,'callback'=>function($server,$workid) use ($pconfig,$class){
									foreach(($pconfig['bootstrap'] ?? []) as $bootstrap){
										$bootstrap::start();
									}
									foreach (($pconfig['autoload'] ?? []) as $file) {
								        include_once $file;
								    }
								    if ($timezone = $pconfig['default_timezone'] ?? Config::get('app','default_timezone')) {
									    \date_default_timezone_set($timezone);
									}
									$instance = Container::make($class, array_merge(['type'=>'swoole','worker'=>$server,'timer'=>\Swoole\Timer::class],$pconfig['constructor'] ?? []) ?? []);

								}];
							break;
							case 'websocket':case 'http': case 'tcp': case 'https': case 'text':
								$workerCount = $isAppProcess ? ($pconfig['count'] ?? $pconfig['worker_num'] ?? $config['worker_num'] ?? 1) : ($pconfig['count'] ?? 1);
								$process[] = ['protocol'=>$workmode,'name'=>$proc_name,'listen'=>$address,'port'=>$port,'workers'=>max(1, (int)$workerCount),'ssl'=>$pconfig['ssl'] ?? ($scheme === 'https'),'app'=>$isAppProcess,'callback'=>function($server,$workid) use ($pconfig,$class,$workmode,$isAppProcess,$proc_name){
									$serverConfig = $isAppProcess ? static::mergeAppProcessConfig('swoole', $pconfig, true) : $pconfig;
									if(isset($serverConfig['context']['ssl']) && $serverConfig['context']['ssl']){
										$ssl = [];
										foreach($serverConfig['context']['ssl'] as $key=>$val){
											if(isset(static::$config_ssl_map[$key])){
												$ssl[static::$config_ssl_map[$key]] = $val;
											}else{
												$ssl[$key] = $val;
											}
										}
										if($ssl){
											$server->set($ssl);
										}
									}

									


									foreach($serverConfig as $key=>$conf){
										$orgikey = $key;
										$key = static::swooleConfigKey($key);
										if(!in_array($key,static::$config_exclude) && $key){
											if($key!=='worker_num'){
												$server->set([
												    $key => $serverConfig[$orgikey]
												]);
											}
											
										}
									}

									if($workmode=='text'){;
										$server->set(array(
										    'open_eof_check' => true,
										    'package_eof' => "\r\n"
										  	
										));
									}


									if($isAppProcess){
										$server->set(['open_http_protocol'=>true, 'open_http2_protocol'=>true]);
										static::bootApplicationRuntime($server, $serverConfig, $pconfig, $proc_name);
										return function($request, $response) use ($serverConfig){
											static::onMessage($response, $request, $serverConfig);
										};
									}
									foreach(($pconfig['bootstrap'] ?? []) as $bootstrap){
										$bootstrap::start($server);
									}
									foreach (($pconfig['autoload'] ?? []) as $file) {
								        include_once $file;
								    }
									if ($timezone = $pconfig['default_timezone'] ?? Config::get('app','default_timezone')) {
									    \date_default_timezone_set($timezone);
									}
									$instance = Container::make($class, array_merge(['type'=>'swoole','worker'=>$server,'timer'=>\Swoole\Timer::class],$pconfig['constructor'] ?? []));
									return $instance;
								}];
								
							break;
						}
					}
				}




				static::prepareSwooleCliOutput($start_app, $config, $process_config, $process);

				$process_counts = array_sum(array_column($process,'workers'));
				if($process_counts <= 0){
					exit("\033[31;40mno swoole coroutine process found!\033[0m\n");
				}
				$processid = [];
				if($config['daemonize']===true){
					\Swoole\process::daemon();
				}
				$pool = new \Swoole\Process\Pool($process_counts);
				$pool->set(['enable_coroutine' => true]);
				for($i=0;$i<$process_counts;$i++){
					array_push($processid,$i);
				}

				foreach($process as $key=>$proc){
					$workers_num = $process[$key]['workers'];
					$process[$key]['workers_range'] = array_slice($processid, 0, $workers_num);
					
					
					if(!$process[$key]['workers_range']){
						echo 'worker Insufficient quantity '."\n";
						return null;
					}
					$processid = array_diff($processid,$process[$key]['workers_range']);

				}

				if($config['daemonize']===true){
					\Swoole\process::daemon(true,false);
				}
				$pool->on('Start', function ($server) use ($process,$config){
					if($config['daemonize']===true){
						\file_put_contents($config['pid_file'],$server->master_pid,LOCK_EX);
					}
					if(!static::shouldPrintCustomCliBanner()){
						foreach($process as $proc){
							echo ''.$proc['protocol'].' Coroutine Server ['.$proc['name'].'] processes ['.$proc['workers'].'] '.(isset($proc['listen']) ? '['.$proc['listen'].':'.$proc['port'].']' : '['.$proc['protocol'].']').' Is Started'."\r\n";
						}
					}
				});
				$pool->on('workerStart', function ($pool, $workid) use ($process,$config){
					static $workers;
					static::$_workid = $workid;
					foreach($process as $proc){
						switch($proc['protocol']){
							//process
							case 'process':
								if(in_array($workid,$proc['workers_range'])){
									$workers = $pool->getProcess($workid);
									$proc['callback']($workers,$workid);
									/*
									\Swoole\Process::signal(SIGTERM,function($sig) use (&$workers,$proc,$pool,$workid){
										$workers->exit();
									});
									*/
								}
							break;
							case 'tcp':case 'text':
								if(in_array($workid,$proc['workers_range'])){
									$workers = $workers ?? new \Swoole\Coroutine\Server($proc['listen'],$proc['port'],$proc['ssl'],true);
									$instance = $proc['callback']($workers,$workid);
									if($instance){
										$workers->handle(function (\Swoole\Coroutine\Server\Connection $conn) use ($workid,$instance,$proc) {
											$instance->handle($conn);
									    });
									}
									/*
								    \Swoole\Process::signal(SIGTERM,function($sig) use (&$workers){
										$workers->shutdown();
									});
									*/
									$workers->start();
								}
							break;
							//http
							case 'http': case 'https': case 'websocket':
								if(in_array($workid,$proc['workers_range'])){
									$workers = new \Swoole\Coroutine\Http\Server($proc['listen'],$proc['port'],$proc['ssl'],true);
									$instance = $proc['callback']($workers,$workid);
									if($instance){
										$workers->handle('/', function ($request, $response) use ($workid,$instance,$proc) {
											if($instance instanceof \Closure){
												$instance($request, $response);
												return;
											}
											$instance->handle($request, $response);
									    });
									}
									/*						
								    \Swoole\Process::signal(SIGTERM,function($sig) use (&$workers){
										$workers->shutdown();
									});
									*/
									$workers->start();
								}
							break;
						}
					}

					
				});

				$pool->on("WorkerStop", function ($pool, $workerId) {
				});
				
				$pool->start();
			}

			
			
		}
		if($frame=='workerman'){
			if(!class_exists(Workerman::class)){
				exit("\033[31;40mno workerman class!\033[0m\n");
			}
			cliCheck(['stream_socket_server','stream_socket_client','pcntl_signal_dispatch','pcntl_signal','pcntl_alarm','pcntl_fork','posix_getuid','posix_getpwuid','posix_kill','posix_setsid','posix_getpid','posix_getppid','posix_getpwnam','posix_getgrnam','posix_getgid','posix_setgid','posix_initgroups','posix_setuid','posix_isatty','pcntl_wait']);

			$config = self::$_config = Config::get('worker');
			if(!$config){
				exit("\033[31;40mno workerman config found!\033[0m\n");
			}
			$display_worker_config = $config;
			static::$_maxRequestCount = $config['max_request'] ?? static::$_maxRequestCount;
			static::ensureConfigFileDirs($config, ['pid_file', 'log_file', 'status_file', 'stdout_file']);
			Workerman::$pidFile                      = $config['pid_file'];
			Workerman::$stdoutFile                   = $config['stdout_file'];
			Workerman::$logFile                      = $config['log_file'];
			if(isset($config['status_file']) && property_exists(Workerman::class, 'statusFile')){
				Workerman::$statusFile                   = $config['status_file'];
			}
			TcpConnection::$defaultMaxPackageSize = $config['max_package_size'] ?? 10*1024*1024;


			$worker = $start_app ? static::$_worker = new Workerman($config['listen'], $config['context'] ?? []) : null;
			if($start_app){
				$property_map = [
				    'name',
				    'count',
				    'user',
				    'group',
				    'reusePort',
				    'transport',
				];
				foreach ($property_map as $property) {
				    if (isset($config[$property])) {
				    	if($property=='count'){
				    		$config[$property] = $config[$property] ?? cpu_count();
				    	}
				        $worker->$property = $config[$property];
				    }
				}
				// Retain the historical default without overriding an explicit false.
				$worker->reusePort = $config['reusePort'] ?? true;
				$worker->onWorkerReload = function($worker){
					
				};
				static::configureWorkermanAppWorker($worker);
			}
			


			$process_config = Config::get('process', null, null, []);
			if(Config::get('app','cli_log')){
				$process_config = array_merge($process_config,['RCmaker_logger'  => [
			       'handler'  => \RC\Helper\Process\Logger::class,
			       'name'  => 'RCmaker_logger',
			       'listen'  => $config['logger_listen'],
			       'count'  => 1,
			       'reusePort' => true
			    ]]);
			}
			if(Config::get('queue','enable')){
				$process_config = array_merge($process_config, Config::get('queue','consumer_process') ?? []);
			}
			foreach ($process_config as $proc_name => $proc_config) {
				if(static::isAppProcessConfig($proc_config)){
					$proc_config['name'] = $proc_config['name'] ?? $proc_name;
					$appConfig = static::mergeAppProcessConfig('workerman', $proc_config);
					if(empty($appConfig['listen'])){
						exit("\033[31;40mprocess error: app process {$proc_name} listen not exists!\033[0m\n");
					}
					$processworker = new Workerman($appConfig['listen'], $appConfig['context'] ?? []);
					foreach(['name', 'count', 'user', 'group', 'reloadable', 'reusePort', 'transport', 'protocol'] as $property){
						if(isset($appConfig[$property])){
							$processworker->$property = $appConfig[$property];
						}
					}
					if(($appConfig['ssl'] ?? false) === true){
						$processworker->transport = 'ssl';
					}
					static::configureWorkermanAppWorker($processworker, $proc_config, $proc_name);
					continue;
				}
				if (isset($proc_config['handler'])) {
					$processworker = new Workerman($proc_config['listen'] ?? null, $proc_config['context'] ?? []);
					$property_map = [
				        'count',
				        'user',
				        'group',
				        'reloadable',
				        'reusePort',
				        'transport',
				        'protocol',
				    ];
				    $processworker->name = $proc_name;
				    foreach ($property_map as $property) {
				        if (isset($proc_config[$property])) {
				            $processworker->$property = $proc_config[$property];
				        }
				    }
				    if(isset($proc_config['ssl']) && $proc_config['ssl']===true){
				    	$processworker->transport = 'ssl';
				    }
				    if(!class_exists($proc_config['handler'])){
				    	$class_file = BASE_PATH.'/support/process/'.$proc_config['handler'].'.php';
					    $class = "support\\process\\".$proc_config['handler'];
					    if(!Container::loadClass($class_file,$class)){
			                exit("\033[31;40mprocess error: class {$class} not exists!\033[0m\n");
			    		}
				    }else{
				    	$class = $proc_config['handler'];
				    }
				   
				    $processworker->onWorkerStart = function ($processworker) use ($worker,$proc_config,$class) {
				    	foreach(($proc_config['bootstrap'] ?? []) as $bootstrap){
							$bootstrap::start();
						}
						foreach (($proc_config['autoload'] ?? []) as $file) {
					        include_once $file;
					    }
					    if ($timezone = $proc_config['default_timezone'] ?? Config::get('app','default_timezone')) {
						    \date_default_timezone_set($timezone);
						}
			    		$instance = Container::make($class, array_merge(['type'=>'workerman','worker'=>$processworker,'timer'=>Timer::class],$proc_config['constructor'] ?? []));
			    		worker_bind($processworker, $instance);
				    };
				}
			}
			$hasAppRuntime = $start_app || static::hasAppProcess($process_config);
			Workerman::$onMasterReload = static function() use ($hasAppRuntime):void{
				if(!$hasAppRuntime){ return; }
				try {
					Config::get('app', null, true);
					Controller::refreshStaticPreload();
				} catch (\Throwable $error) {
					// Native reload still proceeds; never fork new workers with a stale or partial snapshot.
					Controller::clearStaticPreload();
					Workerman::log('[static-preload] reload_failed reason=' . get_class($error) . ' fallback=filesystem');
				}
			};
			if(static::shouldWarmupStaticPreload($hasAppRuntime)){
				Controller::warmupStaticPreload();
			}
			Stopwatch::$_framework = stopwatch('__frame__');
			static::ensureWorkermanEventLoopClass();
			static::prepareWorkermanCliOutput($start_app, $display_worker_config, $process_config);
			\RC\Runtime\WorkermanLifecycle::runAll();
		}
	}
}
?>
