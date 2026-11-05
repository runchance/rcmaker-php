<?php

declare(strict_types=1);

namespace RC\Helper\Curl;

require_once __DIR__ . '/bootstrap.php';

/**
 * PHP-Curl-Class integration with RC-owned request and resource cleanup.
 * Use curl() for a fresh client; HTTP, decoding and authentication stay upstream.
 */
class Curl extends \Curl\Curl
{
    private bool $downloadPrepared = false;
    private mixed $downloadRange = null;
    private int $downloadOffset = 0;
    private mixed $downloadLock = null;
    private bool $downloadLocked = false;
    private ?string $downloadTarget = null;
    private bool $segmentedDownloading = false;
    private ?DownloadIdentity $downloadIdentity = null;

    /** Keep the historical name while applying the same checked segmented-download path. */
    public function _fastDownload($url, $filename, $connections = 4)
    {
        return $this->fastDownload($url, $filename, $connections);
    }

    /**
     * Download one version through RC MultiCurl and atomically commit only verified byte ranges.
     * A server without a strong ETag, byte ranges or a positive length uses ordinary download().
     */
    public function fastDownload($url, $filename, $connections = 4)
    {
        if ($this->segmentedDownloading) {
            throw new \LogicException('Cannot reenter a segmented download on the same client.');
        }
        $multi = new MultiCurl($this->url);
        $probe = null;
        $this->segmentedDownloading = true;
        try {
            $multi->setConcurrency($connections);
            if (!is_string($filename) || $filename === '') {
                throw new \InvalidArgumentException('Segmented download destination must be a filename.');
            }
            if ((int) $connections === 1 || $this->hasPartialDownloadOptions()) {
                return $this->download($url, $filename);
            }
            $this->prepareDownload($url, $filename);
            $resolvedUrl = (string) $this->url;
            $target = $this->downloadTarget;
            $options = $this->segmentedOptions();
            $probe = new self(null, $options);
            $probe->remainingRetries = $this->remainingRetries;
            $probe->retryDecider = $this->retryDecider;
            $probe->head($resolvedUrl);
            if (($probe->error || $probe->httpStatusCode < 200 || $probe->httpStatusCode >= 300)
                && !in_array($probe->httpStatusCode, [405, 501], true)) {
                $this->copyDownloadResult($probe);
                $this->execDone();
                return false;
            }
            $length = filter_var((string) ($probe->responseHeaders['Content-Length'] ?? ''), FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]);
            $etag = trim((string) ($probe->responseHeaders['ETag'] ?? ''));
            if ($probe->error || $length === false || !$this->isStrongDownloadEtag($etag)
                || strtolower(trim((string) ($probe->responseHeaders['Accept-Ranges'] ?? ''))) !== 'bytes'
                || !in_array(strtolower(trim((string) ($probe->responseHeaders['Content-Encoding'] ?? ''))), ['', 'identity'], true)) {
                $this->releaseDownload();
                return $this->download($resolvedUrl, $target);
            }
            $this->copyDownloadResult($probe);
            $effectiveUrl = $probe->getInfo(CURLINFO_EFFECTIVE_URL);
            // A raw partial file has no proven version identity; segmented downloads always restart its contents.
            $this->downloadIdentity?->disable();
            $this->downloadOffset = 0;
            if (!ftruncate($this->fileHandle, 0) || fseek($this->fileHandle, 0) !== 0) {
                throw new \RuntimeException('Cannot reset the segmented download staging stream.');
            }
            $options[CURLOPT_HTTPHEADER] = array_values(array_filter($options[CURLOPT_HTTPHEADER],
                static fn(string $header): bool => !preg_match('/^If-Match:/i', $header)));
            $options[CURLOPT_HTTPHEADER][] = 'If-Match: ' . $etag;
            $multi->setOpts($options);
            $multi->error(function ($client) use ($multi): void {
                if (!$this->error) {
                    $this->copyDownloadResult($client);
                }
                $multi->stop();
            });
            $parts = min((int) $connections, $length);
            $chunk = intdiv($length, $parts);
            $extra = $length % $parts;
            $first = $completed = 0;
            for ($part = 0; $part < $parts; ++$part) {
                $size = $chunk + ($part < $extra ? 1 : 0);
                $last = $first + $size - 1;
                $client = $multi->addDownload($resolvedUrl, function ($client, $stream) use ($multi, $first, $last,
                    $length, $size, $etag, $effectiveUrl, &$completed): void {
                    $range = (string) ($client->responseHeaders['Content-Range'] ?? '');
                    $encoding = strtolower(trim((string) ($client->responseHeaders['Content-Encoding'] ?? '')));
                    if ($client->httpStatusCode !== 206 || $range !== "bytes $first-$last/$length"
                        || trim((string) ($client->responseHeaders['ETag'] ?? '')) !== $etag
                        || !in_array($encoding, ['', 'identity'], true) || fstat($stream)['size'] !== $size
                        || $client->getInfo(CURLINFO_EFFECTIVE_URL) !== $effectiveUrl) {
                        $this->copyDownloadResult($client);
                        $this->error = $this->curlError = true;
                        $this->errorCode = $this->curlErrorCode = CURLE_HTTP_RANGE_ERROR;
                        $this->errorMessage = $this->curlErrorMessage = 'Server did not return the requested download version and byte range.';
                        $multi->stop();
                        return;
                    }
                    if (fseek($this->fileHandle, $first) !== 0 || stream_copy_to_stream($stream, $this->fileHandle) !== $size) {
                        throw new \RuntimeException('Cannot assemble the segmented download.');
                    }
                    ++$completed;
                });
                $client->setRange($first . '-' . $last);
                $client->setOpt(CURLOPT_MAXFILESIZE_LARGE, $size);
                $client->remainingRetries = $this->remainingRetries;
                $client->retryDecider = $this->retryDecider;
                $first = $last + 1;
            }
            $multi->start();
            if (!$this->error && (!fflush($this->fileHandle) || $completed !== $parts
                || fstat($this->fileHandle)['size'] !== $length)) {
                throw new \RuntimeException('Segmented download did not complete every requested byte.');
            }
            $success = !$this->error;
            $this->execDone();
            return $success;
        } catch (\Throwable $error) {
            try {
                $this->releaseDownload(true);
            } catch (\Throwable) {
            }
            throw $error;
        } finally {
            try {
                $probe?->close();
            } finally {
                try {
                    $multi->close();
                } finally {
                    try {
                        $this->releaseDownload();
                    } finally {
                        $this->segmentedDownloading = false;
                    }
                }
            }
        }
    }

    /** Reject malformed URLs before upstream URL resolution emits PHP 8.5 notices. */
    public function setUrl($url, $mixed_data = '')
    {
        if ((!is_string($url) && !$url instanceof \Stringable) || parse_url((string) $url) === false) {
            throw new \InvalidArgumentException('Invalid request URL.');
        }
        parent::setUrl((string) $url, $mixed_data);
    }

    /** Fail closed for unsupported TLS/SOCKS policies before upstream stores an option. */
    public function setOpt($option, $value)
    {
        if (PHP_OS_FAMILY === 'Windows' && ($option === CURLOPT_SSLVERSION || $option === CURLOPT_PROXY_SSLVERSION)) {
            TlsOptions::assertSupportedVersion($value);
        }
        if ($option === CURLOPT_SOCKS5_AUTH) {
            ProxyOptions::assertAuthentication($value);
        }
        return parent::setOpt($option, $value);
    }

    /** Decorate only the internal header callback to preserve empty Cookie values before any user callbacks. */
    protected function setOptInternal($option, $value)
    {
        if ($option === CURLOPT_HEADERFUNCTION) {
            static $readState;
            $readState ??= \Closure::bind(static fn(\Curl\Curl $owner): object => $owner->headerCallbackData,
                null, \Curl\Curl::class);
            $data = $readState($this);
            $original = $value;
            $value = static function ($handle, string $header) use ($original, $data): int {
                $length = $original($handle, $header);
                if (strncasecmp($header, 'Set-Cookie:', 11) === 0
                    && preg_match('/^Set-Cookie:\s*([^=;\r\n]+)=[ \t]*(?:;|\r?\n?$)/i', $header, $cookie)) {
                    $data->responseCookies[trim($cookie[1])] = '';
                }
                return $length;
            };
        }
        return parent::setOptInternal($option, $value);
    }

    /** Configure DELETE without accidentally reusing a previous POST body. */
    public function setDelete($url, $query_parameters = [], $data = [])
    {
        $this->clearPreviousBody();
        parent::setDelete($url, $query_parameters, $data);
    }

    /** Configure a bodyless HEAD without retaining an earlier POST payload. */
    public function setHead($url, $data = [])
    {
        $this->clearPreviousBody();
        parent::setHead($url, $data);
    }

    /** OPTIONS data are query parameters, not permission to resend a previous body. */
    public function setOptions($url, $data = [])
    {
        $this->clearPreviousBody();
        parent::setOptions($url, $data);
    }

    /** Configure PUT, including an intentionally empty body after another request. */
    public function setPut($url, $data = [])
    {
        if (empty($this->getOpt(CURLOPT_INFILE)) && empty($this->getOpt(CURLOPT_INFILESIZE))) {
            $this->clearPreviousBody();
        }
        parent::setPut($url, $data);
    }

    /** Configure SEARCH without retaining the preceding request's data. */
    public function setSearch($url, $data = [])
    {
        if (empty($this->getOpt(CURLOPT_INFILE)) && empty($this->getOpt(CURLOPT_INFILESIZE))) {
            $this->clearPreviousBody();
        }
        parent::setSearch($url, $data);
    }

    /** Execute a prepared upstream GET and release its owned file even when a callback throws. */
    public function download($url, $mixed_filename)
    {
        try {
            $this->prepareDownload($url, $mixed_filename);
            $this->exec();
            return !$this->error;
        } catch (\Throwable $error) {
            try { $this->releaseDownload(true); } catch (\Throwable) {}
            throw $error;
        } finally {
            $this->releaseDownload();
        }
    }

    /**
     * Internal shared preparation for single and queued downloads, adapted from upstream 13.0.1.
     * Only file ownership is managed here; HTTP transfer and response parsing remain upstream.
     */
    public function prepareDownload($url, $mixed_filename): void
    {
        $this->releaseDownload();
        if (!is_callable($mixed_filename) && (!is_string($mixed_filename) || $mixed_filename === '')) {
            throw new \InvalidArgumentException('Download destination must be a filename or callback.');
        }
        $this->downloadRange = $this->getOpt(CURLOPT_RANGE);
        $this->downloadPrepared = true;
        try {
            $this->setGet($url);
            if (is_callable($mixed_filename)) {
                $this->fileHandle = tmpfile();
                $this->downloadCompleteCallback = $mixed_filename;
            } else {
                $filename = $mixed_filename;
                if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $filename) || str_contains($filename, "\0")) {
                    throw new \InvalidArgumentException('Download destination must be a regular local file.');
                }
                $directory = realpath(dirname($filename));
                if ($directory === false) {
                    throw new \RuntimeException('Download directory does not exist.');
                }
                $filename = $directory . DIRECTORY_SEPARATOR . basename($filename);
                $this->downloadTarget = $filename;
                $staging = $filename . '.pccdownload';
                $lockFile = $staging . '.lock';
                require_once __DIR__ . '/DownloadIdentity.php';
                foreach ([$filename, $staging, $lockFile, $staging . '.meta.json'] as $path) {
                    DownloadIdentity::assertRegular($path);
                }
                $this->downloadLock = @fopen($lockFile, 'c+b');
                $this->downloadLocked = is_resource($this->downloadLock) && flock($this->downloadLock, LOCK_EX | LOCK_NB);
                if (!$this->downloadLocked) {
                    throw new \RuntimeException('Download destination is busy or cannot be locked.');
                }
                $lockStat = fstat($this->downloadLock);
                if ($lockStat === false || ($lockStat['mode'] & 0170000) !== 0100000 || $lockStat['nlink'] !== 1) {
                    throw new \RuntimeException('Download lock must be a singly linked regular file.');
                }
                $this->downloadFileName = $staging;
                $this->fileHandle = @fopen($staging, is_file($staging) ? 'r+b' : 'x+b');
                if (!is_resource($this->fileHandle)) { throw new \RuntimeException('Cannot open the download staging stream.'); }
                $automatic = !$this->hasPartialDownloadOptions();
                $this->downloadIdentity = new DownloadIdentity($staging, (string) $this->url, $this->fileHandle, $automatic);
                $this->downloadOffset = $this->downloadIdentity->offset;
                $this->configureDownloadIdentity();
                $this->downloadCompleteCallback = static function ($client, $handle) use ($staging, $filename): void {
                    if (is_resource($handle)) {
                        fclose($handle);
                    }
                    $client->downloadIdentity?->remove();
                    if (!@rename($staging, $filename)) {
                        throw new \RuntimeException('Cannot replace the download destination.');
                    }
                };
            }
            if (!is_resource($this->fileHandle)) {
                throw new \RuntimeException('Cannot open the download staging stream.');
            }
            $this->setFileInternal($this->fileHandle);
        } catch (\Throwable $error) {
            try {
                $this->releaseDownload();
            } catch (\Throwable) {
            }
            throw $error;
        }
    }

    /** Reset a library-owned file before each transfer attempt so retries cannot append another response. */
    public function beginDownloadAttempt(): void
    {
        if (!$this->downloadPrepared) {
            return;
        }
        if ($this->downloadIdentity !== null) {
            $this->downloadIdentity->beginAttempt();
            $this->downloadOffset = $this->downloadIdentity->offset;
            if ($this->downloadIdentity->automaticRange && $this->downloadOffset === 0) {
                $this->setOptInternal(CURLOPT_RANGE, $this->downloadRange);
                $this->restoreDownloadOptions($this->downloadIdentity->savedOptions);
                $this->downloadIdentity->automaticRange = false;
            }
            return;
        }
        if (!is_resource($this->fileHandle) || !ftruncate($this->fileHandle, $this->downloadOffset)
            || fseek($this->fileHandle, $this->downloadOffset) !== 0) {
            throw new \RuntimeException('Cannot reset the download staging stream.');
        }
    }

    /** Run upstream transfers, preparing owned files only before real I/O, not after multi completion. */
    public function exec($ch = null)
    {
        if ($ch === null) {
            $this->beginDownloadAttempt();
        }
        return parent::exec($ch);
    }

    /** Retain upstream callback order while committing owned files before success and never casting STDOUT. */
    public function execDone()
    {
        if (!$this->downloadPrepared) {
            return parent::execDone();
        }
        $failed = true;
        $failure = null;
        try {
            $this->validateDownloadResponse();
            $failed = $this->error;
            if ($this->downloadFileName !== null && !$failed) {
                // Commit a file before success callbacks; a failed rename must never report success.
                $callback = $this->downloadCompleteCallback;
                $this->downloadCompleteCallback = null;
                rewind($this->fileHandle);
                $this->call($callback, $this->fileHandle);
                $this->releaseDownload();
            }
            $this->call($failed ? $this->errorCallback : $this->successCallback);
            $this->call($this->completeCallback);
            if ($this->downloadPrepared && !$failed) {
                rewind($this->fileHandle);
                $this->call($this->downloadCompleteCallback, $this->fileHandle);
            }
        } catch (\Throwable $error) {
            $failure = $error;
            throw $error;
        } finally {
            try { $this->releaseDownload($failed); }
            catch (\Throwable $cleanup) { if ($failure === null) { throw $cleanup; } }
        }
    }

    /** Close owned files and native handles; safe to call repeatedly. */
    public function close()
    {
        try {
            parent::close();
        } finally {
            try {
                $this->releaseDownload();
            } finally {
                $this->beforeSendCallback = null;
                $this->afterSendCallback = null;
                $this->successCallback = null;
                $this->errorCallback = null;
                $this->completeCallback = null;
                $this->retryDecider = null;
            }
        }
    }

    /** Reset is an explicit fresh-client boundary, including cached upstream options and cookies. */
    public function reset()
    {
        $this->close();
        foreach ([\Curl\BaseCurl::class, \Curl\Curl::class] as $class) {
            $reflection = new \ReflectionClass($class);
            foreach ($reflection->getProperties() as $property) {
                if (!$property->isStatic() && $property->getDeclaringClass()->getName() === $class) {
                    $property->setValue($this, $property->getDefaultValue());
                }
            }
        }
        parent::__construct();
    }

    /** Clear only method/body state; keep configured headers, cookies and authentication. */
    private function clearPreviousBody(): void
    {
        $this->setOptInternal(CURLOPT_POSTFIELDS, null);
        $this->setOptInternal(CURLOPT_HTTPGET, true);
    }

    /** Attach the identity observer only to owned file downloads, without retaining a strong Curl reference. */
    private function configureDownloadIdentity(): void
    {
        if (!$this->downloadIdentity->needsHeaders()) { return; }
        $this->downloadIdentity->headerFunction = $this->getOpt(CURLOPT_HEADERFUNCTION);
        $original = $this->downloadIdentity->headerFunction;
        $owner = \WeakReference::create($this);
        parent::setOptInternal(CURLOPT_HEADERFUNCTION, static function ($handle, string $line) use ($original, $owner): int {
            $length = $original($handle, $line);
            $client = $owner->get();
            if ($length !== strlen($line) || $client === null || $client->downloadIdentity === null) { return $length; }
            $block = $client->downloadIdentity->headerBlock($line);
            if ($block === null) { return $length; }
            static $parse;
            $parse ??= \Closure::bind(static fn(\Curl\Curl $client, string $raw): \Curl\CaseInsensitiveArray => $client->parseResponseHeaders($raw), null, \Curl\Curl::class);
            $accepted = $client->downloadIdentity->acceptHeaders($parse($client, $block), (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL));
            $client->downloadOffset = $client->downloadIdentity->offset;
            return $accepted ? $length : 0;
        });
        $tag = $this->downloadIdentity->resumeTag();
        if ($tag === null || $this->downloadOffset === 0) { return; }
        $headers = $this->getOpt(CURLOPT_HTTPHEADER) ?? [];
        foreach ([CURLOPT_HTTPHEADER => $headers, CURLOPT_ENCODING => $this->getOpt(CURLOPT_ENCODING),
            CURLOPT_HTTP_CONTENT_DECODING => $this->getOpt(CURLOPT_HTTP_CONTENT_DECODING) ?? true] as $option => $value) {
            $this->downloadIdentity->savedOptions[$option] = [$value, array_key_exists($option, $this->options)];
        }
        $headers = array_values(array_filter($headers, static fn(string $header): bool => !preg_match('/^(?:Accept-Encoding|If-Range):/i', ltrim($header))));
        $headers[] = 'If-Range: ' . $tag;
        $headers[] = 'Accept-Encoding: identity';
        $this->setOptInternal(CURLOPT_HTTPHEADER, $headers);
        $this->setOptInternal(CURLOPT_ENCODING, null);
        $this->setOptInternal(CURLOPT_HTTP_CONTENT_DECODING, false);
        $this->setRangeInternal($this->downloadOffset . '-');
        $this->downloadIdentity->automaticRange = true;
    }

    /** Restore effective native defaults as well as absence from upstream's visible option bookkeeping. */
    private function restoreDownloadOptions(array $saved): void
    {
        $failure = null;
        foreach ($saved as $option => [$value, $present]) {
            try {
                $this->setOptInternal($option, $value);
                if (!$present) { unset($this->options[$option]); }
            } catch (\Throwable $error) { $failure ??= $error; }
        }
        if ($failure !== null) { throw $failure; }
    }

    /** Copy caller transport options, excluding another transfer's method, input/output stream and range. */
    private function segmentedOptions(): array
    {
        $options = $this->getUserSetOptions();
        foreach ([CURLOPT_CUSTOMREQUEST, CURLOPT_HTTPGET, CURLOPT_POST, CURLOPT_POSTFIELDS, CURLOPT_NOBODY,
            CURLOPT_UPLOAD, CURLOPT_INFILE, CURLOPT_INFILESIZE, CURLOPT_FILE, CURLOPT_RANGE] as $option) {
            unset($options[$option]);
        }
        $headers = array_values(array_filter($options[CURLOPT_HTTPHEADER] ?? [],
            static fn(string $header): bool => !preg_match('/^(?:Accept-Encoding|If-Range|Range):/i', $header)));
        $headers[] = 'Accept-Encoding: identity';
        $options[CURLOPT_HTTPHEADER] = $headers;
        $options[CURLOPT_ENCODING] = null;
        $options[CURLOPT_HTTP_CONTENT_DECODING] = false;
        return $options;
    }

    /** Preserve explicitly requested partial/continued transfers rather than replacing their range with full-file chunks. */
    private function hasPartialDownloadOptions(): bool
    {
        $range = $this->getOpt(CURLOPT_RANGE);
        if ($range !== null && $range !== '') {
            return true;
        }
        foreach ([CURLOPT_RESUME_FROM, defined('CURLOPT_RESUME_FROM_LARGE') ? constant('CURLOPT_RESUME_FROM_LARGE') : CURLOPT_RESUME_FROM] as $option) {
            if (($this->getOpt($option) ?? 0) !== 0) {
                return true;
            }
        }
        foreach ($this->getOpt(CURLOPT_HTTPHEADER) ?? [] as $header) {
            if (preg_match('/^(?:Range|If-Range):/i', ltrim($header))) {
                return true;
            }
        }
        return false;
    }

    /** A strong opaque ETag is required before combining responses fetched at different times. */
    private function isStrongDownloadEtag(string $etag): bool
    {
        return preg_match('/^"[\x21\x23-\x7e\x80-\xff]*"$/D', $etag) === 1;
    }

    /** Expose the logical download's result without leaving diagnostics cached from a preceding request. */
    private function copyDownloadResult(\Curl\Curl $source): void
    {
        foreach (['error', 'errorCode', 'errorMessage', 'curlError', 'curlErrorCode', 'curlErrorMessage', 'httpError',
            'httpStatusCode', 'httpErrorMessage', 'requestHeaders', 'responseHeaders', 'rawResponseHeaders',
            'responseCookies', 'response', 'rawResponse'] as $property) {
            $this->$property = $source->$property;
        }
        static $clear;
        $clear ??= \Closure::bind(static function (\Curl\Curl $owner, \Curl\Curl $source): void {
            unset($owner->deferredValues['curlErrorCodeConstant']);
            $owner->deferredValues['effectiveUrl'] = $source->getInfo(CURLINFO_EFFECTIVE_URL);
            $owner->deferredValues['totalTime'] = $source->getInfo(CURLINFO_TOTAL_TIME);
        }, null, \Curl\Curl::class);
        $clear($this, $source);
    }

    /** Release download-owned streams without closing caller-owned setFile() streams. */
    private function releaseDownload(bool $discardAttempt = false): void
    {
        if (!$this->downloadPrepared && $this->fileHandle === null && $this->downloadCompleteCallback === null
            && $this->downloadLock === null && $this->downloadIdentity === null) {
            return;
        }
        $handle = $this->fileHandle;
        $lock = $this->downloadLock;
        $locked = $this->downloadLocked;
        $identity = $this->downloadIdentity;
        $offset = $identity?->offset ?? $this->downloadOffset;
        $staging = $this->downloadFileName;
        $headerFunction = $identity?->headerFunction;
        $savedOptions = $identity?->savedOptions ?? [];
        $this->downloadIdentity = null;
        if ($identity !== null) { $identity->headerFunction = null; $identity->savedOptions = []; $identity->automaticRange = false; }
        $this->downloadLock = null;
        $this->downloadLocked = false;
        $this->fileHandle = null;
        $this->downloadCompleteCallback = null;
        $this->downloadFileName = null;
        $this->downloadTarget = null;
        $range = $this->downloadRange;
        $this->downloadRange = null;
        $this->downloadPrepared = false;
        $this->downloadOffset = 0;
        $failure = null;
        if ($this->curl instanceof \CurlHandle) {
            try {
                if ($headerFunction !== null) { parent::setOptInternal(CURLOPT_HEADERFUNCTION, $headerFunction); }
            } catch (\Throwable $error) { $failure ??= $error; }
            try { $this->restoreDownloadOptions($savedOptions); } catch (\Throwable $error) { $failure ??= $error; }
            try {
                $this->setOptInternal(CURLOPT_RANGE, $range);
            } catch (\Throwable $error) { $failure ??= $error; }
            try {
                // Casting STDOUT to libcurl's FILE* can rewind redirected CLI logs in static PHP builds.
                $output = fopen('php://temp', 'w+b');
                if (!is_resource($output)) { throw new \RuntimeException('Cannot restore the cURL output stream.'); }
                $this->setOptInternal(CURLOPT_FILE, $output);
                $this->setOptInternal(CURLOPT_RETURNTRANSFER, true);
            } catch (\Throwable $error) { $failure ??= $error; }
        }
        $discarded = !$discardAttempt;
        try {
            if ($discardAttempt) {
                if ($identity !== null && is_resource($handle)) { $identity->rollback(); }
                elseif (is_resource($handle) && !ftruncate($handle, $offset)) { throw new \RuntimeException('Cannot discard the failed download attempt.'); }
                elseif ($identity !== null && !is_resource($handle)) { $identity->remove(); }
                $discarded = true;
            }
        } catch (\Throwable $error) { $failure ??= $error; }
        finally {
            try {
                if (is_resource($handle) && !fclose($handle)) { throw new \RuntimeException('Cannot close the download staging stream.'); }
            } catch (\Throwable $error) { $failure ??= $error; }
        }
        try {
            if ($discardAttempt && $discarded && $offset === 0 && $staging !== null && is_file($staging) && !unlink($staging)) {
                throw new \RuntimeException('Cannot remove the failed download staging file.');
            }
        } catch (\Throwable $error) { $failure ??= $error; }
        if (is_resource($lock)) {
            try {
                if ($locked && !flock($lock, LOCK_UN)) { throw new \RuntimeException('Cannot unlock the download target.'); }
            } catch (\Throwable $error) { $failure ??= $error; }
            finally {
                try {
                    if (!fclose($lock)) { throw new \RuntimeException('Cannot close the download lock.'); }
                } catch (\Throwable $error) { $failure ??= $error; }
            }
        }
        if ($failure !== null) { throw $failure; }
    }

    /** Refuse an automatic resume unless the server confirmed its representation and exact remaining bytes. */
    private function validateDownloadResponse(): void
    {
        if ($this->downloadIdentity !== null && is_resource($this->fileHandle) && !fflush($this->fileHandle)) {
            throw new \RuntimeException('Cannot flush the completed download staging stream.');
        }
        if ($this->downloadIdentity !== null && (!$this->error || $this->downloadIdentity->failure !== null)) {
            $message = $this->downloadIdentity->finalError($this->httpStatusCode, $this->responseHeaders, (string) $this->getInfo(CURLINFO_EFFECTIVE_URL));
            if ($message !== null) {
                $this->error = $this->curlError = true;
                $this->errorCode = $this->curlErrorCode = CURLE_HTTP_RANGE_ERROR;
                $this->errorMessage = $this->curlErrorMessage = $message;
            }
        }
        if ($this->downloadPrepared && !$this->error && $this->httpStatusCode > 0
            && ($this->httpStatusCode < 200 || $this->httpStatusCode >= 300)) {
            $this->error = $this->httpError = true;
            $this->errorCode = $this->httpStatusCode;
            $this->errorMessage = $this->httpErrorMessage = 'Download did not return a successful HTTP status.';
        }
        if (!$this->downloadPrepared || $this->downloadOffset === 0 || $this->error) {
            return;
        }
        $range = (string) ($this->responseHeaders['Content-Range'] ?? '');
        $valid = $this->httpStatusCode === 206 && preg_match('~^bytes (\d+)-(\d+)/(\d+)$~D', $range, $parts) === 1;
        if ($valid) {
            $numbers = array_map(static fn(string $value): mixed => filter_var($value, FILTER_VALIDATE_INT), array_slice($parts, 1));
            [$first, $last, $total] = $numbers;
            $size = is_resource($this->fileHandle) ? fstat($this->fileHandle)['size'] : -1;
            $valid = !in_array(false, $numbers, true) && $first === $this->downloadOffset
                && $last >= $first && $last === $total - 1 && $size === $total;
        }
        if (!$valid) {
            $this->error = $this->curlError = true;
            $this->errorCode = $this->curlErrorCode = CURLE_HTTP_RANGE_ERROR;
            $this->errorMessage = $this->curlErrorMessage = 'Server did not return a valid complete download range.';
        }
    }
}

/** Preserve the historical function entry point; response-header parsing remains upstream. */
function createHeaderCallback($header_callback_data)
{
    return \Curl\createHeaderCallback($header_callback_data);
}

/** Preserve the historical cancellation callback factory without retaining a Curl owner. */
function createStopRequestFunction($header_callback_data)
{
    return \Curl\createStopRequestFunction($header_callback_data);
}
