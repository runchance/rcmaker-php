<?php

declare(strict_types=1);

namespace RC\Helper\Curl;

/** Internal file-download identity; HTTP parsing and transfer still belong to PHP-Curl-Class/libcurl. */
final class DownloadIdentity
{
    public int $offset = 0;
    public ?string $failure = null;
    public mixed $headerFunction = null;
    public array $savedOptions = [];
    public bool $automaticRange = false;
    private readonly string $path;
    private readonly string $source;
    private mixed $stream;
    private ?array $baseline = null;
    private ?array $current = null;
    private bool $present = false;
    private bool $active;
    private string $headers = '';
    private bool $headerBlock = false;

    /** Adopt only a partial regular file whose own record matches its inode and resolved request URL. */
    public function __construct(string $staging, string $url, mixed $stream, bool $automatic)
    {
        $this->path = $staging . '.meta.json';
        $this->source = hash('sha256', $url);
        $this->stream = $stream;
        $this->active = $automatic && preg_match('~^https?://~i', $url) === 1;
        $stat = fstat($stream);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1) {
            throw new \RuntimeException('Download staging must be a singly linked regular file.');
        }
        $record = $this->read();
        if ($this->active && $record !== null && $record['source'] === $this->source
            && $record['device'] === $stat['dev'] && $record['inode'] === $stat['ino']
            && $stat['size'] > 0 && $stat['size'] < $record['total']) {
            $this->baseline = $record;
            $this->offset = $stat['size'];
        } else {
            $this->remove();
            if ($stat['size'] > 0 && (!ftruncate($stream, 0) || fseek($stream, 0) !== 0)) {
                throw new \RuntimeException('Cannot restart an unverified download staging file.');
            }
        }
    }

    /** Reject wrappers, links and special files before fopen can block on a FIFO or mutate a device. */
    public static function assertRegular(string $path): void
    {
        clearstatcache(true, $path);
        if (is_link($path) || file_exists($path) && !is_file($path)) {
            throw new \InvalidArgumentException('Download paths must be regular local files.');
        }
    }

    /** Return only a strong validator generated from a previously accepted representation. */
    public function resumeTag(): ?string
    {
        return $this->baseline === null ? null : base64_decode($this->baseline['etag_base64'], true);
    }

    /** Explicit caller ranges and non-HTTP files do not install automatic-identity header observers. */
    public function needsHeaders(): bool
    {
        return $this->active;
    }

    /** Reset every attempt before native I/O; failed response bodies can never become a future prefix. */
    public function beginAttempt(): void
    {
        $this->rollback();
        $this->current = null;
        $this->failure = null;
        $this->headers = '';
        $this->headerBlock = false;
        if (fseek($this->stream, $this->offset) !== 0) { throw new \RuntimeException('Cannot seek the download staging stream.'); }
    }

    /** Delimit status/header blocks only; field parsing remains the upstream parser, and trailers are ignored. */
    public function headerBlock(string $line): ?string
    {
        if (str_starts_with($line, 'HTTP/')) { $this->headers = ''; $this->headerBlock = true; }
        if (!$this->headerBlock) { return null; }
        $this->headers .= $line;
        if ($line !== "\r\n" && $line !== "\n") { return null; }
        $this->headerBlock = false;
        return $this->headers;
    }

    /** Validate or invalidate a record before response bytes reach the native file writer. */
    public function acceptHeaders(\Curl\CaseInsensitiveArray $headers, int $status, string $effectiveUrl): bool
    {
        if (!$this->active || $status < 200) { return true; }
        if ($status === 206 && $this->baseline !== null && $this->offset > 0) {
            if (!$this->matches($this->baseline, $headers, $effectiveUrl, $this->offset)) {
                $this->failure = 'Server did not confirm the saved download version, URL and byte range.';
                $this->remove();
                return false;
            }
            $this->current = $this->baseline;
            if (!$this->present) { $this->publish($this->baseline); }
            return true;
        }
        $this->remove();
        $this->current = null;
        if ($status !== 200 && $status !== 206) { return true; }
        $encoding = strtolower(trim((string) ($headers['Content-Encoding'] ?? '')));
        if ($this->offset > 0 && !in_array($encoding, ['', 'identity'], true)) {
            $this->failure = 'Server returned encoded content for an identity download continuation.';
            return false;
        }
        if ($status === 200) {
            // If-Range mismatch (or an ignored Range) returns a complete body, never an appendable suffix.
            if ($this->offset > 0 && (!ftruncate($this->stream, 0) || fseek($this->stream, 0) !== 0)) {
                throw new \RuntimeException('Cannot restart the changed download representation.');
            }
            $this->offset = 0;
            $this->baseline = null;
        }
        $total = filter_var((string) ($headers['Content-Length'] ?? ''), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($status === 206) {
            $range = self::range((string) ($headers['Content-Range'] ?? ''));
            if ($range === null || $range[0] !== 0 || $range[1] !== $range[2] - 1) {
                $this->failure = 'Server returned an unsolicited incomplete download range.';
                return false;
            }
            $total = $range[2];
        }
        $tag = trim((string) ($headers['ETag'] ?? ''));
        if ($total === false || !self::strongTag($tag) || !in_array($encoding, ['', 'identity'], true)) { return true; }
        $stat = fstat($this->stream);
        if ($stat === false || $stat['ino'] === 0) { return true; }
        $this->current = ['version' => 1, 'source' => $this->source, 'effective' => hash('sha256', $effectiveUrl),
            'etag_base64' => base64_encode($tag), 'total' => $total, 'device' => $stat['dev'], 'inode' => $stat['ino']];
        $this->publish($this->current);
        return true;
    }

    /** Confirm the final native result again, including hooks replaced by advanced caller configuration. */
    public function finalError(int $status, \Curl\CaseInsensitiveArray $headers, string $effectiveUrl): ?string
    {
        if (!$this->active) { return null; }
        if ($this->failure !== null) { return $this->failure; }
        if ($this->offset > 0 && ($status !== 206 || $this->baseline === null
            || !$this->matches($this->baseline, $headers, $effectiveUrl, $this->offset))) {
            return 'Server did not confirm the saved download version, URL and byte range.';
        }
        if ($this->current !== null) {
            $stat = fstat($this->stream);
            if ($stat === false || $stat['size'] !== $this->current['total']) { return 'Download length does not match its confirmed representation.'; }
        }
        return null;
    }

    /** Restore only the previously verified prefix; invalidate metadata if rollback itself fails. */
    public function rollback(): void
    {
        try {
            if (!ftruncate($this->stream, $this->offset) || !fflush($this->stream)) {
                throw new \RuntimeException('Cannot discard the failed download attempt.');
            }
            if ($this->offset > 0 && $this->baseline !== null) {
                if (!$this->present) { $this->publish($this->baseline); }
            } else { $this->remove(); }
        } catch (\Throwable $error) {
            try { $this->remove(); } catch (\Throwable) {}
            throw $error;
        }
    }

    /** Segmented transfers use their own per-part validator; never retain an ordinary-resume record. */
    public function disable(): void
    {
        $this->remove(); $this->active = false; $this->offset = 0; $this->baseline = $this->current = null;
    }

    /** Remove the owned record before publishing a final target, never after a successful rename. */
    public function remove(): void
    {
        if (!$this->present) { return; }
        self::assertRegular($this->path);
        if (is_file($this->path) && !unlink($this->path)) { throw new \RuntimeException('Cannot remove download identity metadata.'); }
        $this->present = false;
    }

    /** Parse a bounded, explicit schema; malformed records fail without destroying unknown caller data. */
    private function read(): ?array
    {
        self::assertRegular($this->path);
        if (!is_file($this->path)) { return null; }
        $bytes = file_get_contents($this->path, false, null, 0, 4097);
        if ($bytes === false || strlen($bytes) > 4096) { throw new \RuntimeException('Cannot read bounded download identity metadata.'); }
        try { $record = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR); }
        catch (\JsonException $error) { throw new \RuntimeException('Invalid download identity metadata.', 0, $error); }
        if (!is_array($record) || ($record['version'] ?? null) !== 1 || !is_int($record['total'] ?? null) || $record['total'] < 1
            || !is_int($record['device'] ?? null) || !is_int($record['inode'] ?? null)
            || !is_string($record['source'] ?? null) || !preg_match('/\A[0-9a-f]{64}\z/D', $record['source'])
            || !is_string($record['effective'] ?? null) || !preg_match('/\A[0-9a-f]{64}\z/D', $record['effective'])
            || !is_string($record['etag_base64'] ?? null) || !self::strongTag(base64_decode($record['etag_base64'], true))) {
            throw new \RuntimeException('Invalid download identity metadata.');
        }
        $this->present = true;
        return $record;
    }

    /** Publish complete metadata beside staging, with exclusive temporary ownership and no pre-delete. */
    private function publish(array $record): void
    {
        self::assertRegular($this->path);
        $temporary = $this->path . '.' . bin2hex(random_bytes(12)) . '.tmp';
        $handle = fopen($temporary, 'x+b');
        if (!is_resource($handle)) { throw new \RuntimeException('Cannot create download identity metadata.'); }
        $failure = null;
        try {
            $bytes = json_encode($record, JSON_THROW_ON_ERROR) . "\n";
            if (!chmod($temporary, 0600) || fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)) {
                throw new \RuntimeException('Cannot write complete download identity metadata.');
            }
            if (!fclose($handle)) { throw new \RuntimeException('Cannot close download identity metadata.'); }
            $handle = null;
            if (!rename($temporary, $this->path)) { throw new \RuntimeException('Cannot publish download identity metadata.'); }
            $this->present = true;
        } catch (\Throwable $error) { $failure = $error; throw $error; }
        finally {
            $cleanupFailure = null;
            try {
                if (is_resource($handle) && !fclose($handle)) { throw new \RuntimeException('Cannot close temporary download metadata.'); }
            } catch (\Throwable $cleanup) { $cleanupFailure = $cleanup; }
            try {
                if (is_file($temporary) && !unlink($temporary)) { throw new \RuntimeException('Cannot clean temporary download metadata.'); }
            } catch (\Throwable $cleanup) { $cleanupFailure ??= $cleanup; }
            if ($failure === null && $cleanupFailure !== null) { throw $cleanupFailure; }
        }
    }

    /** Match one complete suffix using the same strong validator and effective resource identity. */
    private function matches(array $record, \Curl\CaseInsensitiveArray $headers, string $url, int $offset): bool
    {
        $range = self::range((string) ($headers['Content-Range'] ?? ''));
        return $range !== null && $range === [$offset, $record['total'] - 1, $record['total']]
            && trim((string) ($headers['ETag'] ?? '')) === base64_decode($record['etag_base64'], true)
            && hash('sha256', $url) === $record['effective']
            && in_array(strtolower(trim((string) ($headers['Content-Encoding'] ?? ''))), ['', 'identity'], true);
    }

    /** Accept strong opaque tags as bytes, encoding them only when writing JSON. */
    private static function strongTag(mixed $tag): bool
    {
        return is_string($tag) && strlen($tag) <= 1024 && preg_match('/^"[\x21\x23-\x7e\x80-\xff]*"$/D', $tag) === 1;
    }

    /** Parse bounded decimal byte positions without lossy integer casts. */
    private static function range(string $value): ?array
    {
        if (!preg_match('~\Abytes (\d+)-(\d+)/(\d+)\z~D', $value, $matches)) { return null; }
        $numbers = array_map(static fn(string $number): mixed => filter_var($number, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]), array_slice($matches, 1));
        return in_array(false, $numbers, true) || $numbers[2] < 1 || $numbers[1] < $numbers[0] ? null : $numbers;
    }
}
