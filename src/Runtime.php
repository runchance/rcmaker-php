<?php
use RC\Rcmaker;
use RC\Response;
use RC\Controller;
use RC\Stopwatch;
use RC\Container;
use RC\Config;
use RC\Worker;
use RC\Request;
use RC\Helper\Captcha\CaptchaBuilder;
use RC\Helper\Captcha\PhraseBuilder;
use RC\Helper\Validator;
use RC\Helper\AutoForm;
use RC\Helper\Token;
use RC\Helper\Xlsx;
use RC\Helper\XlsxReader;
use RC\Helper\Pdf;
use RC\Helper\Sms;
use RC\Helper\Mailer;
use RC\Helper\QRcode;
use RC\Helper\Curl\Curl;
use RC\Helper\Curl\MultiCurl;
use RC\Helper\Queue\RedisQueue\Client as queueClient;
use RC\Http\Workerman\Response as ResponseObj;
use RC\Helper\Db as SimpleDb;

\RC\Project\Platform::check();

define('IS_CLI',PHP_SAPI=='cli' || PHP_SAPI=='micro' ? 1 : 0);
define('BASE_PATH', ROOT_PATH);
// __DIR__ is absolute and preserves the phar:// path in packaged applications.
define('FRAME_PATH', __DIR__);
define('IS_WIN',strstr(PHP_OS, 'WIN') ? 1 : 0);
define('VER','1.0');

function rc_response_header_value($value){
    return str_replace(["\r", "\n"], '', (string)$value);
}

function rc_response_json_body($data, $options = JSON_UNESCAPED_UNICODE){
    $json = json_encode($data, $options);
    if($json === false){
        $json = json_encode(['error'=>'json_encode_failed','message'=>json_last_error_msg()], JSON_UNESCAPED_UNICODE);
    }
    return $json === false ? '{"error":"json_encode_failed"}' : $json;
}

function response($request,$body = '', $status = 200, $headers = array()){
   $response = $request->RCresponse ?? null;
   if($response){
       $response->findStaticFile = false;
       $response->staticFile = null;
       $response->_status = $status;
   }
   return new ResponseObj($request, $status, $headers, $body);  
}



function json($request,$data, $options = JSON_UNESCAPED_UNICODE){
    return new ResponseObj($request, 200, ['Content-Type' => 'application/json'], rc_response_json_body($data, $options));
}

function xml($request,$xml){
    if ($xml instanceof SimpleXMLElement) {
        $xml = $xml->asXML();
    }
    return new ResponseObj($request, 200, ['Content-Type' => 'text/xml'], $xml);
}

/** Return a JSONP response: data is a PHP value, never pre-encoded JSON or executable JavaScript. */
function jsonp($request,$data, $callback_name = 'callback'){
    if(!is_string($callback_name) || !preg_match('/\A[A-Za-z_$][A-Za-z0-9_$]*(\.[A-Za-z_$][A-Za-z0-9_$]*)*\z/', $callback_name)){
        $callback_name = 'callback';
    }
    $data = rc_response_json_body($data);
    return new ResponseObj($request, 200, [
        'Content-Type' => 'application/javascript; charset=utf-8',
        'X-Content-Type-Options' => 'nosniff',
    ], "$callback_name($data)");
}

function redirect($request,$location, $status = 302, $headers = []){
    $location = rc_response_header_value($location);
    $response = new ResponseObj($request, $status, ['Location' => $location]);
    if (!empty($headers)) {
        $response->withHeaders($headers);
    }
    return $response;
}

function pdf($request,$config=[
    'orientation'=>'P',
    'unit'=>'mm',
    'format'=>'A4',
    'unicode'=>true,
    'encoding'=>'UTF-8',
    'diskcache'=>false
]){
    $tcpdf = new Pdf($config);
    $instance = $tcpdf->get_Instance();
    $instance->request = $request;
    return $instance;
}

function queue($connection='default'){
    static $queue;
    $queue = $queue ?? queueClient::class;
    return $queue::connection($connection);
}

/** Render inside the request's application using the current configured view handler. */
function view($request,$template, $vars = []){
    $handler = Config::get('view','handler');
    if (!is_callable([$handler, 'render'])) {
        throw new \RuntimeException('Configure a view handler with a callable static render() method.');
    }
    $app = $request->app['app'];
    return $handler::render($template, $vars, $app);
}

/** Create a fresh application model; full class names retain native autoload priority and never start an ORM. */
function model($request,$model=null,$app=null,$constructor=[]){
    $model = $model ?? ($request->app['controller'] ?? null);
    if(!is_string($model) || $model === ''){
        throw new \InvalidArgumentException('Model name must be a non-empty string.');
    }
    $qualified = str_contains($model, '\\');
    if($qualified && class_exists($model)){
        return Container::make($model,$constructor);
    }
    $app = $app ?? ($request->app['app'] ?? null);
    if(!is_string($app) || $app === ''){
        throw new \InvalidArgumentException('Model application must be a non-empty string.');
    }
    if($app[0] === '\\' || $app[-1] === '\\' || str_contains($app, '/')){
        $app = str_replace('/', '\\', trim($app, '/\\'));
    }
    $name = Config::get('app','app_name') ?? 'app';
    if(!is_string($name) || $name === '' || $app === ''){
        throw new \InvalidArgumentException('Model namespace and application must not be empty.');
    }
    if($name[0] === '\\' || $name[-1] === '\\'){
        $name = trim($name, '\\');
        if($name === ''){ throw new \InvalidArgumentException('Model namespace must not be empty.'); }
    }
    $class = $qualified ? $model : "\\$name\\$app\\model\\$model";
    if(class_exists($class)){
        return Container::make($class,$constructor);
    }
    return \RC\Runtime\ModelFactory::load($class,$model,$app,$name,$constructor);
}

function qrcode($request,$text,$format = 'png',$outfile = false, $level = 0, $size = 3, $margin = 4,$saveandprint=false,array $options=[]){
    $format = $format === null ? 'png' : $format;
    if(!is_string($format)){
        throw new \InvalidArgumentException('QR format must be png, svg, text or raw.');
    }
    $format = strtolower($format);
    if($format=='text'){
        return json($request,QRcode::text($text,$outfile,$level,$size,$margin,$options));
    }
    if($format=='raw'){
        return QRcode::raw($text,$outfile,$level,$size,$margin,$options);
    }
    if(!in_array($format,['png','svg'],true)){
        throw new \InvalidArgumentException('QR format must be png, svg, text or raw.');
    }
    $content = QRcode::render($text,$format,$outfile,$level,$size,$margin,$saveandprint,$options);
    return response($request,$content,200,['Content-Type'=>$format === 'svg' ? 'image/svg+xml; charset=utf-8' : 'image/png']);
}
function setcookies($request,$keyvalue = [], $expires = 0, $path = '', $domain = '', $secure = false, $http_only = false){
    $RCresponse = $request->RCresponse;
    $frame = $RCresponse::$_frame;
    $return = true;
    if($frame=='workerman' || $frame=='swoole'){
          $maxAge = ($expires === 0 || $expires === null) ? null : $expires;
		if($frame=='workerman'){
		   $response = new ResponseObj($request);
		}else{
		   $response = $RCresponse->getConnection();
		}
        if($response===null){
            return false;
        }
        if($keyvalue){
            foreach($keyvalue as $key=>$value){
                $return = $response->cookie($key,$value,$maxAge,$path,$domain,$secure,$http_only);
            }
            if($frame=='workerman'){
               $cookies = $response->getHeader('Set-Cookie');
               $RCresponse->setWorkermanCookie($cookies);
            }
            return $return;
        }
        return false;
        
    }
    foreach($keyvalue as $key=>$value){
        $return = setcookie($key,$value,$expires,$path,$domain,$secure,$http_only);
    }
    return $return;
}
function getcookies($request,$key = null, $default = null){
    return $request->cookie($key,$default);
}
function pinyin($charset = 'utf-8'){
    return new \RC\Helper\PinYin($charset);
}
function sessions($request,$key = null, $default = null){
    $session = $request->session();
    if (null === $key) {
        return $session->all();
    }
    if (\is_array($key)) {
        $session->put($key);
        return null;
    }
    return $session->get($key, $default);
}
function captchaCheck($request,$name = '', $value = '',$connect = 'default', $cache=null){
    return \RC\Helper\Captcha\Manager::check($request, $name, $value, $connect, $cache);
}
function captcha($request, $name = '', $connect = 'default', $closure = null,$cache=null){
    return \RC\Helper\Captcha\Manager::generate($request, $name, $connect, $closure, $cache);
}

/** Verify an offline slider attempt and obtain the one-time proof consumed by captchaCheck(). */
function captchaVerify($request, $name = '', $data = [], $connect = 'default', $cache = null){
    return \RC\Helper\Captcha\Manager::verify($request, $name, $data, $connect, $cache);
}

/** Return a local captcha widget JS/CSS asset; unknown filenames return 404. */
function captchaAsset($request, $name){
    return \RC\Helper\Captcha\Manager::asset($request, $name);
}

function download($request,$file,$download_name=''){
    return (new ResponseObj($request))->download($file,$download_name);
}

function autoForm($request,$vars){
    return new \RC\Helper\AutoForm($request,$vars);
}

function validator(){
    return new validator();
}

function xlsx($target = 'writer'){
    return $target == 'writer' ? new Xlsx() : new XlsxReader();
}

function token($request,$guard = null,$cache = null){
    if($guard === null){
        $guard = Config::get('token','default');
    }
    if($cache === null && (Config::get('token',$guard)['is_single_device'] ?? false)){
        $cache = cache();
    }
    return new Token($request,$guard,$cache);
}

function sms($request,$method = 'get',$config = array(),$cache = null){
    $defaults = Config::get('sms');
    if($defaults===null || $defaults===false){ $defaults = []; }
    if(!is_array($defaults) || !is_array($config)){
        throw new \InvalidArgumentException('SMS configuration must be an array.');
    }
    $config = array_replace($defaults,$config);
    return new Sms($request,$config,$method,$cache);
}

function mailer($connect=null){
    $connect = $connect ?? 'default';
    $config = Config::get('mailer',$connect);
    if(!is_array($config)){
        throw new \Exception('no mailer config load!');
    }
    // Configuration is already cached; mutable messages must not be shared between requests.
    return new Mailer($config);
}

function curl($multiCurl = false){
    if(!$multiCurl){
        return new Curl();
    }
    return new MultiCurl();
}

function simple_database($request,...$config){
    return new SimpleDb($request, ... $config);
}

function cache($engine='',$type=null,$id=1,$class=null,$config=null){
    static $CACHE,$_config,$_class;
    if(!$engine){
        $engine = Config::get('cache','default_frame') ?? ''; 
    }
    if(!isset($CACHE[$engine])){
        if(!is_string($class) || $class === '' || !class_exists($class)){
            throw new \Exception('cache engine '.$engine.' class['.$class.'] not load!');
        }
        if($config){
            $_config[$engine] = $config;
            $_class[$engine] = $class;
            $CACHE[$engine] = [];
        }
        return null;
    }else{
        $type = $type ?? $_config[$engine]['default'];;
    }
    if(!isset($CACHE[$engine])){
        throw new \Exception('no db engine load!');
    }
    if($class){
        return ;
    }
    if($type){
        switch($engine){
            case 'raw':
                if(!isset($CACHE[$engine][$type]['id_'.$id])){
                   $conf = $_config[$engine];
                   $conf['stores'] = $conf['driver'];
                   unset($conf['default_frame'],$conf['driver']);
                   $cacheClass = $_class[$engine];
                   $cacheClass::config($conf);
                   // The outer helper owns connection IDs; do not reuse another ID's driver.
                   $CACHE[$engine][$type]['id_'.$id] = $cacheClass::store($type, true);
                }
            break;
        }
    }
    return $CACHE[$engine][$type]['id_'.$id];
}

function redis($engine='',$type=null,$id=1,$class=null,$clusterclass=null,$config=null){
    static $RD,$_config,$_class,$_cluster_class;
    static $ownerPid;
    $pid = getmypid();
    if ($ownerPid !== $pid) {
        // Keep registration/configuration, but never reuse inherited sockets in a forked child.
        foreach (array_keys($RD ?? []) as $registered) {
            foreach ($RD[$registered] as $connections) {
                foreach ($connections as $client) {
                    // Force native close before references are dropped: persistent destructors can recycle inherited streams.
                    try {
                        if ($client instanceof \Redis || $client instanceof \RedisCluster
                            || $client instanceof \RC\Helper\Redis\mix\Redis) { $client->close(); }
                        elseif ($client instanceof \Predis\Client) { $client->disconnect(); }
                    } catch (\Throwable) {}
                }
            }
            $RD[$registered] = [];
        }
        $ownerPid = $pid;
    }
    if(!$engine){
        $engine = Config::get('redis','default_frame') ?? ''; 
    }
    if(!isset($RD[$engine])){
        if(!is_string($class) || $class === '' || !class_exists($class)){
            throw new \Exception('redis engine '.$engine.' class not load!');
        }
        if($config){
            $_config[$engine] = $config;
            $_class[$engine] = $class;
            $_cluster_class[$engine] = $clusterclass;
            $RD[$engine] = [];
        }
        return null;
    }else{
        $type = $type ?? 'default';
    }

    if(!isset($RD[$engine])){
        throw new \Exception('no db engine load!');
    }
    if($class){
        return ;
    }
    $connectionKey = 'id_'.$id;
    if($type){
        switch($engine){
            case 'raw':
                if(!isset($_config[$engine]['connections'][$type])){
                    if($config){
                        $_config[$engine]['connections'][$type] = $config;
                    }else{
                       throw new \Exception('no redis '.$type.' config set'); 
                    }
                }
                if(!isset($RD[$engine][$type][$connectionKey])){
                    $conf = $_config[$engine]['connections'][$type];
                    $RD[$engine][$type][$connectionKey] = \RC\Helper\Redis\Connector::open(
                        $conf, $engine . ':' . $type . ':' . $connectionKey, $_class[$engine], $_cluster_class[$engine]
                    );
                }
            break;
            case 'mix':
                if(!isset($_config[$engine]['connections'][$type])){
                    if($config){
                        $_config[$engine]['connections'][$type] = $config;
                    }else{
                       throw new \Exception('no redis '.$type.' config set'); 
                    }
                }
                if(!isset($RD[$engine][$type][$connectionKey])){
                    $conf = $_config[$engine]['connections'][$type];
                    if(is_array($conf['host'] ?? null) || in_array($conf['type'] ?? 'common', ['cluster','redisCluster'], true)){
                        $RD[$engine][$type][$connectionKey] = new $_cluster_class[$engine]($conf);
                    }else{
                        $RD[$engine][$type][$connectionKey] = new $_class[$engine](
                            $conf['host'] ?? '127.0.0.1', $conf['port'] ?? 6379, $conf['password'] ?? '',
                            $conf['database'] ?? 0, $conf['timeout'] ?? 5, $conf['retryInterval'] ?? $conf['retry_interval'] ?? 0,
                            $conf['read_timeout'] ?? $conf['readTimeout'] ?? null, $conf
                        );
                    }
                }
            break;
        }
    }
    return $RD[$engine][$type][$connectionKey];
}


function database($engine='',$type=null,$id=1,$class=null,$config=null,$support=null,bool $native=false){
    static $DB,$_config,$_class,$_support,$_heartbeat;
    $_support[$engine] = $_support[$engine] ?? ($support ?? []);
    if(!$engine){
        $engine = Config::get('db','default_frame') ?? ''; 
    }
    if ($engine === 'laravel') {
        if ($class !== null) {
            \RC\Helper\Db\Laravel::register($config ?? []);
            return null;
        }
        return $native ? \RC\Helper\Db\Laravel::connection($type, $id) : \RC\Helper\Db\Laravel::legacy($type, $id);
    }
    if(!isset($DB[$engine])){
        
        if(!is_string($class) || $class === '' || !class_exists($class)){
            throw new \Exception('db engine '.$engine.' class not load!');   
        }
        $type = $config['default'];
        if($config){
            $_config[$engine] = [$type,$config];
            $_class[$engine] = $class;
            $DB[$engine] = [];
        }
    }else{
        $type = $type ?? $_config[$engine][0];
    }
    if(!isset($DB[$engine])){
        throw new \Exception('no db engine load!');
    }
    if($class){
        return ;
    }
    $key = 'id_'.$id;
    if($type){
        if(!in_array($type,$_support[$engine])){
            throw new \Exception('db driver ['.$type.'] not supported by db engine ['.$engine.'] yet!');
        }
        $default_type = $_config[$engine][0] ?? 'mysql';
        $configTmp = $_config[$engine][1] ?? [];
        if(!$configTmp){
            if($config){
                $configTmp = $config;
            }else{
               throw new \Exception('no db '.$type.' config set');  
            }
        }
        $config = $configTmp;
        
        switch($engine){
            case 'medoo':
                $DB[$engine][$type][$key] = $DB[$engine][$type][$key] ?? new $_class[$engine]($config['connections'][$type]);
                if(!isset($_heartbeat[$engine][$type][$key]) && IS_CLI && $type!=='mongodb'){
                    $heartbeatFrame = Config::get('app','cli_frame');
                    // Standalone scripts have no running loop; a later APP lookup may register the heartbeat.
                    if($heartbeatFrame=='workerman' && class_exists(\Workerman\Worker::class, false) && \Workerman\Worker::$globalEvent !== null){
                        $timer_id = \Workerman\Timer::add(240, function () use ($DB, $engine, $type, $key, &$timer_id) {
                            try {
                                $DB[$engine][$type][$key]->query('select 1');
                            } catch (\Throwable $e) {
                                // 记录日志（可选）
                                echo "Heartbeat error ({$key}): " . $e->getMessage() . PHP_EOL;
                                // 删除定时器
                                \Workerman\Timer::del($timer_id);
                                echo "Heartbeat timer deleted for {$key}" . PHP_EOL;
                            }
                        });
                        $_heartbeat[$engine][$type][$key] = 'workerman';
                    }
                    if($heartbeatFrame=='swoole'){
                        $_heartbeat[$engine][$type][$key] = 'swoole';
                        $timer_id = \Swoole\Timer::tick(240000, function ($timer_id) use ($DB, $engine, $type, $key) {
                            try {
                                $DB[$engine][$type][$key]->query('select 1');
                            } catch (\Throwable $e) {
                                echo "Heartbeat error ({$key}): " . $e->getMessage() . PHP_EOL;
                                // 清除 Swoole 定时器
                                \Swoole\Timer::clear($timer_id);
                                echo "Heartbeat timer cleared for {$key}" . PHP_EOL;
                            }
                        });
                    }
                }
            break;
            case 'think':
                if(!isset($DB[$engine][$type][$key])){
                    $DB[$engine][$type][$key] = $_class[$engine]::connection($config, $type, $id);
                }
                    if(!isset($_heartbeat[$engine][$type][$key]) && IS_CLI && $type!=='mongodb'){
                        $heartbeatFrame = Config::get('app','cli_frame');
                        if($heartbeatFrame=='workerman' && class_exists(\Workerman\Worker::class, false) && \Workerman\Worker::$globalEvent !== null){
                            $timer_id = \Workerman\Timer::add(240, function () use (&$DB, $engine, $type, $key, &$timer_id) {
                                try {
                                    $DB[$engine][$type][$key]->query('select 1');
                                } catch (\Throwable $e) {
                                    // 记录日志（可选）
                                    echo "Heartbeat error ({$key}): " . $e->getMessage() . PHP_EOL;
                                    // 删除定时器
                                    \Workerman\Timer::del($timer_id);
                                    echo "Heartbeat timer deleted for {$key}" . PHP_EOL;
                                }
                            });
                            $_heartbeat[$engine][$type][$key] = 'workerman';
                        }
                        if($heartbeatFrame=='swoole'){
                            $_heartbeat[$engine][$type][$key] = 'swoole';
                            $timer_id = \Swoole\Timer::tick(240000, function ($timer_id) use (&$DB, $engine, $type, $key) {
                                try {
                                    $DB[$engine][$type][$key]->query('select 1');
                                } catch (\Throwable $e) {
                                    echo "Heartbeat error ({$key}): " . $e->getMessage() . PHP_EOL;
                                    // 清除 Swoole 定时器
                                    \Swoole\Timer::clear($timer_id);
                                    echo "Heartbeat timer cleared for {$key}" . PHP_EOL;
                                }
                            });
                        }
                    }
            break;
        }
    }
    if ($engine === 'think' && !$native) {
        return \RC\Helper\Db\ThinkOrm\StaticFacade::rcmakerBind($DB[$engine][$type][$key], $config, $type, $id, $key);
    }
    return $DB[$engine][$type][$key];
}

function rcEnv($name = null, $default = null)
{
    return Config::getEnv($name, $default);
}

function rc_apply_memory_limit($memoryLimit = null){
    $memoryLimit = $memoryLimit ?? Config::getEnv('app.memory_limit', null);
    if($memoryLimit === null){
        return false;
    }
    if(is_string($memoryLimit)){
        $memoryLimit = trim($memoryLimit);
        if($memoryLimit === ''){
            return false;
        }
    }elseif(is_int($memoryLimit) || is_float($memoryLimit)){
        $memoryLimit = (string)$memoryLimit;
    }else{
        return false;
    }
    return \ini_set('memory_limit', $memoryLimit);
}

/** Check startup functions, e.g. cliCheck(['pcntl_fork']); the PHP minimum is checked at bootstrap. */
function cliCheck($check_func_map = []){
    $errors = [];
    foreach($check_func_map as $func){
        if(!function_exists($func)){
            $errors[] = "\033[31;40mFunction [$func] is not available. Check PHP extensions and disable_functions in [php.ini].\033[0m\n";
        }
    }
    if($errors){
        foreach($errors as $error){
            echo $error;
        }
        exit(1);
    }
}


function opcache_clean(){
    if (function_exists('opcache_get_status')) {
        if ($status = opcache_get_status()) {
            if (isset($status['scripts']) && $scripts = $status['scripts']) {
                foreach (array_keys($scripts) as $file) {
                    opcache_invalidate($file);
                }
            }
        }
    }
}


function stopMaster(){
    Worker::stopMaster();
}

function stopwatch($eventname='__controller__'){
     if($eventname=='__frame__'){
        if(Stopwatch::$_framework){
            return Stopwatch::$_framework;
        }
     }
     return Stopwatch::stopAndCollect($eventname);
}

function is_phar(){
    return class_exists(Phar::class, false) && Phar::running();
}

function base_path(){
    return BASE_PATH;
}

function phar_path(){
    return is_phar() ? dirname(realpath($_SERVER['SCRIPT_FILENAME'])) : BASE_PATH;
}

function runtime_path(){
    $path = Config::get('app','runtime_path');
    return ($path !== null && $path !== '') ? $path : phar_path()."/runtime";
}

function ssl_path(){
    $path = Config::get('app','ssl_path');
    return ($path !== null && $path !== '') ? $path : BASE_PATH."/ssl";
}

function public_path(){
    $path = Config::get('app','public_path');
    return ($path !== null && $path !== '') ? $path : BASE_PATH."/public";
}

function view_path(){
    $path = Config::get('app','view_path');
    return ($path !== null && $path !== '') ? $path : BASE_PATH."/view";
}

/** Preserve user stop callbacks; shut down lazy queue/log transports only when they were actually loaded. */
function rc_bind_queue_shutdown($worker): void {
    static $bindings;
    $bindings ??= new \WeakMap();
    if(isset($bindings[$worker]) && $worker->onWorkerStop === $bindings[$worker]){
        return;
    }
    $previous = $worker->onWorkerStop;
    $callback = static function($worker) use ($previous): void {
        try {
            if($previous){
                $previous($worker);
            }
        } finally {
            try {
                if(class_exists(\RC\Helper\Queue\RedisQueue\Lifecycle::class, false)){
                    \RC\Helper\Queue\RedisQueue\Lifecycle::shutdown(\Workerman\Worker::getGracefulStop());
                }
            } finally {
                if(class_exists(\RC\Helper\Process\AccessLogClient::class, false)){
                    \RC\Helper\Process\AccessLogClient::shutdown(\Workerman\Worker::getGracefulStop());
                }
            }
        }
    };
    $bindings[$worker] = $callback;
    $worker->onWorkerStop = $callback;
}

/** Bind public process hooks once at worker startup; native Workerman owns their invocation signatures. */
function worker_bind($worker, $class, $type='workerman') {
    $callback_map = [
        'onConnect',
        'onMessage',
        'onReceive',
        'onClose',
        'onError',
        'onBufferFull',
        'onBufferDrain',
        'onWorkerStop',
        'onWebSocketConnect',
        'onOpen',
        'onHandshake',
        'onStart',
        'onShutdown',
        'onWorkerExit',
        'onPacket',
        'nWorkerError',
        'onRequest'
    ];
    if ($type === 'workerman') {
        array_push($callback_map, 'onWorkerReload', 'onWebSocketConnected', 'onWebSocketClose', 'onWebSocketPing', 'onWebSocketPong');
    }
    $callbacks = [];
    foreach ($callback_map as $name) {
        if (method_exists($class, $name)) {
            // Legacy bridge-only method names are not native Workerman hooks; keep their old binding contract.
            $nativeCallback = $type === 'workerman' && in_array($name, [
                'onConnect', 'onMessage', 'onClose', 'onError', 'onBufferFull', 'onBufferDrain',
                'onWorkerStop', 'onWorkerReload', 'onWebSocketConnect', 'onWebSocketConnected',
                'onWebSocketClose', 'onWebSocketPing', 'onWebSocketPong',
            ], true);
            if ($nativeCallback && !is_callable([$class, $name])) {
                throw new \InvalidArgumentException('Process callback must be public and callable: ' . $name);
            }
            $callbacks[$name] = [$class, $name];
        }
    }
    if ($type === 'workerman' && method_exists($class, 'onWorkerStart') && !is_callable([$class, 'onWorkerStart'])) {
        throw new \InvalidArgumentException('Process callback must be public and callable: onWorkerStart');
    }
    foreach ($callbacks as $name => $callback) {
        if($type=='workerman'){
            $worker->$name = $callback;
        }
        if($type=='swoole'){
            $worker->on(substr($name,2),$callback);
        }
    }
    if (method_exists($class, 'onWorkerStart')) {
        call_user_func([$class, 'onWorkerStart'], $worker);
    }
    if($type === 'workerman'){
        rc_bind_queue_shutdown($worker);
    }
}

function cpu_count() {
    if (strtolower(PHP_OS) === 'darwin') {
        $count = shell_exec('sysctl -n machdep.cpu.core_count');
    } else {
        $count = shell_exec('nproc');
    }
    $count = (int)$count > 0 ? (int)$count : 4;
    return $count;
}

function getFilesize($size,$decimals=2){
   $p = 0;
   $format='bytes';
   if($size>0 && $size<1024){
     $p = 0;
     return number_format($size,$decimals).' '.$format;
   }
  if($size>=1024 && $size<pow(1024, 2)){
     $p = 1;
     $format = 'KB';
  }
  if ($size>=pow(1024, 2) && $size<pow(1024, 3)) {
    $p = 2;
    $format = 'MB';
  }
  if ($size>=pow(1024, 3) && $size<pow(1024, 4)) {
    $p = 3;
    $format = 'GB';
  }
  if ($size>=pow(1024, 4) && $size<pow(1024, 5)) {
    $p = 4;
    $format = 'TB';
  }
  $size /= pow(1024, $p);
  return number_format($size, $decimals).' '.$format;
}

function R($request,$body = '', $status = 200, $headers = array()){
    return response($request,$body, $status, $headers);
}
function P($request,$config=[
    'orientation'=>'P',
    'unit'=>'mm',
    'format'=>'A4',
    'unicode'=>true,
    'encoding'=>'UTF-8',
    'diskcache'=>false
]){
    return pdf($request,$config);

}
function T($request,$guard = null,$cache = null){
  return token($request,$guard,$cache);
}
function V($request,$template, $vars = []){
    return view($request,$template,$vars);
}
function M($request,$model=null,$app=null,$constructor=[]){
  return model($request,$model,$app,$constructor);
}
function ML($connect=null){
  return mailer($connect);
}
function Q($request,$text,$format = 'png',$outfile = false, $level = 0, $size = 3, $margin = 4,$saveandprint=false,array $options=[]){
  return qrcode($request,$text,$format,$outfile, $level, $size, $margin,$saveandprint,$options);
}
function SC($request,$keyvalue = [], $expires = 0, $path = '', $domain = '', $secure = false, $http_only = false){
  return setcookies($request,$keyvalue,$expires,$path,$domain,$secure,$http_only);
}
function GC($request, $key = null, $default = null){
    return getcookies($request,$key,$default);
}
function S($request,$key = null, $default = null){
    return sessions($request,$key,$default);
}
function C($request, $name = '', $connect = 'default', $closure = null,$cache=null){
    return captcha($request,$name,$connect,$closure,$cache);
}
function CC($request,$name = '', $value = '',$connect = 'default', $cache=null){
    return captchaCheck($request,$name,$value,$connect,$cache);
}
function D($request,$file,$download_name=''){
    return download($request,$file,$download_name);
}
function AF($request,$vars){
    return autoForm($request,$vars);
}
function VD(){
    return validator();
}
function X($target = 'writer'){
    return xlsx($target);
}
function SDB($request,...$config){
    return simple_database($request,... $config);
}
function RD($engine='',$type=null,$id=1,$class=null,$clusterclass=null,$config=null){
  return redis($engine,$type,$id,$class,$clusterclass,$config);
}
function DB($engine='',$type=null,$id=1,$class=null,$config=null,$support=null,bool $native=false){
    return database($engine,$type,$id,$class,$config,$support,$native);
}
function PY($charset = 'utf-8'){
    return pinyin($charset);
}

// Alias-only files have no class declarations for Composer's authoritative map; keep these entries lazy.
spl_autoload_register(static function (string $class): void {
    if ($class === 'RC\\Helper\\XLSXWriter_BuffererWriter') {
        require_once __DIR__ . '/Helper/Excel/writer-bootstrap.php';
        return;
    }
    $prefix = 'RC\\Helper\\Stopwatch\\';
    if (str_starts_with($class, $prefix)
        && in_array(substr($class, strlen($prefix)), ['Stopwatch', 'StopwatchEvent', 'StopwatchPeriod', 'Section'], true)) {
        require_once __DIR__ . '/Helper/Stopwatch/bootstrap.php';
        return;
    }
    $prefix = 'RC\\Helper\\Curl\\';
    if (str_starts_with($class, $prefix)) {
        $name = substr($class, strlen($prefix));
        if (in_array($name, ['ArrayUtil', 'BaseCurl', 'CaseInsensitiveArray', 'CookieArray', 'Decoder', 'Encoder', 'StringUtil', 'TimeUtil', 'Url'], true)) {
            require_once __DIR__ . '/Helper/Curl/' . $name . '.php';
        }
    }
});

if(Config::get('app','count')===true){
    Stopwatch::start('__frame__');
}

Rcmaker::start();
?>
