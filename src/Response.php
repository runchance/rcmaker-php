<?php

namespace RC;

use RC\Http\Workerman\Response as ResponseObj;
use Workerman\Protocols\Http\Response as NativeResponse;

/** Request-owned response transport; business callbacks return framework/native responses or ordinary body bytes. */
class Response
{
    public static $_frame = null;
    public static $_client = null;
    public static $_log_count = 0;
    protected static $_cliLogEnabled = null;
    protected $id = null;
    protected $connection = null;
    protected $_cookies = null;
    public $findStaticFile = false;
    public $staticFile = null;
    public $_status = 200;
    public $RCrequest = null;

    /** Construct once per reusable sequential scope. */
    public function __construct($id) { $this->id = $id; }

    /** Preserve the public scope identifier. */
    public function id() { return $this->id; }

    /** Bind a fresh request and discard stale transport metadata, including direct scope replacement. */
    public function set($connection, $id, $RCrequest): void
    {
        if ($this->_status !== 200 || $this->findStaticFile || $this->staticFile !== null) { $this->resetMetadata(); }
        $this->_cookies = null;
        $this->connection = $connection; $this->RCrequest = $RCrequest;
        static::$_frame = IS_CLI ? (static::$_frame ?? Config::get('app', 'cli_frame')) : null;
    }

    /** Release references and response-only state before another request reuses this facade. */
    public function unset($id)
    {
        $this->connection = $this->RCrequest = $this->_cookies = null;
        if ($this->_status !== 200 || $this->findStaticFile || $this->staticFile !== null) { $this->resetMetadata(); }
    }

    /** Bare successful requests avoid repeated writes to fields that are already at defaults. */
    private function resetMetadata(): void
    {
        if ($this->_status !== 200 || $this->findStaticFile || $this->staticFile !== null) {
            $this->_status = 200; $this->findStaticFile = false; $this->staticFile = null;
        }
    }

    /** Return the actual transport for framework integrations, not a second connection lookup table. */
    public function getConnection() { return $this->connection; }

    /** HTTP/1.1 is persistent by default; explicit close always wins over keep-alive tokens. */
    protected static function shouldKeepAlive($request): bool
    {
        if (!$request) { return false; }
        $keepAlive = false;
        $connection = (string) $request->header('connection', '');
        if ($connection === 'keep-alive') { return true; }
        if ($connection === 'close') { return false; }
        $connection = strtolower(trim($connection));
        foreach ($connection === '' ? [] : explode(',', $connection) as $token) {
            $token = trim($token);
            if ($token === 'close') { return false; }
            if ($token === 'keep-alive') { $keepAlive = true; }
        }
        return $keepAlive || in_array(strtoupper(trim((string) $request->protocol())), ['HTTP/1.1', '1.1'], true);
    }

    /** Preserve all cookies queued by independent helpers during this request. */
    public function setWorkermanCookie($cookies) { $this->_cookies = array_merge($this->_cookies ?? [], (array) $cookies); }

    /** Failed session persistence cannot publish queued authentication cookies. */
    public function discardCookies(): void
    {
        $this->_cookies = null;
        if ($this->connection instanceof \Workerman\Connection\TcpConnection) {
            foreach (array_keys($this->connection->headers) as $name) { if (strtolower($name) === 'set-cookie') { unset($this->connection->headers[$name]); } }
        }
        if (!IS_CLI && !headers_sent()) { header_remove('Set-Cookie'); }
    }

    /** Drain once before validating: a malformed raw header cannot poison the subsequent error response. */
    private function takeConnectionHeaders(): array
    {
        if (!empty($this->connection->headers) && is_array($this->connection->headers)) {
            $headers = $this->connection->headers; $this->connection->headers = []; return $headers;
        }
        return [];
    }

    /** Error responses retain close intent, never failed metadata or arbitrary header object conversions. */
    private function preserveCloseOnFailure(array $headers, ?bool $keepAlive): void
    {
        if (!$this->connection instanceof \Workerman\Connection\TcpConnection) { return; }
        if ($keepAlive !== false) {
            foreach ($headers as $name => $values) {
                if (!is_string($name) || strtolower($name) !== 'connection') { continue; }
                foreach (is_array($values) ? $values : [$values] as $value) {
                    if (!is_string($value) || preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $value)) { continue; }
                    foreach (explode(',', $value) as $token) {
                        if (strtolower(trim($token)) === 'close') { $keepAlive = false; break 3; }
                    }
                }
            }
        }
        if ($keepAlive === false) { $this->connection->headers['Connection'] = 'close'; }
    }

    /** One entry point for callback responses, direct sends and framework error pages. */
    public function send($response)
    {
        if (static::$_frame === 'workerman') { $this->workerman_send($response, $this->RCrequest); return null; }
        if (static::$_frame === 'swoole') { $this->swoole_send($response); return null; }
        $this->sapiSend($response); return null;
    }

    /** Preserve native objects and the plain-string fast path; session commits precede every transport write. */
    public function workerman_send($response, $request = null)
    {
        $request ??= $this->RCrequest;
        $headers = empty($this->connection->headers) ? [] : $this->takeConnectionHeaders();
        $cookies = $this->_cookies; $this->_cookies = null;
        try {
            $keepAlive = static::shouldKeepAlive($request); $head = $request && $request->method() === 'HEAD';
            if (!$response instanceof ResponseObj) {
                if ($response instanceof NativeResponse) { $response = ResponseObj::fromNative($response, $request); }
                elseif (!is_string($response)) { $response = new ResponseObj($request, 200, [], $response); }
            }
            if ($headers || $cookies || !$keepAlive || $head) {
                if (!$response instanceof ResponseObj) { $response = new ResponseObj($request, 200, [], $response); }
                if ($headers) { $response->withHeaders($headers); }
                if ($cookies) { $response->withHeaders(['Set-Cookie' => $cookies]); }
            }
            if ($response instanceof ResponseObj) {
                if ($connectionHeader = $response->getHeader('Connection')) {
                    $tokens = explode(',', strtolower($connectionHeader));
                    if (in_array('close', array_map('trim', $tokens), true)) { $keepAlive = false; }
                }
                if (!$keepAlive) { $response->header('Connection', 'close'); }
                if ($request && in_array($request->protocol(), ['HTTP/1.0', '1.0'], true)) { $response->withProtocolVersion('1.0'); }
                $response = $response->forRequest($request, $head);
            }
            if ($request instanceof Request) { $request->saveSession(); }
            $headers = empty($this->connection->headers) ? [] : $this->takeConnectionHeaders();
            if ($headers || $this->_cookies) {
                if (!$response instanceof ResponseObj) { $response = (new ResponseObj($request, 200, [], $response))->forRequest($request, $head); }
                if ($headers) { $response->withHeaders($headers); }
                // Commit hooks can use SC()/setcookies() as well as native connection headers.
                if ($this->_cookies) {
                    $cookies = $this->_cookies; $this->_cookies = null;
                    $response->withHeaders(['Set-Cookie' => $cookies]);
                }
                // Session/custom commit hooks can add Connection after the first preparation pass.
                if ($connectionHeader = $response->getHeader('Connection')) {
                    $tokens = explode(',', strtolower($connectionHeader));
                    if (in_array('close', array_map('trim', $tokens), true)) { $keepAlive = false; }
                }
                if (!$keepAlive) { $response->header('Connection', 'close'); }
            }
            $this->_status = $response instanceof NativeResponse ? $response->getStatusCode() : 200;
            $this->log_list();
        } catch (\Throwable $error) {
            $this->_cookies = null;
            $this->preserveCloseOnFailure($headers, $keepAlive ?? null);
            throw $error;
        }
        if ($response instanceof ResponseObj && $response->file !== null && $response->representationLength() >= 2097152
            && $this->connection instanceof \Workerman\Connection\TcpConnection) {
            $this->sendWorkermanFile($response, $keepAlive);
        } elseif ($keepAlive) { $this->connection->send($response); }
        else { $this->connection->close($response); }
    }

    /** Native streaming must finish while the socket still accepts sends, before entering its closing state. */
    private function sendWorkermanFile(ResponseObj $response, bool $keepAlive): void
    {
        $connection = $this->connection; $previousFull = $connection->onBufferFull; $previousDrain = $connection->onBufferDrain;
        static $paused;
        $paused ??= new \ReflectionProperty(\Workerman\Connection\TcpConnection::class, 'isPaused');
        $ownsPause = !$paused->getValue($connection);
        if ($ownsPause) { $connection->pauseRecv(); }
        $connection->send($response);
        if ($connection->getStatus() === \Workerman\Connection\TcpConnection::STATUS_CLOSED) { return; }
        $finish = static function ($connection) use ($keepAlive, $previousFull, $previousDrain, $ownsPause): void {
            if ($connection->getStatus() === \Workerman\Connection\TcpConnection::STATUS_CLOSED) { return; }
            $connection->context->streamSending = false;
            $connection->onBufferFull = $previousFull; $connection->onBufferDrain = $previousDrain;
            if (!$keepAlive) { $connection->close(); }
            elseif ($ownsPause) {
                // resumeRecv immediately dispatches buffered requests; defer until the old request/write stack has unwound.
                \Workerman\Timer::add(0.000001, static function () use ($connection): void {
                    if ($connection->getStatus() === \Workerman\Connection\TcpConnection::STATUS_ESTABLISHED) { $connection->resumeRecv(); }
                }, [], false);
            }
        };
        $complete = static function ($connection) use ($keepAlive, $previousFull, $finish): void {
            if ($connection->getStatus() === \Workerman\Connection\TcpConnection::STATUS_CLOSED) { return; }
            $connection->onBufferFull = $previousFull;
            if ($connection->getSendBufferQueueSize() > 0) {
                // Do not let baseWrite destroy or reuse a socket while its final generated bytes are still buffered.
                $connection->context->streamSending = true; $connection->onBufferDrain = $finish;
                if (!$keepAlive) { $connection->close(); }
            } else { $finish($connection); }
        };
        if ($connection->onBufferDrain === null) { $complete($connection); return; }
        $drain = $connection->onBufferDrain;
        $connection->onBufferDrain = static function ($connection) use ($drain, $complete): void {
            $drain($connection);
            if ($connection->onBufferDrain === null) { $complete($connection); }
        };
        if ($previousFull !== null) {
            $full = $connection->onBufferFull;
            $connection->onBufferFull = static function ($connection) use ($full, $previousFull): void { $full($connection); $previousFull($connection); };
            if (!empty($connection->context->bufferFull)) { $previousFull($connection); }
        }
    }

    /** Save the actual emitted status; the disabled path does not load or initialize logger transport classes. */
    private function log_list(): void
    {
        if (!(static::$_cliLogEnabled ??= (bool) Config::get('app', 'cli_log')) || !$this->RCrequest) { return; }
        $log = ['app' => $this->RCrequest->app['app'] ?? '__static__', 'path' => $this->RCrequest->path(),
            'ip' => $this->RCrequest->ip(), 'protocol' => $this->RCrequest->protocol(), 'time' => time(),
            'referer' => $this->RCrequest->header('referer'), 'method' => $this->RCrequest->method(),
            'status' => $this->_status, 'agent' => $this->RCrequest->header('user-agent')];
        if (static::$_frame === 'workerman') {
            \RC\Helper\Process\AccessLogClient::write($log);
        } elseif (static::$_frame === 'swoole') {
            Worker::$_swoole_table->set(Worker::$_workid . '-' . static::$_log_count++, $log);
        }
    }

    /** Use native metadata APIs; never stringify/reparse a response or emit file contents twice under FPM. */
    private function sapiSend($response): void
    {
        $object = $response instanceof NativeResponse ? ResponseObj::fromNative($response, $this->RCrequest) : new ResponseObj($this->RCrequest, 200, [], $response);
        $object = $object->forRequest($this->RCrequest); $stream = null;
        try {
            if ($object->file !== null && $object->sendsBody()) {
                $stream = fopen($object->file['file'], 'rb');
                if ($stream === false || $object->file['offset'] !== 0 && fseek($stream, $object->file['offset']) !== 0) {
                    throw new \RuntimeException('Cannot open the response file.');
                }
            }
            if ($this->RCrequest instanceof Request) { $this->RCrequest->saveSession(); }
            $this->_status = $object->getStatusCode(); http_response_code($this->_status);
            $headers = $object->getHeaders();
            $headers['Content-Type'] ??= 'text/html;charset=utf-8';
            unset($headers['Transfer-Encoding'], $headers['Connection'], $headers['Keep-Alive'], $headers['Trailer']);
            if ($object->bodyForbidden()) {
                unset($headers['Content-Length']);
                if ($this->_status === 205) { $headers['Content-Length'] = '0'; }
            } elseif ($object->isEventStream()) { unset($headers['Content-Length']); }
            else { $headers['Content-Length'] = (string) $object->representationLength(); }
            foreach ($headers as $name => $value) {
                $replace = strtolower($name) !== 'set-cookie';
                foreach ((array) $value as $item) { header($name . ': ' . $item, $replace); $replace = false; }
            }
            if (!$object->sendsBody() || $object->isEventStream()) { return; }
            if ($stream !== null) {
                $remaining = (int) $headers['Content-Length'];
                while ($remaining > 0) {
                    $bytes = fread($stream, min(65536, $remaining));
                    if ($bytes === false || $bytes === '') { throw new \RuntimeException('Incomplete response file read.'); }
                    echo $bytes; $remaining -= strlen($bytes);
                }
            } else { echo $object->rawBody(); }
        } finally { if (is_resource($stream)) { fclose($stream); } }
    }

    /** Retain legacy sequential Swoole dispatch without introducing coroutine or protocol support. */
    public function swoole_send($response)
    {
        $object = $response instanceof NativeResponse ? ResponseObj::fromNative($response, $this->RCrequest) : new ResponseObj($this->RCrequest, 200, [], $response);
        $object = $object->forRequest($this->RCrequest);
        if ($this->RCrequest instanceof Request) { $this->RCrequest->saveSession(); }
        $this->_status = $object->getStatusCode(); $this->log_list(); $this->connection->status($this->_status);
        $headers = $object->getHeaders(); $headers['Content-Type'] ??= 'text/html;charset=utf-8';
        unset($headers['Content-Length'], $headers['Transfer-Encoding'], $headers['Connection']);
        foreach ($headers as $name => $value) { foreach ((array) $value as $item) { $this->connection->header($name, $item); } }
        if ($object->file !== null && $object->sendsBody()) { $this->connection->sendfile($object->file['file'], $object->file['offset'], $object->file['length']); }
        else { $this->connection->end($object->sendsBody() ? $object->rawBody() : ''); }
    }

    /** Use the same emitter directly for the canonical facade; preserve virtual send hooks on extensions. */
    public function response($app, $callback, $request)
    {
        $response = $callback($request);
        if (static::$_frame === 'workerman' && static::class === self::class) { $this->workerman_send($response, $this->RCrequest); }
        else { $this->send($response); }
        return null;
    }

    /** Keep the legacy virtual signature; an optional fourth positional header array remains accepted. */
    public function bad($request, $status = 404, $e = '404 Not Found')
    {
        $headers = func_num_args() > 3 ? func_get_arg(3) : [];
        return $this->badWithHeaders($request, $status, $e, $headers);
    }

    /** Explicit header-aware entry, including named arguments; preserve the existing error renderer. */
    public function badWithHeaders($request, $status = 404, $e = '404 Not Found', array $headers = [])
    {
        $object = new ResponseObj($request, $status, $headers, $e);
        $type = $object->getHeader('Content-Type');
        $public = public_path();
        $file = rtrim($public, IS_WIN || stripos($public, 'phar://') === 0 ? '/\\' : '/') . '/' . (int) $status . '.html';
        if (($type === null || str_contains(strtolower($type), 'text/html')) && is_file($file)) {
            $level = ob_get_level(); ob_start();
            try { $msg = $e; include $file; $object->withBody(ob_get_clean()); }
            catch (\Throwable $error) {
                while (ob_get_level() > $level) { ob_end_clean(); }
                error_log('RC error-page render failed: ' . $error->getMessage());
            }
        }
        $this->send($object); return null;
    }
}
