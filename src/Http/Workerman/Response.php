<?php
/** Workerman/Webman-derived adapter, MIT; upstream sources remain unchanged. See MIT-LICENSE.txt. */

declare(strict_types=1);

namespace RC\Http\Workerman;

use Workerman\Protocols\Http\Response as NativeResponse;

/** HTTP metadata and SAPI adaptation around native framing, cookies, file descriptors and MIME mappings. */
class Response extends NativeResponse
{
    protected $response = null;
    protected $request = null;
    private bool $omitBody = false;
    private ?int $headLength = null;
    private array $headerKeys = [];
    private const JSON_HEADERS = ['Content-Type' => 'application/json'];
    private const HEADER_NAMES = [
        'content-type' => 'Content-Type', 'content-length' => 'Content-Length', 'content-encoding' => 'Content-Encoding',
        'content-range' => 'Content-Range', 'content-disposition' => 'Content-Disposition', 'transfer-encoding' => 'Transfer-Encoding',
        'connection' => 'Connection', 'set-cookie' => 'Set-Cookie', 'etag' => 'ETag', 'last-modified' => 'Last-Modified',
        'location' => 'Location', 'cache-control' => 'Cache-Control', 'vary' => 'Vary', 'date' => 'Date', 'server' => 'Server',
        'accept-ranges' => 'Accept-Ranges', 'keep-alive' => 'Keep-Alive', 'trailer' => 'Trailer',
    ];
    private const SINGLE = ['Content-Type' => true, 'Content-Length' => true, 'Content-Encoding' => true,
        'Content-Range' => true, 'Content-Disposition' => true, 'Transfer-Encoding' => true, 'Connection' => true,
        'ETag' => true, 'Last-Modified' => true, 'Location' => true, 'Date' => true, 'Server' => true, 'Accept-Ranges' => true];

    /** Existing helper constructor; scalar/Stringable bodies retain their previous byte conversion. */
    public function __construct($request = null, $status = 200, $headers = [], $body = '')
    {
        if (!is_int($status)) {
            if (!is_string($status) || filter_var($status, FILTER_VALIDATE_INT) === false) { throw new \InvalidArgumentException('HTTP status must be an integer.'); }
            $status = (int) $status;
        }
        if (!is_array($headers)) { throw new \InvalidArgumentException('Response headers must be an array.'); }
        if (!is_string($body)) {
            if ($body !== null && !is_scalar($body) && !$body instanceof \Stringable) { throw new \InvalidArgumentException('Response body must be a string or scalar.'); }
            $body = (string) $body;
        }
        parent::__construct(200, [], $body);
        if ($status !== 200) { $this->withStatus($status); }
        // This exact immutable set contains no user-supplied field/value; every other set is fully validated.
        if ($headers === self::JSON_HEADERS) { $this->headers = $headers; }
        elseif ($headers !== []) { $this->withHeaders($headers); }
        $this->request = $request; $this->response = $request ? $request->RCresponse : null;
    }

    /** Preserve native public response state without casting its HTTP wire packet into a business body. */
    public static function fromNative(NativeResponse $source, $request = null): static
    {
        if ($source instanceof static) { return $source; }
        $copy = new static($request, $source->getStatusCode(), $source->getHeaders(), $source->rawBody());
        $copy->withProtocolVersion($source->version)->withStatus($source->getStatusCode(), $source->getReasonPhrase());
        if ($source->file !== null) { $copy->withFile($source->file['file'], $source->file['offset'], $source->file['length']); }
        return $copy;
    }

    /** Canonical known fields let the native encoder recognize case-insensitive HTTP names. */
    private static function headerName(string $name): string
    {
        if (isset(self::SINGLE[$name])) { return $name; }
        $lower = strtolower($name);
        if (isset(self::HEADER_NAMES[$lower])) { return self::HEADER_NAMES[$lower]; }
        if (!preg_match('/\A[!#$%&\'*+.^_`|~0-9A-Za-z-]+\z/', $name)) { throw new \InvalidArgumentException('Invalid HTTP response header name.'); }
        return $lower;
    }

    /** Validate all values before publishing an update; repeated non-singleton fields retain native list semantics. */
    private function putHeader(string $name, mixed $value, bool $merge): void
    {
        $originalName = $name; $name = self::headerName($name);
        // Most framework responses set a single string Content-Type; do not allocate reference arrays for it.
        if (is_string($value) && isset(self::SINGLE[$name])) {
            if (preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $value)) { throw new \InvalidArgumentException('HTTP response headers cannot contain control bytes.'); }
            if ($name === 'Content-Length' && !ctype_digit($value)) { throw new \InvalidArgumentException('Invalid Content-Length.'); }
            if ($name === 'Transfer-Encoding' && strtolower($value) !== 'chunked') { throw new \InvalidArgumentException('Only native chunked Transfer-Encoding is supported.'); }
            if ($merge && ($name === 'Connection' || $name === 'Content-Encoding') && isset($this->headers[$name])) {
                $value = $this->headers[$name] . ', ' . $value;
            }
            $this->headers[$name] = $value; return;
        }
        $values = is_array($value) ? array_values($value) : [$value];
        foreach ($values as &$item) {
            if (!is_string($item) && !is_int($item) && !is_float($item)) { throw new \InvalidArgumentException('Invalid HTTP response header value.'); }
            $item = (string) $item;
            if (preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $item)) { throw new \InvalidArgumentException('HTTP response headers cannot contain control bytes.'); }
        }
        unset($item);
        if (isset(self::SINGLE[$name])) {
            if ($values === []) { unset($this->headers[$name]); return; }
            if ($name === 'Connection' || $name === 'Content-Encoding') {
                if ($merge && isset($this->headers[$name])) { array_unshift($values, $this->headers[$name]); }
                $this->headers[$name] = implode(', ', $values); return;
            }
            $value = end($values);
            if ($name === 'Content-Length' && !ctype_digit($value)) { throw new \InvalidArgumentException('Invalid Content-Length.'); }
            if ($name === 'Transfer-Encoding' && strtolower($value) !== 'chunked') { throw new \InvalidArgumentException('Only native chunked Transfer-Encoding is supported.'); }
            $this->headers[$name] = $value; return;
        }
        if (!in_array($name, ['Set-Cookie', 'Cache-Control', 'Vary', 'Keep-Alive', 'Trailer'], true)) {
            $name = $this->headerKeys[$name] ??= $originalName;
        }
        if ($merge && isset($this->headers[$name])) { $values = [...(array) $this->headers[$name], ...$values]; }
        $this->headers[$name] = $name !== 'Set-Cookie' && count($values) === 1 && !is_array($value) ? $values[0] : $values;
    }

    /** Replace one field using HTTP's case-insensitive name semantics. */
    public function header(string $name, string $value): static { $this->putHeader($name, $value, false); return $this; }

    /** Merge repeated fields, but replace singleton metadata instead of emitting conflicting copies. */
    public function withHeaders(array $headers): static
    {
        $old = $this->headers; $keys = $this->headerKeys;
        try { foreach ($headers as $name => $value) { $this->putHeader((string) $name, $value, true); } }
        catch (\Throwable $error) { $this->headers = $old; $this->headerKeys = $keys; throw $error; }
        return $this;
    }

    /** Match header()/withHeaders() regardless of caller spelling. */
    public function getHeader(string $name): array|string|null {
        $key = self::headerName($name); return $this->headers[$this->headerKeys[$key] ?? $key] ?? null;
    }

    /** Remove every spelling through the same canonical key. */
    public function withoutHeader(string $name): static {
        $key = self::headerName($name); unset($this->headers[$this->headerKeys[$key] ?? $key], $this->headerKeys[$key]); return $this;
    }

    /** Validate status/reason while preserving unknown but valid three-digit application statuses. */
    public function withStatus(int $code, ?string $reasonPhrase = null): static
    {
        if ($code < 100 || $code > 599 || $reasonPhrase !== null && preg_match('/[\x00-\x1f\x7f]/', $reasonPhrase)) {
            throw new \InvalidArgumentException('Invalid HTTP status or reason phrase.');
        }
        parent::withStatus($code, $reasonPhrase);
        if ($this->bodyForbidden()) { $this->file = null; }
        return $this;
    }

    /** This transport emits HTTP/1.x, not an HTTP/2 status line. */
    public function withProtocolVersion(string $version): static
    {
        if (!in_array($version, ['1.0', '1.1'], true)) { throw new \InvalidArgumentException('Unsupported HTTP response protocol version.'); }
        return parent::withProtocolVersion($version);
    }

    /** A new ordinary body replaces a pending file rather than leaking its bytes later. */
    public function withBody(string $body): static { $this->file = null; return parent::withBody($body); }

    /** Keep native file descriptors; bound explicit ranges before passing them to a transport. */
    public function withFile(string $file, int $offset = 0, int $length = 0): static
    {
        if ($offset < 0 || $length < 0 || str_contains($file, "\0")) { throw new \InvalidArgumentException('Invalid response file or byte range.'); }
        $this->file = null;
        if (!is_file($file)) { return $this->withStatus(404)->withBody('404 Not Found'); }
        if (!$this->bodyForbidden()) { parent::withFile($file, $offset, $length); }
        return $this;
    }

    /** Serve a file with a GMT validator; request conditions never become part of a cached response body. */
    public function file($file): static
    {
        $this->withFile($file);
        if ($this->file !== null) {
            $modified = filemtime($file);
            if ($modified === false) { throw new \RuntimeException('Cannot inspect response file metadata.'); }
            $this->withFileValidators($modified);
        }
        return $this;
    }

    /** Attachment names are basenames on every host and never introduce control bytes or quoted-string escapes. */
    public function download($file, $download_name = ''): static
    {
        $this->withFile($file);
        if ($this->file !== null && $download_name !== '') {
            $name = basename(str_replace('\\', '/', (string) $download_name));
            $name = preg_replace('/["\\\\\x00-\x1f\x7f]/', '', $name) ?: 'download';
            $this->header('Content-Disposition', 'attachment; filename="' . $name . '"');
        }
        return $this;
    }

    /** Reuse native cookie formatting after validating attributes that could create another field/attribute. */
    public function cookie(string $name, string $value = '', ?int $maxAge = null, string $path = '', string $domain = '', bool $secure = false, bool $httpOnly = false, string $sameSite = ''): static
    {
        self::headerName($name);
        if (preg_match('/[\x00-\x1f\x7f;]/', $path . $domain) || !in_array(strtolower($sameSite), ['', 'lax', 'strict', 'none'], true)) {
            throw new \InvalidArgumentException('Invalid cookie attributes.');
        }
        return parent::cookie($name, $value, $maxAge, $path, $domain, $secure, $httpOnly, $sameSite);
    }

    /** Apply conditional GET/HEAD consistently to disk, preheated, compressed and PHAR representations. */
    public function withFileValidators(int $modified): static
    {
        $this->header('Last-Modified', gmdate('D, d M Y H:i:s', $modified) . ' GMT');
        if ($this->status !== 200 || !$this->request) { return $this; }
        $method = $this->request->method(); $safe = $method === 'GET' || $method === 'HEAD';
        $none = $this->request->header('if-none-match', null);
        if ($none !== null && $none !== '') {
            $etag = $this->getHeader('ETag');
            $match = trim($none) === '*';
            if (!$match && is_string($etag) && preg_match('/\A\s*(?:W\/)?"[\x21\x23-\x7e\x80-\xff]*"(?:\s*,\s*(?:W\/)?"[\x21\x23-\x7e\x80-\xff]*")*\s*\z/', $none)) {
                preg_match_all('/(?:W\/)?"[\x21\x23-\x7e\x80-\xff]*"/', $none, $tags);
                foreach ($tags[0] as $tag) { if (preg_replace('/\AW\//', '', $tag) === preg_replace('/\AW\//', '', $etag)) { $match = true; break; } }
            }
            if ($match) { $this->withStatus($safe ? 304 : 412)->withBody(''); }
            return $this;
        }
        if ($safe && ($date = self::httpDate($this->request->header('if-modified-since', ''))) !== null && $modified <= $date) {
            $this->withStatus(304)->withBody('');
        }
        return $this;
    }

    /** Compatibility extension point; use HTTP dates, not the application's timezone label. */
    protected function notModifiedSince($file): bool
    {
        if (!$this->request || !in_array($this->request->method(), ['GET', 'HEAD'], true) || !is_file($file)) { return false; }
        $date = self::httpDate($this->request->header('if-modified-since', ''));
        return $date !== null && filemtime($file) <= $date;
    }

    /** Strict IMF-fixdate and obsolete HTTP date parsing; arbitrary strtotime expressions are not validators. */
    private static function httpDate(mixed $value): ?int
    {
        if (!is_string($value) || strlen($value) > 64 || preg_match('/[\x00-\x1f\x7f]/', $value)) { return null; }
        $value = trim($value); $zone = new \DateTimeZone('GMT');
        foreach (['D, d M Y H:i:s \G\M\T', 'l, d-M-y H:i:s \G\M\T', 'D M j H:i:s Y'] as $format) {
            $candidate = str_contains($format, 'M j') ? preg_replace('/ +/', ' ', $value) : $value;
            if (str_contains($format, '-y ')) {
                $comma = strpos($candidate, ', ');
                if ($comma === false) { continue; }
                $date = \DateTimeImmutable::createFromFormat('!d-M-y H:i:s \G\M\T', substr($candidate, $comma + 2), $zone);
                if ($date === false) { continue; }
                $now = new \DateTimeImmutable('now', $zone); $year = intdiv((int) $now->format('Y'), 100) * 100 + (int) $date->format('y');
                $date = $date->setDate($year, (int) $date->format('m'), (int) $date->format('d'));
                if ($date > $now->modify('+50 years')) { $date = $date->setDate($year - 100, (int) $date->format('m'), (int) $date->format('d')); }
            } else { $date = \DateTimeImmutable::createFromFormat('!' . $format, $candidate, $zone); }
            if ($date !== false && $date->format($format) === $candidate) {
                return $date->getTimestamp();
            }
        }
        return null;
    }

    /** Prepare file metadata once for a SAPI emitter; native Workerman still performs its own file transport. */
    public function forRequest($request = null, ?bool $head = null): static
    {
        $head ??= $request && $request->method() === 'HEAD';
        if (!$head && $this->status === 200 && $this->file === null && $this->version === '1.1') { return $this; }
        $forbidden = $this->bodyForbidden();
        $copy = $head || $forbidden ? clone $this : $this;
        if ($copy->version === '1.0' && isset($copy->headers['Transfer-Encoding'])) { throw new \LogicException('Chunked responses require HTTP/1.1.'); }
        if ($copy->file !== null && !$forbidden) { $copy->prepareFileHeaders(); }
        if ($head || $forbidden) {
            $copy->headLength = $copy->file !== null ? (int) $copy->headers['Content-Length'] : strlen($copy->body);
            $copy->omitBody = true; $copy->file = null;
        }
        return $copy;
    }

    /** Add native-equivalent file metadata without serializing/reparsing an entire HTTP response. */
    private function prepareFileHeaders(): void
    {
        $file = $this->file['file']; clearstatcache(true, $file); $size = filesize($file);
        if ($size === false) { throw new \RuntimeException('Cannot inspect response file size.'); }
        if (!is_readable($file)) { throw new \RuntimeException('The response file is not readable.'); }
        $offset = $this->file['offset']; $length = $this->file['length'];
        if (($offset !== 0 && $offset >= $size) || $length > $size - $offset) { throw new \InvalidArgumentException('Response file range exceeds its size.'); }
        if (isset($this->headers['Transfer-Encoding'])) { throw new \InvalidArgumentException('File responses cannot use chunked Transfer-Encoding.'); }
        $bytes = $length ?: $size - $offset;
        $this->header('Content-Length', (string) $bytes)->header('Accept-Ranges', 'bytes');
        if ($offset || $length) { $this->header('Content-Range', 'bytes ' . $offset . '-' . ($offset + $bytes - 1) . '/' . $size)->withStatus(206); }
        if (!isset($this->headers['Content-Type'])) { $this->header('Content-Type', $this->getMimeType(strtolower(pathinfo($file, PATHINFO_EXTENSION)))); }
        if (!isset($this->headers['Last-Modified'])) {
            $modified = filemtime($file);
            if ($modified === false) { throw new \RuntimeException('Cannot inspect response file timestamp.'); }
            $this->header('Last-Modified', gmdate('D, d M Y H:i:s', $modified) . ' GMT');
        }
        if (!isset($this->headers['Content-Disposition']) && $this->headers['Content-Type'] === 'application/octet-stream') {
            $name = preg_replace('/["\\\\\x00-\x1f\x7f]/', '', basename($file)) ?: 'download';
            $this->header('Content-Disposition', 'attachment; filename="' . $name . '"');
        }
        if (!isset(self::PHRASES[$this->status]) && !$this->reason) { $this->reason = 'Unknown Status'; }
    }

    /** Bodyless status codes are protocol constraints, not an application-body convention. */
    public function bodyForbidden(): bool { return $this->status < 200 || $this->status === 204 || $this->status === 205 || $this->status === 304; }

    /** SAPI emitters use actual body bytes, never an encoded HTTP packet. */
    public function sendsBody(): bool { return !$this->omitBody && !$this->bodyForbidden(); }

    /** Native SSE response objects send headers only; subsequent events use the Workerman connection API. */
    public function isEventStream(): bool {
        $type = $this->headers['Content-Type'] ?? '';
        return isset($type[16]) && strtolower(trim(explode(';', $type, 2)[0])) === 'text/event-stream';
    }

    /** Representation size survives HEAD detachment without trusting a manually supplied body Content-Length. */
    public function representationLength(): int { return $this->headLength ?? ($this->file !== null ? (int) $this->headers['Content-Length'] : strlen($this->body)); }

    /** Delegate normal framing/SSE/chunks to native code; only correct bodyless and explicit-length handling here. */
    public function __toString(): string
    {
        if ($this->omitBody || $this->bodyForbidden()) {
            $copy = clone $this; $copy->file = null; $copy->body = '';
            $length = !$this->bodyForbidden() && !$this->isEventStream() ? $this->representationLength() : ($this->status === 205 ? 0 : null);
            $copy->withoutHeader('Content-Length')->withoutHeader('Transfer-Encoding');
            $wire = $copy->nativeWire(); $wire = str_replace("\r\nContent-Length: 0\r\n", "\r\n", $wire);
            return $length === null ? $wire : substr($wire, 0, -2) . 'Content-Length: ' . $length . "\r\n\r\n";
        }
        if ($this->isEventStream()) {
            $copy = clone $this; $copy->body = ''; $copy->file = null;
            $copy->withoutHeader('Content-Length')->withoutHeader('Transfer-Encoding');
            return str_replace("\r\nContent-Length: 0\r\n", "\r\n", $copy->nativeWire());
        }
        if (isset($this->headers['Content-Length'], $this->headers['Transfer-Encoding'])) {
            $copy = clone $this; $copy->withoutHeader('Content-Length'); return $copy->nativeWire();
        }
        if ($this->file === null && isset($this->headers['Content-Length']) && !isset($this->headers['Transfer-Encoding'])) {
            $copy = clone $this; $copy->withoutHeader('Content-Length'); return $copy->nativeWire();
        }
        return parent::__toString();
    }

    /** Bypass only this adapter's override on a lightweight clone, retaining native HTTP serialization. */
    private function nativeWire(): string { return parent::__toString(); }
}
