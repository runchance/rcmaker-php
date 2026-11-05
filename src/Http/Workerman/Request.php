<?php
// Adapted Workerman parser methods, Copyright walkor <walkor@workerman.net>.
// MIT License; see MIT-LICENSE.txt. RC changes live here, never in the upstream package.

declare(strict_types=1);

namespace RC\Http\Workerman;

use Symfony\Component\HttpFoundation\HeaderUtils;

/** Workerman 5.2 adapter; upstream parser remains unchanged, with body-cache and upload-lifetime fixes here. */
class Request extends \Workerman\Protocols\Http\Request
{
    /** Internal immutable parse results; only canonical RC requests use the facade fast path. */
    public private(set) ?string $rcParsedMethod = null;
    public private(set) ?array $rcParsedHeaders = null;
    public private(set) ?string $rcRoutingKey = null;

    /** Keep lazy parsing and child parser overrides; expose no mutable request state. */
    protected function parseHeadFirstLine(): void
    {
        parent::parseHeadFirstLine();
        if (static::class === self::class) { $this->rcParsedMethod = $this->data['method']; }
    }

    /** Share the parsed header array by PHP copy-on-write, not by duplicating or reparsing it. */
    protected function parseHeaders(): void
    {
        parent::parseHeaders();
        if (static::class === self::class) {
            $this->rcParsedHeaders = $this->data['headers'];
            $this->cacheRoutingKey();
        }
    }

    /** A slash-prefixed HTTP target is a path, never the authority of a network-path URL. */
    protected function parseUriComponents(): void
    {
        $uri = $this->uri();
        if (!str_starts_with($uri, '//')) { parent::parseUriComponents(); }
        else {
            $parsed = parse_url('http://rcmaker.invalid' . $uri);
            $this->data['path'] = $parsed['path'] ?? '/';
            $this->data['query_string'] = $parsed['query'] ?? '';
        }
        if (static::class === self::class) {
            $this->cacheRoutingKey();
        }
    }

    /** Reuse only immutable wire identity; no application, callback, Session or connection state is cached. */
    private function cacheRoutingKey(): void
    {
        if ($this->rcParsedHeaders === null || !isset($this->data['path'])) { return; }
        $this->rcRoutingKey = ($this->rcParsedHeaders['host'] ?? '') . "\0" . $this->rcParsedMethod . "\0"
            . $this->data['path'] . "\0" . $this->data['query_string'];
    }

    /** Return the RC lifecycle adapter while retaining the original Workerman Session return contract. */
    public function session(): Session
    {
        if (isset($this->context['session_error'])) { throw new \RuntimeException('Session initialization has already failed.'); }
        if (!isset($this->context['session'])) {
            try {
                $session = new Session($this->sessionId());
                if (Session::$useStrictMode && ($this->context['sid_from_cookie'] ?? false) && !$session->hasStoredData()) {
                    $session->abandon();
                    $sid = static::createSessionId();
                    $this->setSidCookie(Session::$name, $sid, Session::getCookieParams());
                    $this->context['sid'] = $sid;
                    $session = new Session($sid);
                }
                $this->context['session'] = $session;
            } catch (\Throwable $error) { $this->context['session_error'] = true; unset($this->context['session_cookie']); throw $error; }
        }
        return $this->context['session'];
    }

    /** Validate explicit IDs and treat malformed client cookies as a request for a fresh secure identifier. */
    public function sessionId(?string $sessionId = null): string
    {
        \RC\Session::ensureStarted();
        if ($sessionId !== null) {
            if (!Session::isValidId($sessionId)) { throw new \InvalidArgumentException('Invalid explicit session identifier.'); }
            if (!$this->connection) { throw new \RuntimeException('Cannot set a session identifier after response headers were sent.'); }
            if (isset($this->context['session'])) { $this->context['session']->commit(); }
            $this->setSidCookie(Session::$name, $sessionId, Session::getCookieParams());
            unset($this->context['session']);
            $this->context['sid_from_cookie'] = false;
            return $this->context['sid'] = $sessionId;
        }
        if (!isset($this->context['sid'])) {
            $sid = \RC\Session::normalizeId($this->cookie(Session::$name));
            $this->context['sid_from_cookie'] = $sid !== null;
            if ($sid === null) {
                $sid = static::createSessionId();
                $this->setSidCookie(Session::$name, $sid, Session::getCookieParams());
            }
            $this->context['sid'] = $sid;
        }
        return $this->context['sid'];
    }

    /** Retain the native public ID validator, now with strict end anchoring. */
    public function isValidSessionId(mixed $sessionId): bool { return Session::isValidId($sessionId); }

    /** Commit identity rotation before changing the cookie; never rely on destructors for old/new writes. */
    public function sessionRegenerateId(bool $deleteOldSession = false): string
    {
        if (!$this->connection) { throw new \RuntimeException('Cannot regenerate a session after response headers were sent.'); }
        $session = \RC\Session::rotate($this->session(), $deleteOldSession, function (string $sid): void {
            $this->setSidCookie(Session::$name, $sid, Session::getCookieParams());
        });
        $this->context['session'] = $session;
        return $this->context['sid'] = $session->getId();
    }

    /** A request that never used Session does not initialize a handler or perform storage IO. */
    public function saveSession(): void
    {
        if (isset($this->context['session_error'])) { throw new \RuntimeException('Session initialization has already failed.'); }
        if (isset($this->context['session'])) { $this->context['session']->commit(); }
        if (isset($this->context['session_cookie'])) {
            if (!$this->connection) { throw new \RuntimeException('Cannot publish a session cookie after response headers were sent.'); }
            $this->connection->headers['Set-Cookie'] = [...(array) ($this->connection->headers['Set-Cookie'] ?? []), $this->context['session_cookie']];
            unset($this->context['session_cookie']);
        }
    }

    /** Discard only the existing request object; never create or read a session during error cleanup. */
    public function abandonSession(): void
    {
        $this->context['session_error'] = true;
        unset($this->context['session_cookie']);
        if (isset($this->context['session'])) { $this->context['session']->abandon(); }
    }

    /** Inspect only already-created state; do not initialize storage while preparing an error response. */
    public function hasSessionFailure(): bool
    {
        return isset($this->context['session_error']) || (isset($this->context['session']) && $this->context['session']->hasFailed());
    }

    /** Append rather than overwrite application cookies queued on the native connection. */
    protected function setSidCookie(string $sessionName, string $sid, array $cookieParams): void
    {
        if (!$this->connection) { throw new \RuntimeException('Cannot create a session after response headers were sent.'); }
        $this->context['session_cookie'] = \RC\Session::cookieHeader($sessionName, $sid, $cookieParams);
    }

    /** Parse uploads once even after a file has been moved out of its original temporary path. */
    public function file(?string $name = null): mixed
    {
        if (!isset($this->data['files']) || (!empty($this->data['files']) && !isset($this->context['rc_uploads_parsed']))) { $this->parsePost(); }
        return $name === null ? $this->data['files'] : ($this->data['files'][$name] ?? null);
    }

    /** Keep native multipart field semantics; short JSON/form bodies must not share decoder cache entries. */
    protected function parsePost(): void
    {
        $contentType = strtolower($this->header('content-type', ''));
        if (str_starts_with($contentType, 'multipart/form-data')) {
            if (isset($this->context['rc_upload_failed'])) { throw new \RuntimeException('Multipart parsing has already failed for this request.'); }
            $this->context['rc_uploads_parsed'] = true;
            $this->data['post'] = $this->data['files'] = [];
            try {
                $parameters = self::uploadHeader($this->header('content-type', ''));
                $boundary = $parameters['boundary'] ?? null;
                if (!is_string($boundary) || !preg_match("~\\A[0-9A-Za-z'()+_,./:=? -]{1,70}\\z~D", $boundary) || str_ends_with($boundary, ' ')) {
                    throw new \UnexpectedValueException('Multipart boundary is missing or invalid.');
                }
                $this->parseUploadFiles('--' . $boundary);
            } catch (\Throwable $error) {
                unset($this->data['files'], $this->data['post']);
                $this->context['rc_upload_failed'] = true;
                throw $error;
            }
            return;
        }
        $this->data['post'] = $this->data['files'] = [];
        $body = $this->rawBody();
        if ($body === '') { return; }
        static $cache = [];
        $json = str_contains($contentType, 'json');
        $cacheable = !isset($body[static::MAX_CACHE_STRING_LENGTH]);
        $entry = $cacheable ? ($cache[$body] ?? null) : null;
        if ($entry !== null && $entry[0] === $json) { $this->data['post'] = $entry[1]; return; }
        if ($json) { $this->data['post'] = (array) json_decode($body, true); }
        else { parse_str($body, $this->data['post']); }
        if ($cacheable) {
            $cache[$body] = [$json, $this->data['post']];
            if (count($cache) > static::MAX_CACHE_SIZE) { unset($cache[array_key_first($cache)]); }
        }
    }

    /** Use unchanged Symfony header tokenization for quoted parameters, preserving case and embedded delimiters. */
    private static function uploadHeader(string $header): array
    {
        if (preg_match('~\Aform-data; name="([^"\\\\]*)"(?:; filename="([^"\\\\]*)")?\z~iD', $header, $match)) {
            $parameters = ['form-data' => true, 'name' => $match[1]];
            if (isset($match[2])) { $parameters['filename'] = $match[2]; }
            return $parameters;
        }
        if (preg_match("~\\Amultipart/form-data; boundary=([0-9A-Za-z'+_.-]{1,70})\\z~iD", $header, $match)) {
            return ['multipart/form-data' => true, 'boundary' => $match[1]];
        }
        if (!class_exists(HeaderUtils::class)) { require_once dirname(__DIR__, 2) . '/ThirdParty/symfony-http-foundation/HeaderUtils.php'; }
        return HeaderUtils::combine(HeaderUtils::split($header, ';='));
    }

    /** Keep native field/index normalization, but release every allocated part lost by overwrites or parse errors. */
    protected function parseUploadFiles(string $httpPostBoundary): void
    {
        $postEncoded = $filesEncoded = ''; $files = $selected = []; $failure = null;
        $offset = strpos($this->buffer, "\r\n\r\n") + 4 + strlen($httpPostBoundary) + 2;
        $remaining = static::$maxFileUploads;
        try {
            while ($remaining-- > 0 && $offset) {
                $offset = $this->parseUploadFile($httpPostBoundary, $offset, $postEncoded, $filesEncoded, $files);
            }
            if ($postEncoded !== '') { parse_str($postEncoded, $this->data['post']); }
            if ($filesEncoded !== '') {
                parse_str($filesEncoded, $this->data['files']);
                array_walk_recursive($this->data['files'], static function (&$value) use ($files, &$selected): void {
                    $index = $value; $value = $files[$index];
                    if ($value['error'] === UPLOAD_ERR_OK) { $selected[$index] = true; }
                    else { $value['tmp_name'] = ''; }
                });
            }
        } catch (\Throwable $error) { $failure = $error; }
        try { self::discardUploadParts($files, $failure === null ? $selected : []); }
        catch (\Throwable $cleanup) {
            if ($failure === null) {
                $failure = $cleanup;
                try { self::discardUploadParts(array_intersect_key($files, $selected), []); } catch (\Throwable) { }
            }
        }
        if ($failure !== null) { throw $failure; }
    }

    /** Native boundary/section strategy with complete header values and ownership recorded before body writes. */
    protected function parseUploadFile(string $boundary, int $sectionStartOffset, string &$postEncodeString, string &$filesEncodeStr, array &$files): int
    {
        $marker = "\r\n" . $boundary; $search = $sectionStartOffset; $end = false; $next = 0;
        while (($candidate = strpos($this->buffer, $marker, $search)) !== false) {
            $after = $candidate + strlen($marker); $lineEnd = strpos($this->buffer, "\r\n", $after);
            $tail = substr($this->buffer, $after, $lineEnd === false ? null : $lineEnd - $after);
            $tail = rtrim($tail, " \t");
            if ($tail === '' || $tail === '--') { $end = $candidate; $next = $tail === '--' || $lineEnd === false ? 0 : $lineEnd + 2; break; }
            $search = $after;
        }
        if ($end === false) { return 0; }
        $headerEnd = strpos($this->buffer, "\r\n\r\n", $sectionStartOffset);
        if ($headerEnd === false || $headerEnd + 4 > $end) { return 0; }
        $headers = [];
        foreach (explode("\r\n", substr($this->buffer, $sectionStartOffset, $headerEnd - $sectionStartOffset)) as $line) {
            if (!str_contains($line, ':')) { return 0; }
            [$name, $value] = explode(':', $line, 2); $name = trim($name);
            if ($name === '') { return 0; }
            $headers[strtolower($name)] = ltrim($value, " \t");
        }
        $disposition = self::uploadHeader($headers['content-disposition'] ?? '');
        $field = $disposition['name'] ?? null;
        if (!isset($disposition['form-data']) || !is_string($field)) { return 0; }
        $body = substr($this->buffer, $headerEnd + 4, $end - $headerEnd - 4);
        if (!array_key_exists('filename', $disposition)) {
            $postEncodeString .= urlencode($field) . '=' . urlencode($body) . '&'; return $next;
        }
        $filename = $disposition['filename'];
        if (!is_string($filename)) { return 0; }
        $temporary = ''; $error = UPLOAD_ERR_OK; $directoryPath = null;
        if ($filename === '') { $error = UPLOAD_ERR_NO_FILE; }
        else {
            $directory = \Workerman\Protocols\Http::uploadTmpDir();
            if (!$directory || !is_dir($directory) || ($directoryPath = realpath($directory)) === false) { $error = UPLOAD_ERR_NO_TMP_DIR; }
            else { $temporary = @tempnam($directory, 'workerman.upload.'); if ($temporary === false) { $temporary = ''; $error = UPLOAD_ERR_CANT_WRITE; } }
        }
        $index = count($files);
        $files[$index] = ['name' => $filename, 'tmp_name' => $temporary, 'size' => strlen($body), 'error' => $error,
            'type' => $headers['content-type'] ?? '', 'full_path' => $headers['webkitrelativepath'] ?? $filename];
        if ($error === UPLOAD_ERR_OK && (realpath(dirname($temporary)) !== $directoryPath || file_put_contents($temporary, $body) !== strlen($body))) {
            $files[$index]['error'] = UPLOAD_ERR_CANT_WRITE;
        }
        $filesEncodeStr .= urlencode($field) . '=' . $index . '&';
        return $next;
    }

    /** Delete only unselected native allocations; continue cleanup while preserving its first error. */
    private static function discardUploadParts(array $files, array $selected): void
    {
        $failure = null;
        foreach ($files as $index => $file) {
            if (isset($selected[$index]) || $file['tmp_name'] === '') { continue; }
            $path = $file['tmp_name'];
            try {
                clearstatcache(true, $path);
                if (is_file($path) && !@unlink($path)) { throw new \RuntimeException('Cannot remove an unselected upload temporary file.'); }
            } catch (\Throwable $error) { $failure ??= $error; }
        }
        if ($failure !== null) { throw $failure; }
    }
}
