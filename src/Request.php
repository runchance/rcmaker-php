<?php
namespace RC;
use RC\Config;
use RC\Session;
use RC\Response;
use RC\Helper;
class Request{
	protected static $_frame = null;
	protected $id = null;
	protected $nativeRequest = null;
	protected $_get = array();
	private $_files = null;
	private $_fileData = null;
	private $_post = null;
	private ?Session $sessionManager = null;
	private bool $sessionAborted = false;
	// These caches are lazy; bare Workerman GET/POST scopes do not need repeated null writes.
	private bool $hasCachedInput = false;
	private static ?\RC\Http\TrustedProxy $trustedProxy = null;
	protected $_attributes = [];
	public $app = [null,null,null];
	public $RCresponse = null;
	protected static $injection = ['response','R','json','xml','jsonp','redirect','pdf','P','view','V','model','M','qrcode','Q','setcookies','SC','getcookies','GC','sessions','S','captcha','C','download','D','autoForm','AF','simple_database','SDB','token','T','sms','captchaCheck','CC','captchaVerify','captchaAsset'];
	public function __construct($id)
    {
        $this->id = $id;
    }

    public function id()
    {
        return $this->id;
    }

    public function __call($method, $args){
    	if(in_array($method,static::$injection)){
            return $args ? $method($this,...$args) : $method($this);
    	}
    }

	public function __set($name, $value)
	{
		$this->_attributes[$name] = $value;
	}

	public function __get($name)
	{
		return $this->_attributes[$name] ?? null;
	}

	public function __isset($name)
	{
		return array_key_exists($name, $this->_attributes);
	}

	public function __unset($name)
	{
		unset($this->_attributes[$name]);
	}


    public function set($request = null,$id = null,$RCresponse = null){
		static::$_frame = static::$_frame ?? Config::get('app','cli_frame');
		$this->nativeRequest = $request;
		$this->_get = [];
		if ($this->hasCachedInput) {
			$this->_files = $this->_fileData = $this->_post = null;
			$this->sessionManager = null;
			$this->sessionAborted = false;
			$this->hasCachedInput = false;
		}
		$this->app = [null, null, null];
		$this->_attributes = [];
		$this->RCresponse = $RCresponse;
	}

	public function unset($id){
		$this->nativeRequest = null;
		$this->_get = [];
		if ($this->hasCachedInput) {
			$this->_files = $this->_fileData = $this->_post = null;
			$this->sessionManager = null;
			$this->sessionAborted = false;
			$this->hasCachedInput = false;
		}
		$this->app = [null, null, null];
		$this->_attributes = [];
		$this->RCresponse = null;
	}

	protected static function stripHostPort($host)
	{
		$host = trim((string)$host);
		if($host === ''){
			return $host;
		}
		if($host[0] === '[' && ($pos = strpos($host, ']')) !== false){
			return substr($host, 1, $pos - 1);
		}
		if(substr_count($host, ':') === 1){
			return explode(':', $host)[0];
		}
		return $host;
	}

	protected static function serverHeader($name, $default = '', $hasDefault = false)
	{
		$key = strtoupper(str_replace('-', '_', (string)$name));
		$serverKey = 'HTTP_'.$key;
		if(array_key_exists($serverKey, $_SERVER)){
			return $_SERVER[$serverKey];
		}
		if(in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true) && array_key_exists($key, $_SERVER)){
			return $_SERVER[$key];
		}
		return $hasDefault ? $default : '';
	}

	/** Expose request headers only; SERVER/PATH/environment values are not HTTP headers. */
	protected static function serverHeaders(): array
	{
		if (function_exists('getallheaders') && is_array($headers = getallheaders())) {
			return array_change_key_case($headers, CASE_LOWER);
		}
		$headers = [];
		foreach ($_SERVER as $key => $value) {
			if (str_starts_with($key, 'HTTP_')) { $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value; }
		}
		foreach (['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'] as $key) {
			if (array_key_exists($key, $_SERVER)) { $headers[strtolower(str_replace('_', '-', $key))] = $_SERVER[$key]; }
		}
		return $headers;
	}

	/** Framework boot API: compile proxy policy once, not in the request hot path. */
	public static function configureTrustedProxies(mixed $proxies = [], mixed $header = 'x-forwarded-for'): void {
		if (!is_array($proxies) || !is_string($header)) {
			throw new \InvalidArgumentException('trusted_proxies must be an array and trusted_proxy_header must be a string.');
		}
		if ($proxies === [] && $header === 'x-forwarded-for') { self::$trustedProxy = null; return; }
		$policy = new \RC\Http\TrustedProxy($proxies, $header);
		self::$trustedProxy = $proxies === [] ? null : $policy;
	}

	/** Client IP is the peer unless an explicitly trusted proxy supplies the one configured header. */
	public function ip(){
		$policy = self::$trustedProxy;
		return $policy === null ? $this->remoteIp() : $policy->resolve($this);
	}

	public function remoteIp(){
		$frame = static::$_frame;
		if(!IS_CLI || !$frame){
			// The peer address must not be replaced by attacker-controlled forwarding headers.
			$ip = $_SERVER['REMOTE_ADDR'] ?? getenv('REMOTE_ADDR');
			return is_string($ip) && $ip !== '' ? $ip : null;
		}
		if($frame=='workerman'){
			$connection = $this->RCresponse ? $this->RCresponse->getConnection() : null;
			return $connection ? trim($connection->getRemoteIp(), '[]') : null;
		}
		$req = $this->nativeRequest;
		if($frame=='swoole' && $req){
			$ip = $req->server['remote_addr'];
			return $ip;
		}
		return null;
	}

	/** Return one upload or the form-keyed file tree; client filenames never become array keys. */
	public function file($name=null){
		if ($name !== null && !is_string($name) && !is_int($name)) {
			throw new \InvalidArgumentException('Upload field names must be strings or integers.');
		}
		$frame = static::$_frame;
		$transposed = !IS_CLI || !$frame || $frame === 'swoole';
		if ($this->_fileData === null) {
			if (!IS_CLI || !$frame) {
				$files = $_FILES;
			} elseif ($frame === 'workerman' && $this->nativeRequest) {
				$files = $this->nativeRequest->file();
			} elseif ($frame === 'swoole' && $this->nativeRequest) {
				$files = $this->nativeRequest->files ?? [];
			} else { $files = []; }
			$this->_fileData = $files ?? [];
			$this->_files = [];
			$this->hasCachedInput = true;
		}
		if ($name !== null) {
			if (!array_key_exists($name, $this->_fileData)) { return null; }
			if (!array_key_exists($name, $this->_files)) {
				$this->_files[$name] = static::normalizeUpload($this->_fileData[$name], $transposed);
			}
			return $this->_files[$name] === [] ? null : $this->_files[$name];
		}
		$uploads = [];
		foreach ($this->_fileData as $field => $file) {
			if (!array_key_exists($field, $this->_files)) { $this->_files[$field] = static::normalizeUpload($file, $transposed); }
			$upload = $this->_files[$field];
			if ($upload !== null && $upload !== []) { $uploads[$field] = $upload; }
		}
		return $uploads;
	}

	protected static function parseFile($file)
    {
        if (!is_array($file) || !isset($file['error']) || !is_int($file['error'])) {
            throw new \RC\Exception\FileException('Malformed uploaded-file error metadata.');
        }
        if ($file['error'] === UPLOAD_ERR_NO_FILE) { return null; }
        $temporary = $file['tmp_name'] ?? '';
        if ($temporary === false && $file['error'] !== UPLOAD_ERR_OK) { $temporary = ''; }
        if (!is_string($temporary) || !is_string($file['name'] ?? null) || !is_string($file['type'] ?? '')) {
            throw new \RC\Exception\FileException('Malformed uploaded-file path/name/type metadata.');
        }
        $name = basename(str_replace('\\', '/', $file['name']));
        return new \RC\Http\UploadFile($temporary, $name, $file['type'] ?? '', $file['error'], $file['full_path'] ?? $file['name']);
    }

    protected static function parseFiles($files)
    {
        return static::normalizeUpload($files, is_array($files['tmp_name'] ?? null));
    }

    /** Convert FPM's column layout or Workerman's row layout without guessing from client field names. */
    private static function normalizeUpload($files, bool $transposed, int $depth = 0)
    {
        if (!is_array($files) || $depth >= 64) {
            throw new \RC\Exception\FileException('Malformed or excessively nested upload metadata.');
        }
        if (array_key_exists('tmp_name', $files) && !is_array($files['tmp_name'])) { return static::parseFile($files); }
        $uploads = [];
        if ($transposed) {
            if (!isset($files['tmp_name']) || !is_array($files['tmp_name'])) {
                throw new \RC\Exception\FileException('Malformed transposed upload metadata.');
            }
            foreach ($files['tmp_name'] as $key => $temporary) {
                $row = ['tmp_name' => $temporary];
                foreach (['name', 'type', 'error', 'size', 'full_path'] as $column) {
                    if (isset($files[$column]) && is_array($files[$column]) && array_key_exists($key, $files[$column])) {
                        $row[$column] = $files[$column][$key];
                    }
                }
                $upload = static::normalizeUpload($row, true, $depth + 1);
                if ($upload !== null && $upload !== []) { $uploads[$key] = $upload; }
            }
        } else {
            foreach ($files as $key => $row) {
                $upload = static::normalizeUpload($row, false, $depth + 1);
                if ($upload !== null && $upload !== []) { $uploads[$key] = $upload; }
            }
        }
        return $uploads;
    }

	public function protocol(){
		$frame = static::$_frame;
		if(!IS_CLI || !$frame){
			return $_SERVER['SERVER_PROTOCOL'] ?? (getenv('SERVER_PROTOCOL') ?: null);
		}
		$req = $this->nativeRequest;
		if($frame=='workerman' && $req){
			$protocol = 'HTTP/' . $req->protocolVersion();
			return $protocol;
		}
		if($frame=='swoole' && $req){
			$protocol = $req->server['server_protocol'];
			return $protocol;
		}
		return null;
	}

	public function setGet($get):bool{
		if(!$get || !is_array($get)){
			$this->_get = [];
			return false;
		}
		$frame = static::$_frame;
		if(!IS_CLI || !$frame){
			$_GET = array_replace($get,$_GET);
			return true;
		}
		$req = $this->nativeRequest;
		$this->_get = $get;
		return false;
	}

	public function rawBody(){
		$frame = static::$_frame;
		if(!IS_CLI || !$frame){
			return file_get_contents('php://input');
		}
		$req = $this->nativeRequest;
		if($frame=='workerman' && $req){
			return $req->rawBody();
		}
		if($frame=='swoole' && $req){
			return $req->getContent();
		}
	}

	public function raw($function,...$args){
		$frame = static::$_frame;
		if(!IS_CLI || !$frame){
			return null;
		}
		$req = $this->nativeRequest;
		if($req){
			if(is_callable([$req,$function])){
				return $req->{$function}(...$args);
			}else{
				if(is_array($req->{$function})){
					return isset($args[0]) ? $req->{$function}[$args[0]] : $req->{$function};
				}
			}
		}
	}

	public function session(){
		$frame = static::$_frame;
		if (session_status() === PHP_SESSION_ACTIVE) {
			throw new \LogicException('A native PHP session is already active; use the framework session API only.');
		}
		$req = $this->nativeRequest;
		if(IS_CLI && $frame=='workerman' && $req){
			if (!$req instanceof \RC\Http\Workerman\Request) {
				throw new \LogicException('Custom Workerman session requests must extend RC\\Http\\Workerman\\Request.');
			}
			return $req->session();
		}
		if ($this->sessionManager === null) {
			$connection = $this->RCresponse ? $this->RCresponse->getConnection() : null;
			$this->sessionManager = new Session($this->id, $req, $connection);
			$this->hasCachedInput = true;
		}
		return $this->sessionManager->session(IS_CLI && $frame === 'swoole' ? 'swoole' : null);
	}

	/** Regenerate through the active backend, preserving one request-scoped object on all supported SAPIs. */
	public function sessionRegenerateId(bool $deleteOldSession = false): string {
		$this->session();
		if ($this->nativeRequest instanceof \RC\Http\Workerman\Request) { return $this->nativeRequest->sessionRegenerateId($deleteOldSession); }
		return $this->sessionManager->regenerateId($deleteOldSession, IS_CLI && static::$_frame === 'swoole' ? 'swoole' : null);
	}

	/** Called before output, including native raw('session') access, without creating unused sessions. */
	public function saveSession(): void {
		if ($this->sessionAborted) { return; }
		if (session_status() === PHP_SESSION_ACTIVE) { throw new \LogicException('Native PHP and framework sessions cannot be active together.'); }
		$native = $this->nativeRequest;
		if ($native instanceof \RC\Http\Workerman\Request) {
			// An empty canonical context has no Session, pending SID cookie or recorded failure. Extensions still run.
			if ($native->context || $native::class !== \RC\Http\Workerman\Request::class) { $native->saveSession(); }
		}
		elseif ($this->sessionManager !== null) {
			$this->sessionManager->save();
		}
	}

	/** Preserve an application error, or render a persistence failure once without a second storage attempt. */
	public function prepareErrorResponse(\Throwable $error): \Throwable {
		$failed = $this->nativeRequest instanceof \RC\Http\Workerman\Request
			? $this->nativeRequest->hasSessionFailure() : ($this->sessionManager !== null && $this->sessionManager->hasFailed());
		$storageError = $failed ? $error : null;
		if (!$failed) { try { $this->saveSession(); } catch (\Throwable $failure) { $storageError = $failure; } }
		if ($storageError !== null) {
			if ($this->RCresponse instanceof Response) { $this->RCresponse->discardCookies(); }
			if (session_status() === PHP_SESSION_ACTIVE) { session_abort(); $_SESSION = []; }
			if ($this->nativeRequest instanceof \RC\Http\Workerman\Request) { $this->nativeRequest->abandonSession(); }
			elseif ($this->sessionManager !== null) { $this->sessionManager->abandon(); }
			$this->sessionAborted = true;
			$this->hasCachedInput = true;
			return $storageError;
		}
		return $error;
	}

	public function cookie($var=null,$default=null){
		$frame = static::$_frame;
		$hasDefault = func_num_args() >= 2;
		if(!IS_CLI || !$frame){
			if($var === null){
				return $_COOKIE;
			}
			return array_key_exists($var, $_COOKIE) ? $_COOKIE[$var] : ($hasDefault ? $default : '');
		}
		$req = $this->nativeRequest;
		if($frame=='workerman' && $req){
			if($var === null){
				return $req->cookie();
			}
			return $hasDefault ? $req->cookie($var,$default) : $req->cookie($var);
		}
		if($frame=='swoole' && $req){
			if($var === null){
				return $req->cookie ?? [];
			}
			$cookie = $req->cookie ?? [];
			return array_key_exists($var, $cookie) ? $cookie[$var] : ($hasDefault ? $default : '');
		}
		return null;

	}

	public function getRequest(){
		return $this->nativeRequest;
	}
	
	public function header($var=null,$default=null,$id=null){
		$frame = static::$_frame;
		$hasDefault = func_num_args() >= 2;
		if(!IS_CLI || !$frame){
			if($var === null){
				return static::serverHeaders();
			}
			return static::serverHeader($var, $default, $hasDefault);
			
		}
		$req = $this->nativeRequest;
		if($frame=='workerman' && $req){
			if ($var === 'connection' && $req::class === \RC\Http\Workerman\Request::class && $req->rcParsedHeaders !== null) {
				return $req->rcParsedHeaders['connection'] ?? ($hasDefault ? $default : null);
			}
			if($var === null){
				return $req->header();
			}
			return $hasDefault ? $req->header($var,$default) : $req->header($var);
		}
		if($frame=='swoole' && $req){
			if($var === null){
				return $req->header ?? [];
			}
			$headers = $req->header ?? [];
			$key = strtolower((string)$var);
			return array_key_exists($key, $headers) ? $headers[$key] : ($hasDefault ? $default : '');
		}
		return null;
	}
	public function method(){
		$frame = static::$_frame;
		if(!IS_CLI || !$frame){
			return $_SERVER['REQUEST_METHOD'] ?? null;
		}
		$req = $this->nativeRequest;
		if($frame=='workerman' && $req){
			if ($req::class === \RC\Http\Workerman\Request::class && $req->rcParsedMethod !== null) { return $req->rcParsedMethod; }
			return $req->method();
		}
		if($frame=='swoole' && $req){
			return $req->server['request_method'];
		}
		return null;
	}
	public function host($hideport = false){
		$frame = static::$_frame;
		if(!IS_CLI || !$frame){
			$host = $_SERVER['HTTP_HOST'] ?? '';
			if($hideport){
				return static::stripHostPort($host);
			}
			if($host === '' || strpos($host, ':') !== false){
				return $host;
			}
			return isset($_SERVER['SERVER_PORT']) ? $host.":".$_SERVER['SERVER_PORT'] : $host;
		}
		$req = $this->nativeRequest;
		if($frame=='workerman' && $req){
			if ($hideport === false && $req::class === \RC\Http\Workerman\Request::class && $req->rcParsedHeaders !== null) {
				return $req->rcParsedHeaders['host'] ?? null;
			}
			return $req->host($hideport);
		}
		if($frame=='swoole' && $req){
			$host = $req->header['host'] ?? '';
			return $hideport ? static::stripHostPort($host) : $host;
		}
		return null;
	}
	public function get($var=null,$default=null){
		$frame = static::$_frame;
		if(!IS_CLI || !$frame){
			return $var === null ? $_GET : ($_GET[$var] ?? $default);
		}
		$req = $this->nativeRequest;
		if($frame=='workerman' && $req){
			if($var === null){
				return array_replace($this->_get,$req->get() ?? []);
			}
			return isset($default) ? ($req->get($var) ?? ($this->_get[$var] ?? $default)) : ($req->get($var) ?? ($this->_get[$var] ?? null));
		}
		if($frame=='swoole' && $req){
			if($var === null){
				return array_replace($this->_get,$req->get ?? []);
			}
			return isset($default) ? ($req->get[$var] ?? ($this->_get[$var] ?? $default)) : ($req->get[$var] ?? ($this->_get[$var] ?? null));
		}
		return $default ?? '';
	}

	public function post($var=null,$default=null){
		$frame = static::$_frame;
		if(!IS_CLI || !$frame){
			$post = $_POST;
			$contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
			if (str_contains(strtolower((string) $contentType), 'json')) {
				$this->hasCachedInput = true;
				$post = $this->_post ??= (array) json_decode(file_get_contents('php://input'), true);
			}
			return $var === null ? $post : ($post[$var] ?? $default);
		}
		$req = $this->nativeRequest;
		if($frame=='workerman' && $req){
			if($var === null){
				return $req->post();
			}
			return isset($default) ? $req->post($var,$default) : ($req->post($var) ?? null);
		}
		if($frame=='swoole' && $req){
			if($var === null){
				return $req->post;
			}
			return isset($default) ? ($req->post[$var] ?? $default) : ($req->post[$var] ?? null);
		}
		return $default ?? '';
	}
	public function path(){
		$frame = static::$_frame;
		if(!IS_CLI || !$frame){
			$uri = $_SERVER['REQUEST_URI'] ?? '/';
			return parse_url(str_starts_with($uri, '//') ? 'http://rcmaker.invalid' . $uri : $uri, PHP_URL_PATH);
		}	
		$req = $this->nativeRequest;
		if($frame=='workerman' && $req){
			return $req->path();
		}
		if($frame=='swoole' && $req){
			return $req->server['path_info'];
		}
		return null;
	}

	public function queryString(){
		$frame = static::$_frame;
		if(!IS_CLI || !$frame){
			$uri = $_SERVER['REQUEST_URI'] ?? '/';
			return parse_url(str_starts_with($uri, '//') ? 'http://rcmaker.invalid' . $uri : $uri, PHP_URL_QUERY);
		}	
		$req = $this->nativeRequest;
		if($frame=='workerman' && $req){
			return $req->queryString() ?? null;
		}
		if($frame=='swoole' && $req){
			return $req->server['query_string'] ?? null;
		}
		return null;
	}

    public function isAjax(){
        return $this->header('X-Requested-With') === 'XMLHttpRequest';
    }

	public function isPjax(){
        return (bool)$this->header('X-PJAX');
    }

	public function expectsJson(){
        return ($this->isAjax() && !$this->isPjax()) || $this->acceptJson();
    }

	public function acceptJson(){
		return false !== stripos((string)$this->header('accept', ''), 'json');
    }
}
?>
