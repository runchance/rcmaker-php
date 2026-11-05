<?php
namespace RC;
use RC\Config;
use RC\Container;
use RC\Route;
use RC\Response;
use RC\Request;
use RC\Middleware;
use RC\Stopwatch;
use RC\Exception\ExceptionHandler;
use FastRoute\Dispatcher;
use RC\Http\Workerman\Response as ResponseObj;
// Keep newly added runtime helpers usable with an existing authoritative Composer class map.
require_once __DIR__ . '/StaticFile/Registry.php';
class Controller{
	private static $_config = [];
	private static $_pathparse = [];
	private static $_routeCacheLimit = 1024;
	private static $_callbacks = [];
	private static $_staticMemory = [];
	private static $_appProcess = null;
	private static $_processDefaultApp = null;
	private static $_controllerTargets = [];
    private static ?bool $nativeWireRouting = null;
	public static $_mb = [];

	public static function setAppProcess($processName = null, $defaultApp = null){
		$processName = trim((string)$processName);
		self::$_appProcess = $processName === '' ? null : $processName;
		$defaultApp = trim((string)$defaultApp);
		self::$_processDefaultApp = $defaultApp === '' ? null : self::normalizeAppName($defaultApp);
		if(self::$_processDefaultApp !== null){
			$appConfigs = Config::get('app', 'app') ?? [];
			$matchedConfig = null;
			foreach($appConfigs as $appName=>$appConfig){
				if(self::normalizeAppName($appName) === self::$_processDefaultApp){
					$matchedConfig = $appConfig;
					break;
				}
			}
			$bindProcess = is_array($matchedConfig) ? trim((string)($matchedConfig['bind_process'] ?? '')) : '';
			$bindProcess = $bindProcess === '' ? null : $bindProcess;
			$allowed = self::$_appProcess === null
				? ($matchedConfig === null || $bindProcess === null)
				: ($matchedConfig !== null && $bindProcess === self::$_appProcess);
			if(!$allowed){
				throw new \InvalidArgumentException('default_app ' . self::$_processDefaultApp . ' is not bound to app process ' . (self::$_appProcess ?? 'main'));
			}
		}
		self::$_config = [];
		self::$_pathparse = [];
		self::$_callbacks = [];
		self::$_controllerTargets = [];
        self::$nativeWireRouting = null;
		self::$_mb = [];
	}
	
	private static function normalizeHost($host){
		$host = strtolower(trim((string)$host));
		if(substr_count($host, ':') === 1){
			$host = explode(':', $host)[0];
		}
		return $host;
	}

	private static function normalizeAppName($app){
		$app = trim((string)$app, " /\\\t\n\r\0\x0B");
		$app = str_replace('/', '\\', $app);
		return $app === '' ? 'index' : $app;
	}

	private static function normalizeIndex($index){
		if(is_array($index)){
			$index = array_values($index);
			return [$index[0] ?? 'index', $index[1] ?? 'index'];
		}
		$index = trim((string)$index, " /\\\t\n\r\0\x0B");
		if($index === ''){
			return ['index', 'index'];
		}
		$index = str_replace('\\', '/', $index);
		$index = str_replace('@', '/', $index);
		$index = array_values(array_filter(explode('/', $index), 'strlen'));
		return [$index[0] ?? 'index', $index[1] ?? 'index'];
	}

	private static function isValidClassPart($name){
		return is_string($name) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1;
	}

	private static function isValidAppName($app){
		$app = self::normalizeAppName($app);
		foreach(explode('\\', $app) as $part){
			if(!self::isValidClassPart($part)){
				return false;
			}
		}
		return true;
	}

	private static function appToPath($app){
		return str_replace('\\', '/', self::normalizeAppName($app));
	}

	private static function matchPathApp($segments, $apps){
		$matchedApp = null;
		$matchedLength = 0;
		foreach($apps as $appname=>$appconf){
			if(!empty($appconf['domains'])){
				continue;
			}
			$appSegments = explode('/', self::appToPath($appname));
			$length = count($appSegments);
			if($length <= $matchedLength || $length > count($segments)){
				continue;
			}
			if(array_slice($segments, 0, $length) === $appSegments){
				$matchedApp = $appname;
				$matchedLength = $length;
			}
		}
		return [$matchedApp, $matchedLength];
	}

	private static function getAppDir($baseAppPath, $app){
		return $baseAppPath.'/'.self::appToPath($app);
	}

	private static function isAbsolutePath($path){
		if(!is_string($path) || $path === ''){
			return false;
		}
		if(self::isPharStaticPath($path)){
			return true;
		}
		if($path[0] === '/' || (IS_WIN && $path[0] === '\\')){
			return true;
		}
		return IS_WIN && isset($path[1]) && ctype_alpha($path[0]) && $path[1] === ':';
	}

	private static function resolveDocumentRoot($documentRoot){
		if(!is_string($documentRoot) || trim($documentRoot) === ''){
			return public_path();
		}
		if(self::isAbsolutePath($documentRoot)){
			return $documentRoot;
		}
		$publicRoot = public_path();
		$archive = self::isPharStaticPath($publicRoot);
		return rtrim($publicRoot, IS_WIN || $archive ? '/\\' : '/') . '/'
			. trim(IS_WIN || $archive ? str_replace('\\', '/', $documentRoot) : $documentRoot, '/');
	}

	private static function isPharStaticPath($path){
		return is_string($path) && stripos(str_replace('\\', '/', $path), 'phar://') === 0;
	}

	private static function normalizePharStaticPath($path){
		$path = str_replace('\\', '/', (string) $path);
		$relative = substr($path, strlen('phar://'));
		// Preserve the leading slash of an absolute Unix PHAR path.
		// 保留 Unix PHAR 绝对路径的前导斜杠。
		$isUnixAbsolute = str_starts_with($relative, '/');
		$segments = [];
		foreach (explode('/', $relative) as $segment) {
			if ($segment === '' || $segment === '.') {
				continue;
			}

			if ($segment === '..') {
				array_pop($segments);
				continue;
			}

			$segments[] = $segment;
		}
		return 'phar://'
			. ($isUnixAbsolute ? '/' : '')
			. implode('/', $segments);
	}

	private static function canonicalStaticPath($path){
		if(self::isPharStaticPath($path)){
			$path = self::normalizePharStaticPath($path);
			return file_exists($path) ? $path : false;
		}
		return realpath($path);
	}

	private static function isStaticGzipEnabled($config){
		return !array_key_exists('enable_static_gzip', $config) ? true : (bool)$config['enable_static_gzip'];
	}

	private static function isStaticPreloadEnabled($config){
		return !empty($config['enable_static_preload']);
	}

	private static function getStaticPreloadExtensions($config){
		$extensions = $config['static_preload_extensions'] ?? ['css', 'js', 'html', 'htm', 'json', 'svg', 'txt', 'xml'];
		if(is_string($extensions)){
			$extensions = explode(',', str_replace('，', ',', $extensions));
		}
		if(!is_array($extensions)){
			$extensions = ['css', 'js', 'html', 'htm', 'json', 'svg', 'txt', 'xml'];
		}
		$extensions = array_map(function ($extension) {
			return ltrim(strtolower(trim((string)$extension)), '.');
		}, $extensions);
		$extensions = array_values(array_filter(array_unique($extensions), 'strlen'));
		return $extensions ?: ['css', 'js', 'html', 'htm', 'json', 'svg', 'txt', 'xml'];
	}

	private static function getStaticPreloadTimeLimit($config){
		$limit = $config['static_preload_time_limit'] ?? 0.5;
		if(!is_numeric($limit)){
			return 0.5;
		}
		$limit = (float)$limit;
		return $limit < 0 ? 0.0 : $limit;
	}

	private static function clientAcceptsGzip($request){
		$explicit = null; $wildcard = 0.0;
		foreach (explode(',', (string) $request->header('accept-encoding', '')) as $part) {
			if (!preg_match('/\A\s*(gzip|\*)(?:\s*;\s*q\s*=\s*(0(?:\.[0-9]{0,3})?|1(?:\.0{0,3})?))?\s*\z/i', $part, $match)) { continue; }
			$quality = isset($match[2]) ? (float) $match[2] : 1.0;
			if (strtolower($match[1]) === 'gzip') { $explicit = $explicit === null ? $quality : min($explicit, $quality); }
			else { $wildcard = $quality; }
		}
		return ($explicit ?? $wildcard) > 0;
	}

	private static function isCompressibleStaticFile($file){
		$extension = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
		return in_array($extension, ['css', 'js', 'html', 'htm', 'json', 'svg', 'txt', 'xml'], true);
	}

	private static function getStaticContentType($file, $body = null){
		$extension = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
		$mimeMap = [
			'css' => 'text/css; charset=UTF-8',
			'js' => 'application/javascript; charset=UTF-8',
			'html' => 'text/html; charset=UTF-8',
			'htm' => 'text/html; charset=UTF-8',
			'json' => 'application/json; charset=UTF-8',
			'svg' => 'image/svg+xml',
			'txt' => 'text/plain; charset=UTF-8',
			'xml' => 'text/xml; charset=UTF-8',
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
            'webp' => 'image/webp', 'avif' => 'image/avif', 'ico' => 'image/x-icon',
            'woff' => 'font/woff', 'woff2' => 'font/woff2', 'pdf' => 'application/pdf',
		];
		if(isset($mimeMap[$extension])){
			return $mimeMap[$extension];
		}
        if (is_string($body)) {
            if (class_exists(\finfo::class)) {
                static $inspector; $inspector ??= new \finfo(FILEINFO_MIME_TYPE);
                return $inspector->buffer($body) ?: 'application/octet-stream';
            }
            return 'application/octet-stream';
        }
		if(function_exists('mime_content_type')){
			$mime = mime_content_type($file);
			if(is_string($mime) && $mime !== ''){
				return $mime;
			}
		}
		return 'application/octet-stream';
	}

	private static function shouldPreloadStaticFile($file, $config){
		$extension = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
		return in_array($extension, self::getStaticPreloadExtensions($config), true);
	}

	private static function buildStaticMemoryEntry($file, $config = []){
        if (isset($config['static_encryption']) && $config['static_encryption'] !== false && $config['static_encryption'] !== '') {
            $decoded = \RC\StaticFile\Registry::forConfig($config)->read($file);
            if ($decoded !== null) { return self::buildStaticEntry($file, $decoded, self::isStaticGzipEnabled($config)); }
        }
		$body = @file_get_contents($file);
		if(!is_string($body)){
			return null;
		}
        return self::buildStaticEntry($file, $body);
    }

    /** Build representation metadata from plaintext, shared by preload and authenticated live reads. */
    private static function buildStaticEntry($file, string $body, bool $compress = true){
		$mtime = @filemtime($file);
		if ($mtime === false) { return null; }
		$gzip = null;
		if($compress && self::isCompressibleStaticFile($file) && function_exists('gzencode')){
			$encoded = gzencode($body, 6);
			$gzip = is_string($encoded) ? $encoded : null;
		}
		return [
			'body' => $body,
			'mime' => self::getStaticContentType($file, $body),
			'mtime' => $mtime,
			'gzip' => $gzip,
		];
	}

	private static function staticPreloadAppName($appName){
		$appName = preg_replace('/[\x00-\x1F\x7F]+/', '', (string)$appName);
		$appName = preg_replace('/\s+/', '_', trim((string)$appName));
		return $appName === '' ? 'default' : substr($appName, 0, 100);
	}

	/** Build one root privately; a scan failure must not publish a partial cache. */
	private static function preloadStaticDirectory($documentRoot, $config, $appName = 'default'){
		$appName = self::staticPreloadAppName($appName);
		$entries = [];
		$started = microtime(true);
		$timeLimit = self::getStaticPreloadTimeLimit($config);
		echo '[static-preload] start app=' . $appName . ' limit=' . $timeLimit . 's' . PHP_EOL;
		$loaded = 0;
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($documentRoot, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST
		);
		foreach($iterator as $item){
			if($timeLimit > 0 && (microtime(true) - $started) >= $timeLimit){
				echo '[static-preload] stop app=' . $appName . ' reason=time_limit loaded=' . $loaded . ' elapsed=' . round(microtime(true) - $started, 4) . 's' . PHP_EOL;
				break;
			}
			if(!$item->isFile()){
				continue;
			}
			$file = self::canonicalStaticPath($item->getPathname());
			if($file === false){
				continue;
			}
			if(!self::shouldPreloadStaticFile($file, $config)){
				continue;
			}
			$entry = self::buildStaticMemoryEntry($file, $config);
			if($entry !== null){
				$entries[$file] = $entry;
				$loaded++;
			}
		}
		echo '[static-preload] done app=' . $appName . ' loaded=' . $loaded . ' elapsed=' . round(microtime(true) - $started, 4) . 's' . PHP_EOL;
		return $entries;
	}

	private static function getPreloadedStaticEntry($config, $file){
		if(!self::isStaticPreloadEnabled($config)){
			return null;
		}
		$documentRoot = self::canonicalStaticPath(self::resolveDocumentRoot($config['document_root'] ?? ''));
		$file = self::canonicalStaticPath($file);
		if($documentRoot === false || $file === false){
			return null;
		}
		return self::$_staticMemory[self::staticMemoryKey($documentRoot, $config)][$file] ?? null;
	}

    /** Different encryption policies sharing a document root must never share decrypted cache entries. */
    private static function staticMemoryKey(string $root, array $config): string {
        $profile = $config['static_encryption'] ?? false;
        if ($profile === false || $profile === '') { return $root; }
        if (!is_string($profile)) { throw new \RuntimeException('static_encryption must be a profile name or false.'); }
        return $root . "\0" . $profile;
    }

	public static function warmupStaticPreload(){
		self::warmupStaticPreloadByProcess(null, false);
	}

	public static function warmupStaticPreloadForProcess($processName = null){
		$processName = trim((string)$processName);
		self::warmupStaticPreloadByProcess($processName === '' ? null : $processName, true);
	}

	/** Rebuild the global snapshot before Linux forks replacement workers; never called per request. */
	public static function refreshStaticPreload():void{
		clearstatcache(true);
        \RC\StaticFile\Registry::reset(true);
		self::warmupStaticPreloadByProcess(null, false, true);
	}

	/** Discard inherited bytes so replacement workers can safely fall back to ordinary static serving. */
	public static function clearStaticPreload():void{
		self::$_staticMemory = [];
	}

	private static function warmupStaticPreloadByProcess($processName, $processScoped, $replace = false){
		$conf = Config::get('app');
		if(!$conf){
			throw new \Exception('config error!');
		}
        $conf = \RC\StaticFile\Registry::apply($conf);
		$configs = [];
		if((!$processScoped || $processName === null) && self::isStaticPreloadEnabled($conf)){
			$configs[] = [
				'app' => $conf['default_app'] ?? 'index',
				'config' => $conf,
			];
		}
		foreach(($conf['app'] ?? []) as $appName => $appconf){
			if(!is_array($appconf)){
				continue;
			}
			$bindProcess = trim((string)($appconf['bind_process'] ?? ''));
			$bindProcess = $bindProcess === '' ? null : $bindProcess;
			if($processScoped && $bindProcess !== $processName){
				continue;
			}
			$merged = array_merge($conf, $appconf);
			if(self::isStaticPreloadEnabled($merged)){
				$configs[] = [
					'app' => $appName,
					'config' => $merged,
				];
			}
		}
		$snapshot = $replace ? [] : self::$_staticMemory;
		foreach($configs as $item){
			$config = $item['config'];
			$documentRoot = self::resolveDocumentRoot($config['document_root'] ?? '');
			$realRoot = self::canonicalStaticPath($documentRoot);
			if($realRoot === false){
				continue;
			}
            $memoryKey = self::staticMemoryKey($realRoot, $config);
            if (isset($snapshot[$memoryKey])) { continue; }
			$snapshot[$memoryKey] = self::preloadStaticDirectory($realRoot, $config, $item['app']);
		}
		self::$_staticMemory = $snapshot;
	}

	private static function memoryStaticResponse($request, $config, $file){
		$entry = self::getPreloadedStaticEntry($config, $file);
		if(!$entry){
			return null;
		}
        return self::staticEntryResponse($request, $config, $entry);
    }

    /** Produce original MIME/length/validators and optional gzip from an already authenticated representation. */
    private static function staticEntryResponse($request, $config, array $entry){
		$headers = [
			'Content-Type' => $entry['mime'],
		];
		if(self::isStaticGzipEnabled($config)) { $headers['Vary'] = 'Accept-Encoding'; }
		if(self::isStaticGzipEnabled($config) && self::clientAcceptsGzip($request) && !$request->header('range', '') && is_string($entry['gzip'])){
			$headers['Content-Encoding'] = 'gzip';
			$headers['Vary'] = 'Accept-Encoding';
			return (new ResponseObj($request, 200, $headers, $entry['gzip']))->withFileValidators($entry['mtime']);
		}
		return (new ResponseObj($request, 200, $headers, $entry['body']))->withFileValidators($entry['mtime']);
	}

	private static function gzipStaticResponse($request, $config, $file){
		if(self::isStaticPreloadEnabled($config)){
			return null;
		}
		if(!self::isStaticGzipEnabled($config) || !function_exists('gzencode')){
			return null;
		}
		if(!self::clientAcceptsGzip($request)){
			return null;
		}
		if($request->header('range', '')){
			return null;
		}
		if(!self::isCompressibleStaticFile($file)){
			return null;
		}
		$size = @filesize($file);
		if($size === false || $size < 256){
			return null;
		}
		$contents = @file_get_contents($file);
		if(!is_string($contents) || $contents === ''){
			return null;
		}
		$body = gzencode($contents, 6);
		if($body === false){
			return null;
		}
		$headers = [
			'Content-Type' => self::getStaticContentType($file),
			'Content-Encoding' => 'gzip',
			'Vary' => 'Accept-Encoding',
		];
		$mtime = filemtime($file);
		if ($mtime === false) { throw new \RuntimeException('Cannot inspect static file timestamp.'); }
		return (new ResponseObj($request, 200, $headers, $body))->withFileValidators($mtime);
	}

	private static function isPathInBase($path, $basePath){
		$path = self::canonicalStaticPath($path);
		$basePath = self::canonicalStaticPath($basePath);
		if($path === false || $basePath === false){
			return false;
		}
		$isPharPath = self::isPharStaticPath($path) || self::isPharStaticPath($basePath);
		if(IS_WIN && !$isPharPath){
			$path = strtolower($path);
			$basePath = strtolower($basePath);
		}
		$separator = $isPharPath ? '/' : DIRECTORY_SEPARATOR;
		return $path === $basePath || strpos($path, rtrim($basePath, IS_WIN || $isPharPath ? '/\\' : '/') . $separator) === 0;
	}

	private static function getAppNamespace($app){
		return self::normalizeAppName($app);
	}



	//调用控制器主方法
	public static function call($request,$response,$config = array()){
		self::$_mb = [];
        $native = $request::class === Request::class && (self::$nativeWireRouting ??= IS_CLI && Config::get('app', 'cli_frame') === 'workerman')
            ? $request->getRequest() : null;
        $native = $native !== null && $native::class === \RC\Http\Workerman\Request::class ? $native : null;
        if ($native !== null && $native->rcRoutingKey !== null) {
            $key = $native->rcRoutingKey;
        } else {
            $host = $request->host();
            $path = $request->path();
            $query = $request->queryString();
            $method = $request->method();
            $key = $native?->rcRoutingKey ?? $host . "\0" . $method . "\0" . $path . "\0" . ($query ?? '');
        }
		$cached = static::$_callbacks[$key] ?? null;
		if($cached !== null){
			self::$_mb = $cached[7] ?? [];
        	list($callback, $app, $controller, $action, $args, $count) = $cached;
			$controller_class = $cached[6] ?? null;
			if($app=='__static__' || isset($cached[8])){
        		$response->findStaticFile = true;
				$response->staticFile = $cached[8] ?? $controller;
        	}
        	if($count){
        		Stopwatch::start('__controller__');
			}
	        	$request->app = ['app'=>$app,'controller'=>$controller,'action'=>$action,'class'=>$controller_class];
        	$request->setGet($args);
            $response->response($app,$callback,$request);
            return null;
        }
		self::parseConfig();
		$path ??= $request->path();
		$parseUrl = self::parseUrl($key,$request);
		if(empty(self::$_mb['app_allowed'])){
			$response->bad($request,'404','unkown app!');
			return null;
		}
		$staticConfig = $config ?? [];
		foreach (['document_root', 'index_default', 'enable_static_file', 'enable_static_php', 'enable_static_gzip', 'enable_static_preload', 'static_preload_extensions', 'static_preload_time_limit', 'static_only', 'static_encryption'] as $staticKey) {
			if (array_key_exists($staticKey, self::$_mb)) {
				$staticConfig[$staticKey] = self::$_mb[$staticKey];
			}
		}
		if(!empty($staticConfig['static_only'])){
			$staticConfig['enable_static_php'] = false;
		}
		$with_custom_route = self::$_mb['with_custom_route'] && empty($staticConfig['static_only']);
		if($with_custom_route){
			if(self::$_mb['count']){
				Stopwatch::start('__controller__');
			}
			// Explicit routes own their paths, including paths that also exist under public/.
			if(self::routeMode($path,$key,$staticConfig,$request,$response)===null){
				return null;
			}
		}
		if(!empty($staticConfig['enable_static_file'])){
			if(self::staticMode(self::$_mb['static_path'] ?? $path, $key,$staticConfig,$request,$response)){
				return null;
			}
		}
		if(!empty($staticConfig['static_only'])){
			$response->bad($request,'404');
			return null;
		}
		
		if(self::$_mb['count'] && !$with_custom_route){
			Stopwatch::start('__controller__');
		}
		list($name,$appdir,$app,$appNamespace,$controller,$action,$args,$count) = [
			self::$_mb['name'],
			self::$_mb['appdir'],
			self::$_mb['app'],
			self::$_mb['app_namespace'],
			self::$_mb['controller'],
			self::$_mb['action'],
			self::$_mb['args'],
			self::$_mb['count']
		];

		if(!$app){
			$response->bad($request,'404','unkown app!');
			return null;
		}
		if(!$controller){
			$response->bad($request,'404','unkown controller class!');
			return null;
		}
		if(!$action){
			$action = 'index';
		}
		if(!self::isValidAppName($appNamespace) || !self::isValidClassPart($name) || !self::isValidClassPart($controller) || !self::isValidClassPart($action)){
			$response->bad($request,'404');
			return null;
		}
		if(!is_phar() && !self::isPathInBase($appdir, Config::get('app','apps_path') ?? BASE_PATH.'/apps')){
			$response->bad($request,'404');
			return null;
		}
		$controller_class = "".$name."\\$appNamespace\\controller\\$controller";
		$controller_class_file = $appdir.'/controller/'.$controller.'.php';
		$request->setGet($args);
		$request->app = ['app'=>$app,'controller'=>$controller,'action'=>$action,'class'=>$controller_class];
		if(!class_exists($controller_class)){
			if(Container::loadClass($controller_class_file,$controller_class)===false){
				if($with_custom_route && self::fallbackMode($key, $request, $response)){
					return null;
				}
				$response->bad($request,'404');
				return null;
			}
		}
		
		if(\is_callable([$instance = Container::get($controller_class), $action])){
			$callback = static::getCallback($app, [$instance, $action],$request,$args);
			self::cacheCallback($key, [$callback, $app, $controller, $action, $args, $count, $controller_class]);
			$response->response($app,$callback,$request);
			
		}else{
			if($with_custom_route && self::fallbackMode($key, $request, $response)){
				return null;
			}
			$response->bad($request,'404');
		}
		return null;

	}

    /** Fallback is application code too; apply policies without negatively caching future file/controller discovery. */
    private static function fallbackMode(string $key, $request, $response): bool
    {
        $fallback = Route::getFallback();
        if ($fallback === null) { return false; }
        $app = self::$_mb['app'];
        $callback = static::getCallback($app, $fallback, $request);
        $response->response($app, $callback, $request);
        return true;
    }

	/** Resolve configured app namespaces once per class, keeping only bounded class metadata. */
	protected static function parseController($controller_class){
		$controller_class = ltrim($controller_class, '\\');
		if(isset(self::$_controllerTargets[$controller_class])){
			return self::$_controllerTargets[$controller_class];
		}
		$parts = explode('\\', $controller_class);
		$controller = end($parts);
		$appRoot = trim((string)(Config::get('app', 'app_name') ?? 'app'), '\\') . '\\';
		$app = '';
		if(strncasecmp($controller_class, $appRoot, strlen($appRoot)) !== 0){
			$result = ['', $controller];
		}else{
			$relative = explode('\\', substr($controller_class, strlen($appRoot)));
			array_pop($relative);
			$namespace = implode('\\', $relative);
			$apps = self::$_config['app_bindings'] ?? [];
			$apps[self::$_config['default_app'] ?? 'index'] = null;
			foreach($apps as $name => $_){
				if(strlen($name) > strlen($app) && (strcasecmp($namespace, $name) === 0
					|| strncasecmp($namespace, $name . '\\', strlen($name) + 1) === 0)){
					$app = $name;
				}
			}
			if($app === '' && $relative){
				// Conventional unconfigured apps may have nested controller/model folders.
				$role = array_search('controller', $relative, true);
				if($role === false){ $role = array_search('model', $relative, true); }
				if($role !== false){ $relative = array_slice($relative, 0, $role); }
				elseif(count($relative) > 1){ array_pop($relative); }
				$app = implode('\\', $relative);
			}
			$result = [$app, $controller];
		}
		if(count(self::$_controllerTargets) < 4096){ self::$_controllerTargets[$controller_class] = $result; }
		return $result;
	}

	protected static function routeMode($path, $key, $config, $request,$response){
		static $routeInit;

		if($routeInit===null){
			Route::init();
			$routeInit = true;
		}
         
		$ret = self::matchAppRoute($request->method(), $path, self::$_mb['app'] ?? '');
		if(is_array($ret) && $ret[0] === Dispatcher::METHOD_NOT_ALLOWED){
			return self::methodNotAllowed($path, $key, $request, $response, $ret[1]);
		}
		if(is_array($ret) && $ret[0] === Dispatcher::FOUND) { //查找全局路由
			$app = self::$_mb['app'] ?? '';
			$controller = $action = '';
			$controllerClass = null;
			$staticString = is_string($ret[1]['callback']) && str_contains($ret[1]['callback'], '::');
			$handler = self::normalizeRouteHandler($ret[1]['callback']);
			$route = $ret[1]['route'];
            $route = clone $route;
			$args = !empty($ret[2]) ? $ret[2] : null;
			if (\is_array($handler)) {
				if(($handler[0] ?? null)==='__static__'){
				    if ((isset($config['enable_static_file']) && $config['enable_static_file']) || !IS_CLI) {
						if(self::staticMode($path, $key,$config,$request,$response,$handler[1],$route,$app)){
	    	        		return null;
	    	        	}
	    	        }
					$response->bad($request, 404);
					return null;
				}
			$handler = \array_values($handler);
			if(isset($handler[1])){
				$handlerClass = is_object($handler[0]) ? get_class($handler[0]) : $handler[0];
				if(is_string($handlerClass)){
					list($routeApp, $routeController) = self::parseController($handlerClass);
					if($routeApp !== '' && self::normalizeAppName($routeApp) !== self::normalizeAppName($app)){
						return self::methodNotAllowed($path, $key, $request, $response);
					}
					$controller = $routeController;
					$action = is_string($handler[1]) ? $handler[1] : '';
				}
			}
			if (isset($handler[1]) && \is_string($handler[0]) && \class_exists($handler[0]) && !is_callable($handler) && !$staticString) {
	                $handler = [Container::get($handler[0]), $handler[1]];
	            }
	        }

			if(is_callable($handler)){
				if (is_array($handler)) {
					$controllerClass = is_object($handler[0]) ? get_class($handler[0]) : (new \ReflectionClass($handler[0]))->getName();
				}
				$callback = static::getCallback($app, $handler, $request, $args, $route);
				self::cacheCallback($key, [$callback, $app, $controller, $action, $args, false, $controllerClass]);
				$request->setGet($args);
				$request->app = ['app'=>$app,'controller'=>$controller,'action'=>$action,'class'=>$controllerClass];
				$response->response($app,$callback,$request);
				return null;
			}
			throw new \InvalidArgumentException('Custom route handler must be callable or a static file mapping.');

		}
		return true;
	}

    /** Method discovery must not expose or reserve controller routes belonging to another application. */
    private static function routeHandlerMatchesApp($handler, $app): bool
    {
        $handler = self::normalizeRouteHandler($handler);
        if (!is_array($handler)) { return true; }
        $handler = array_values($handler);
        if (($handler[0] ?? null) === '__static__' || !isset($handler[1])) { return true; }
        $class = is_object($handler[0]) ? get_class($handler[0]) : $handler[0];
        if (!is_string($class)) { return true; }
        [$routeApp] = self::parseController($class);
        return $routeApp === '' || self::normalizeAppName($routeApp) === self::normalizeAppName($app);
    }

    /** Static callable strings follow the same app isolation and metadata rules as callable arrays. */
    private static function normalizeRouteHandler($handler)
    {
        if (is_string($handler) && str_contains($handler, '::')) { return explode('::', $handler, 2); }
        if (is_object($handler) && !$handler instanceof \Closure && is_callable($handler)) { return [$handler, '__invoke']; }
        return $handler;
    }

    /** A foreign explicit HEAD route must not shadow this app's normal GET-to-HEAD fallback. */
    private static function matchAppRoute($method, $path, $app)
    {
        $match = Route::dispatch($method, $path);
        if ($method === 'HEAD' && is_array($match) && $match[0] === Dispatcher::FOUND
            && !self::routeHandlerMatchesApp($match[1]['callback'], $app)) {
            $get = Route::dispatch('GET', $path);
            if (is_array($get) && $get[0] === Dispatcher::FOUND && self::routeHandlerMatchesApp($get[1]['callback'], $app)) { return $get; }
        }
        return $match;
    }

    /** Keep method denials out of MVC; unrelated foreign-app routes do not reserve the current app's paths. */
    private static function methodNotAllowed($path, $key, $request, $response, ?array $candidates = null)
    {
        $app = self::$_mb['app'] ?? '';
        if ($candidates === null) {
            // FastRoute has no enumeration API; an impossible HTTP token asks its own engine for allowed methods.
            $probe = Route::dispatch("\0", $path);
            if (!is_array($probe) || $probe[0] !== Dispatcher::METHOD_NOT_ALLOWED) { return true; }
            $candidates = $probe[1];
        }
        if (in_array('GET', $candidates, true) && !in_array('HEAD', $candidates, true)) { $candidates[] = 'HEAD'; }
        $allowed = [];
        foreach ($candidates as $method) {
            $match = self::matchAppRoute($method, $path, $app);
            if ($match[0] === Dispatcher::FOUND && self::routeHandlerMatchesApp($match[1]['callback'], $app)) { $allowed[] = $method; }
        }
        if (!$allowed) { return true; }
        $allow = implode(', ', array_unique($allowed));
        $callback = static::getCallback($app, static fn($request) => new ResponseObj($request, 405, ['Allow' => $allow], 'Method Not Allowed'), $request);
        self::cacheCallback($key, [$callback, $app, '', '', null, false, null]);
        $request->app = ['app'=>$app,'controller'=>'','action'=>'','class'=>null];
        $response->response($app, $callback, $request);
        return null;
    }

	protected static function execFile($file){
        $level = \ob_get_level();
        \ob_start();
        try {
            include $file;
            while (\ob_get_level() > $level + 1) { \ob_end_flush(); }
            if (\ob_get_level() !== $level + 1) { throw new \RuntimeException('Static PHP closed its output capture buffer.'); }
            return \ob_get_clean();
        } catch (\Throwable $error) {
            while (\ob_get_level() > $level) { \ob_end_clean(); }
            throw $error;
        }
	}

	private static function buildStaticResponse($request, $config, $file){
		if(($memoryResponse = self::memoryStaticResponse($request, $config, $file)) instanceof ResponseObj){
			return $memoryResponse;
		}
        if (isset($config['static_encryption']) && $config['static_encryption'] !== false && $config['static_encryption'] !== '') {
            $body = \RC\StaticFile\Registry::forConfig($config)->read($file);
            if ($body !== null) {
                $entry = self::buildStaticEntry($file, $body, self::isStaticGzipEnabled($config) && self::clientAcceptsGzip($request) && !$request->header('range', ''));
                if ($entry === null) { throw new \RuntimeException('Cannot inspect decrypted static representation.'); }
                return self::staticEntryResponse($request, $config, $entry);
            }
        }
		if(($gzipResponse = self::gzipStaticResponse($request, $config, $file)) instanceof ResponseObj){
			return $gzipResponse;
		}
		\clearstatcache(false, $file);
		if (!\is_file($file)) {
			return new ResponseObj($request, 404, [], '404 Not Found');
		}
		if(self::isPharStaticPath($file)){
			$body = @file_get_contents($file);
			if(!is_string($body)){
				return new ResponseObj($request, 404, [], '404 Not Found');
			}
			$headers = ['Content-Type' => self::getStaticContentType($file)];
			if (self::isStaticGzipEnabled($config)) { $headers['Vary'] = 'Accept-Encoding'; }
			$mtime = filemtime($file);
			if ($mtime === false) { throw new \RuntimeException('Cannot inspect static archive timestamp.'); }
			return (new ResponseObj($request, 200, $headers, $body))->withFileValidators($mtime);
		}
		$result = (new ResponseObj($request))->file($file);
		if (self::isStaticGzipEnabled($config)) { $result->header('Vary', 'Accept-Encoding'); }
		return $result;
	}

	protected static function staticMode($path, $key, $config, $request, $response, $file = null, $route = null, $app = '__static__'){
		
		$document_root = self::resolveDocumentRoot($config['document_root'] ?? '');
		$path = $path === '' ? '/' : $path;
		$separators = IS_WIN || self::isPharStaticPath($document_root) ? '/\\' : '/';
		$file = $file ?? rtrim($document_root, $separators) . $path;
		if (($file === false || !\is_file($file)) && ($path === '/' || substr($path, -1) === '/' || \is_dir($file))) {
			$indexDefault = ltrim((string) ($config['index_default'] ?? 'index.html'), $separators);
			$indexFile = rtrim($file, $separators) . '/' . $indexDefault;
			if (\is_file($indexFile)) {
				$file = $indexFile;
			}
		}
		if (false === $file || false === \is_file($file)) {
            return false;
        }
		if (!self::isPathInBase($file, $document_root)) {
            $response->bad($request,'400');
            return true;
        }
		$file = self::canonicalStaticPath($file);
		if($file === false){
			return false;
		}
        $ext = strtolower(\pathinfo($file, PATHINFO_EXTENSION));
	    if ($ext === 'php') {
            if (isset($config['static_encryption']) && $config['static_encryption'] !== false && $config['static_encryption'] !== '' && !\RC\StaticFile\Registry::staticPhpPassthrough($config)) { $response->bad($request, 403, 'Encrypted static applications do not execute PHP files.'); return true; }
			if(strcasecmp($path, '/index.php') === 0){
	    		return false;
	    	}
	    	if(!empty($config['enable_static_php'])){
                $call = function ($request) use ($file) {
		           return static::execFile($file);
		       };
			}else{
				return false;
	    	}
	    }else{
			$call = function ($request) use ($config, $file) {
				return self::buildStaticResponse($request, $config, $file);
            };
		}
		$callback = static::getCallback($app, $call, $request, null, $route, true);
        // Slot 8 preserves static transport metadata without putting a file path in controller/app identity.
        self::cacheCallback($key, [$callback, $app, '', null, null, false, null, null, $file]);
        $request->app = ['app'=>$app,'controller'=>'','action'=>null,'class'=>null];
        $response->findStaticFile = true;
        $response->staticFile = $file;
        $response->response($app,$callback,$request);
        return true;
	}

    protected static function getCallback($app, $call, $request, $args = null,$route = null, $static = false)
    {
        $args = $args === null ? null : \array_values($args);
        $middlewares = [];
        if ($route) {
            $route_middlewares = \array_reverse($route->getMiddleware());
            foreach ($route_middlewares as $class_name) {
                $middlewares[] = [Container::get($class_name), 'handle'];
            }
        }
        if (Middleware::hasMiddleware('__path__')) {
            $middlewares = \array_merge($middlewares, Middleware::getPathMiddleware($request->path()));
        }
        $middleware = \array_merge($middlewares, $static ? Middleware::getStaticMiddleware($app) : Middleware::getMiddleware($app));
        if ($middleware) {
        	$callback = array_reduce($middleware,function($carry, $item){
        		return function ($request) use ($carry, $item) {
                    return $item($request, $carry);
                };
        	},static::initial($call, $args));
        	return $callback;
        }else{
        	return static::initial($call, $args);

        }
    }

	protected static function initial($call, $args = null){
		if ($args === null) {
            $callback = $call;
        } else {
            $callback = function ($request) use ($call, $args) {
                return $call($request, ...$args);
            };
        }
        return $callback;
	}


	//解析config并设置系统参数
	/** A cached callback must carry its own application exception/debug context, not the preceding request's. */
	private static function cacheCallback(string $key, array $value): void {
		$value[7] = self::$_mb;
		self::cacheRoute(self::$_callbacks, $key, $value);
	}

	/** Bound cold-route insertions; cache hits do not count, iterate or evict entries. Zero explicitly disables caching. */
	private static function cacheRoute(array &$cache, string $key, array $value): void {
		if (self::$_routeCacheLimit === 0) { return; }
		if (!isset($cache[$key]) && count($cache) >= self::$_routeCacheLimit) { unset($cache[array_key_first($cache)]); }
		$cache[$key] = $value;
	}

	private static function parseConfig(){
		if(self::$_config){
			return true;
		}
		$conf = Config::get('app');
		if(!$conf){
			throw new \Exception('config error!');
		}
        $conf = \RC\StaticFile\Registry::apply($conf);
		$limit = $conf['route_cache_limit'] ?? 1024;
		if ((!is_int($limit) && !is_string($limit)) || filter_var($limit, FILTER_VALIDATE_INT) === false || (int) $limit < 0) {
			throw new \InvalidArgumentException('app.route_cache_limit must be a nonnegative integer.');
		}
		self::$_routeCacheLimit = (int) $limit;
		self::$_config = [
			'default_timezone'=>$conf['default_timezone'] ?? 'Asia/Shanghai',
			'debug'=>$conf['debug'] ?? true,
			'error_msg'=>$conf['error_msg'] ?? 'page error!',
			'error_types'=>$conf['error_types'] ?? E_ALL &~E_NOTICE &~E_DEPRECATED,
			'index'=>self::normalizeIndex($conf['index'] ?? 'index/index'),
			'index_default'=>$conf['index_default'] ?? 'index.html',
			'route'=>$conf['route'] ?? true,
			'with_custom_route'=>$conf['with_custom_route'] ?? true,
			'document_root'=>$conf['document_root'] ?? null,
			'enable_static_file'=>$conf['enable_static_file'] ?? false,
			'enable_static_php'=>$conf['enable_static_php'] ?? false,
			'enable_static_gzip'=>$conf['enable_static_gzip'] ?? true,
			'enable_static_preload'=>$conf['enable_static_preload'] ?? false,
            'static_encryption'=>$conf['static_encryption'] ?? false,
			'static_preload_extensions'=>$conf['static_preload_extensions'] ?? ['css', 'js', 'html', 'htm', 'json', 'svg', 'txt', 'xml'],
			'static_preload_time_limit'=>$conf['static_preload_time_limit'] ?? 0.5,
			'static_only'=>$conf['static_only'] ?? false,
			'default_app'=>self::normalizeAppName($conf['default_app'] ?? 'index'),
			'appcount'=>0,
			'count'=>$conf['count'] ?? ['memory'=>true,'loadtime'=>true],
			'app'=>[],
			'domain_map'=>[],
			'foreign_apps'=>[],
			'foreign_domain_map'=>[],
			'app_bindings'=>[]
		];
		foreach(($conf['app'] ?? []) as $appname=>$appconf){
			$appname = self::normalizeAppName($appname);
			$bindProcess = trim((string)($appconf['bind_process'] ?? ''));
			$bindProcess = $bindProcess === '' ? null : $bindProcess;
			self::$_config['app_bindings'][$appname] = $bindProcess;
			if($bindProcess !== self::$_appProcess){
				self::$_config['foreign_apps'][$appname] = $appconf;
				foreach(($appconf['domains'] ?? []) as $domain){
					$domain = self::normalizeHost($domain);
					if($domain !== ''){
						self::$_config['foreign_domain_map'][$domain] = $appname;
					}
				}
				continue;
			}
			if(isset($appconf['index'])){
				$appconf['index'] = self::normalizeIndex($appconf['index']);
			}
			self::$_config['app'][$appname] = $appconf;
			foreach(($appconf['domains'] ?? []) as $domain){
				$domain = self::normalizeHost($domain);
				if($domain !== ''){
					self::$_config['domain_map'][$domain] = $appname;
				}
			}
		}
		self::$_config['appcount'] = count(self::$_config['app']);
		if(self::$_processDefaultApp !== null && self::isAppAllowed(self::$_processDefaultApp)){
			self::$_config['default_app'] = self::$_processDefaultApp;
		}elseif(!self::isAppAllowed(self::$_config['default_app']) && self::$_config['app']){
			self::$_config['default_app'] = array_key_first(self::$_config['app']);
		}
		return true;
	}

	private static function isAppAllowed($app){
		if(!is_string($app) || $app === ''){
			return false;
		}
		$app = self::normalizeAppName($app);
		if(array_key_exists($app, self::$_config['app_bindings'])){
			return self::$_config['app_bindings'][$app] === self::$_appProcess;
		}
		return self::$_appProcess === null;
	}

	public static function exceptionResponse(\Throwable $e,$request,$response){
		$members = self::$_mb;
		$app = $members['app'] ?? '';
		$members['debug'] = $members['debug'] ?? Config::get('app','debug');
    	$members['error_msg'] = $members['error_msg'] ?? Config::get('app','error_msg');
    	try{
    		$response->findStaticFile = false;
        	$response->staticFile = null;
			$config = Config::get('exception');
			$default = $config[''] ?? ExceptionHandler::class;
			$handler_class = $config[$app] ?? $default;
			if(is_array($handler_class)){
				$class_file = BASE_PATH.'/support/exception/'.$handler_class['handle'].'.php';
				$class = "support\\exception\\".$handler_class['handle'];
				if(!class_exists($class)){
					if(!Container::loadClass($class_file,$class)){
						return self::failedExceptionResponse($e, $members);
					}
				}
				$exception_handler = Container::make($class,[
					'logger'=>null,
					'debug'=>$members['debug'], 
					'error_msg'=>$members['error_msg']
				]);
			}else{
				$exception_handler = Container::make($handler_class,[
					'logger'=>null,
					'debug'=>$members['debug'],
					'error_msg'=>$members['error_msg']
				]);
			}
			$exception_handler->report($e);
			$render = $exception_handler->render($e,$request);
			if (!is_array($render) || !array_is_list($render) || count($render) !== 3) {
				throw new \UnexpectedValueException('Exception handlers must return [status, headers, body].');
			}
			$validated = new ResponseObj($request, $render[0], $render[1], $render[2]);
			return [$validated->getStatusCode(), $validated->getHeaders(), $validated->rawBody()];
    	}catch(\Throwable $e){
			return self::failedExceptionResponse($e, $members);
    	}
    }

    /** A broken reporter/renderer must not expose database paths, credentials or traces on a private application. */
    private static function failedExceptionResponse(\Throwable $error, array $members): array
    {
        $message = !empty($members['debug']) ? nl2br(htmlspecialchars((string) $error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
            : (is_string($members['error_msg'] ?? null) && $members['error_msg'] !== '' ? $members['error_msg'] : 'Internal Server Error');
        return [500, [], $message];
    }

	//解析URL地址并赋值GET
	private static function parseUrl($key,$request):bool{
		$path = $request->path();
		if(isset(self::$_pathparse[$key])){
			self::$_mb = self::$_pathparse[$key];
			return true;
		}
		if($path && $path[0] === '/'){
			$path = substr($path,1);
		}
		$explode = $path ? array_values(array_filter(explode("/",$path), 'strlen')) : array();
		$getParm = [];
		$host = self::normalizeHost($request->host(true));
		$app = $appInstance = self::normalizeAppName($request->get('a',self::$_config['default_app']));
		$config = [
			'route'=>self::$_config['route'],
			'with_custom_route'=>self::$_config['with_custom_route'],
			'index'=>self::$_config['index'],
			'debug'=>self::$_config['debug'],
			'error_msg'=>self::$_config['error_msg'],
			'count'=>self::$_config['count']
		];
		$controller = $controllerInstance = $request->get('c',$config['index'][0]);
		$action = $actionInstance = $request->get('m',$config['index'][1]);
		$appcount = self::$_config['appcount'];
		$apps = self::$_config['app'] ?? [];
		$domainMap = self::$_config['domain_map'] ?? [];
		$foreignApps = self::$_config['foreign_apps'] ?? [];
		$foreignDomainMap = self::$_config['foreign_domain_map'] ?? [];
		$domainMatched = false;
		$matchedApp = $domainMap[$host] ?? ($foreignDomainMap[$host] ?? null);
		$matchedLength = 0;
		$staticPath = $request->path();
		if($config['route']){
			if($matchedApp !== null){
				$domainMatched = true;
				$app = $matchedApp;
				$controller = $explode[0] ?? $controller;
				$action = $explode[1] ?? $action;
				$getParm = array_slice($explode, 2);
			}elseif($foreignApps && ($foreignPathApp = self::matchPathApp($explode, $foreignApps)[0]) !== null){
				$app = $foreignPathApp;
				$controller = $action = '';
			}elseif($appcount<=1){
				$controller = $explode[0] ?? $controller;
				$action = $explode[1] ?? $action;
				$getParm = array_slice($explode, 2);
			}else{
				list($matchedApp, $matchedLength) = self::matchPathApp($explode, $apps);
				if($matchedApp !== null){
					$app = $matchedApp;
					$staticPath = '/' . implode('/', array_slice($explode, $matchedLength));
					$controller = $explode[$matchedLength] ?? $controller;
					$action = $explode[$matchedLength + 1] ?? $action;
					$getParm = array_slice($explode, $matchedLength + 2);
				}else{
					$app = self::$_config['default_app'];
					$controller = $explode[0] ?? $controller;
					$action = $explode[1] ?? $action;
					$getParm = array_slice($explode, 2);
				}
			}
		}
		$baseAppName = Config::get('app','app_name') ?? 'app';
		$baseAppPath = Config::get('app','apps_path') ?? BASE_PATH.'/apps';
		$appdir = self::getAppDir($baseAppPath, $app);

		
		$config_map = [
			'route','with_custom_route','index','index_default','debug','error_msg','count','document_root','enable_static_file','enable_static_php','enable_static_gzip','enable_static_preload','static_preload_extensions','static_preload_time_limit','static_only','static_encryption'
		];
		$matchedApp = null;
		$matchedAppConf = null;
		if($apps){
			if(isset($domainMap[$host])){
				$matchedApp = $domainMap[$host];
				$matchedAppConf = $apps[$matchedApp] ?? [];
				$domainMatched = true;
			}

			if($matchedApp === null){
				if(isset($apps[$app])){
					if(!empty($apps[$app]['domains'])){
						$app = $controller = $action = '';
					}elseif(isset($apps[$app]['route']) && !$apps[$app]['route'] && $app !== $appInstance){
						$app = $controller = $action = '';
					}else{
						$matchedApp = $app;
						$matchedAppConf = $apps[$app];
					}
				}elseif(isset($apps[$appInstance]) && isset($apps[$appInstance]['route']) && !$apps[$appInstance]['route']){
					$matchedApp = $appInstance;
					$matchedAppConf = $apps[$appInstance];
					$app = $appInstance;
					$controller = $controllerInstance;
					$action = $actionInstance;
					$getParm = [];
				}
			}

			if($matchedApp !== null){
				foreach($config_map as $map){
					$config[$map] = $matchedAppConf[$map] ?? self::$_config[$map];
				}
				$app = $matchedApp;
				$appdir = self::getAppDir($baseAppPath, $app);
				if(isset($config['route']) && $config['route']===true){
					if($domainMatched){
						$controller = $explode[0] ?? ($config['index'][0] ?? $controller);
						$action = $explode[1] ?? ($config['index'][1] ?? $action);
						$getParm = array_slice($explode, 2);
					}else{
						$offset = $matchedLength > 0 ? $matchedLength : 0;
						$controller = $explode[$offset] ?? ($config['index'][0] ?? $controller);
						$action = $explode[$offset + 1] ?? ($config['index'][1] ?? $action);
						$getParm = array_slice($explode, $offset + 2);
					}
				}else{
					$app = $domainMatched ? $matchedApp : $appInstance;
					$controller = $controllerInstance;
					$action = $actionInstance;
					$appdir = self::getAppDir($baseAppPath, $app);
					$getParm = [];
				}
			}
		}
		$appAllowed = self::isAppAllowed($app);
		if(!$appAllowed){
			$app = $controller = $action = '';
			$config['enable_static_file'] = false;
			$config['with_custom_route'] = false;
		}
		$args = self::parseParams($getParm);
		self::$_mb = array_merge([
			'name'=>$baseAppName,
			'app'=>$app,
			'app_namespace'=>self::getAppNamespace($app),
			'controller'=>$controller,
			'action'=>$action,
			'appcount'=>$appcount,
			'appdir'=>$appdir,
			'args'=>$args,
			'app_allowed'=>$appAllowed,
			'static_path'=>$staticPath
		],$config);
		self::cacheRoute(self::$_pathparse, $key, self::$_mb);
		return true;
	}

	private static function parseParams($parms=null){
		$vars = null;
		if($parms){
			foreach($parms as $k=>$v){
				if($k%2==0){
					if(!empty($parms[$k])){
						$vars[$parms[$k]] = $parms[$k+1] ?? '';
					}
				}
			}
			return $vars;
		}
		return null;
	}
}
?>
