<?php

declare(strict_types=1);

namespace RC\Helper\Curl;

require_once __DIR__ . '/bootstrap.php';

/**
 * PHP-Curl-Class 13 integration with RC factories, bounded I/O waiting and deterministic cancellation.
 * Options, headers, cookies, parsing and quota calculation remain upstream.
 */
class MultiCurl extends \Curl\MultiCurl
{
    private ?MultiState $state = null;
    private ?\Curl\Curl $dispatching = null;
    private array $pendingDownloads = [];

    /** Create one independent queue; start() remains a blocking call, not a Workerman coroutine API. */
    public function __construct($base_url = null)
    {
        if (!extension_loaded('curl')) {
            throw new \ErrorException('cURL library is not loaded');
        }
        parent::__construct($base_url);
        $this->state = new MultiState($this);
    }

    /** Queue GET with the existing base-URL array shorthand. */
    public function addGet($url, $data = [])
    {
        $this->normalizeArguments($url, $data);
        return $this->queueRequest('setGet', $url, [$data], $data);
    }

    /** Queue HEAD while retaining the upstream request implementation. */
    public function addHead($url, $data = [])
    {
        $this->normalizeArguments($url, $data);
        return $this->queueRequest('setHead', $url, [$data], $data);
    }

    /** Queue OPTIONS and its query parameters. */
    public function addOptions($url, $data = [])
    {
        $this->normalizeArguments($url, $data);
        return $this->queueRequest('setOptions', $url, [$data], $data);
    }

    /** Queue POST; the optional flag retains upstream 303 handling. */
    public function addPost($url, $data = '', $follow_303_with_post = false)
    {
        if (is_array($url)) {
            $follow_303_with_post = (bool) $data;
            $data = $url;
            $url = $this->baseUrl;
        }
        return $this->queueRequest('setPost', $url, [$data, $follow_303_with_post]);
    }

    /** Queue PUT, including literal zero bodies. */
    public function addPut($url, $data = [])
    {
        $this->normalizeArguments($url, $data);
        return $this->queueRequest('setPut', $url, [$data]);
    }

    /** Queue PATCH using upstream encoding and RC lifecycle cleanup. */
    public function addPatch($url, $data = [])
    {
        $this->normalizeArguments($url, $data);
        return $this->queueRequest('setPatch', $url, [$data]);
    }

    /** Queue SEARCH using the same body preparation as a single request. */
    public function addSearch($url, $data = [])
    {
        $this->normalizeArguments($url, $data);
        return $this->queueRequest('setSearch', $url, [$data]);
    }

    /** Queue DELETE without adding an empty body or dropping the string "0". */
    public function addDelete($url, $query_parameters = [], $data = [])
    {
        if (is_array($url)) {
            $data = $query_parameters;
            $query_parameters = $url;
            $url = $this->baseUrl;
        }
        return $this->queueRequest('setDelete', $url, [$query_parameters, $data], $query_parameters);
    }

    /** Queue a download; its owned file resources follow the same cleanup as single requests. */
    public function addDownload($url, $mixed_filename)
    {
        if (!is_callable($mixed_filename) && (!is_string($mixed_filename) || $mixed_filename === '')) {
            throw new \InvalidArgumentException('Download destination must be a filename or callback.');
        }
        $curl = $this->queueRequest('setGet', $url, [[]]);
        $this->pendingDownloads[$curl->id] = $mixed_filename;
        return $curl;
    }

    /** Accept upstream or RC clients once only; closed or already-owned clients cannot be queued. */
    public function addCurl(\Curl\Curl $curl)
    {
        $this->assertOpen();
        if (!$curl->curl instanceof \CurlHandle) {
            throw new \InvalidArgumentException('Cannot queue a closed cURL client.');
        }
        if ($curl->childOfMultiCurl) {
            throw new \LogicException('A cURL client already belongs to a multi queue.');
        }
        return parent::addCurl($curl);
    }

    /** Preserve RC's positive-integer concurrency validation, including digit-string configuration. */
    public function setConcurrency($concurrency)
    {
        if (!is_int($concurrency) && !(is_string($concurrency) && ctype_digit($concurrency))) {
            throw new \InvalidArgumentException('concurrency must be an integer greater than 0');
        }
        $value = filter_var(ltrim((string) $concurrency, '0'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($value === false) {
            throw new \InvalidArgumentException('concurrency must be an integer greater than 0');
        }
        parent::setConcurrency($value);
    }

    /** Preserve RC's nonzero rate-limit validation and reject integer overflow before upstream sleep. */
    public function setRateLimit($rate_limit)
    {
        if (!is_string($rate_limit) || !preg_match('~^(\d+)/(\d*)([smh])$~i', $rate_limit, $parts)) {
            throw new \UnexpectedValueException('rate limit must be formatted like "60/1m"');
        }
        $requests = filter_var(ltrim($parts[1], '0'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $interval = $parts[2] === '' ? 1 : filter_var(ltrim($parts[2], '0'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $factor = match (strtolower($parts[3])) { 's' => 1, 'm' => 60, 'h' => 3600 };
        if ($requests === false || $interval === false || $interval > intdiv(PHP_INT_MAX, $factor)) {
            throw new \UnexpectedValueException('rate limit must use positive, bounded integer values');
        }
        parent::setRateLimit($rate_limit);
    }

    /** Validate URL syntax while preserving upstream base/relative resolution. */
    public function setUrl($url, $mixed_data = '')
    {
        if ((!is_string($url) && !$url instanceof \Stringable) || parse_url((string) $url) === false) {
            throw new \InvalidArgumentException('Invalid request URL.');
        }
        parent::setUrl((string) $url, $mixed_data);
    }

    /** Validate native TLS/SOCKS policy and capture URL options by stable request IDs, not shifted array keys. */
    public function setOpt($option, $value)
    {
        // Match upstream option-array keys before interpreting TLS or per-request URL settings.
        if (!is_int($option)) {
            $option = array_key_first([$option => null]);
        }
        if (PHP_OS_FAMILY === 'Windows' && ($option === CURLOPT_SSLVERSION || $option === CURLOPT_PROXY_SSLVERSION)) {
            TlsOptions::assertSupportedVersion($value);
        }
        if ($option === CURLOPT_SOCKS5_AUTH) {
            ProxyOptions::assertAuthentication($value);
        }
        if ($option !== CURLOPT_URL) {
            parent::setOpt($option, $value);
            return;
        }
        $this->options[$option] = $value;
        if ($this->state !== null) {
            foreach ($this->state->queued as $curl) {
                if (!isset($this->state->instanceOptions[$curl->id][$option])) {
                    $this->state->instanceOptions[$curl->id][$option] = $value;
                }
            }
        }
    }

    /**
     * Drain the queue using upstream initialization and completion rules.
     * The loop adapts upstream 13.0.1 start(), retaining its Unlicense provenance.
     */
    public function start()
    {
        $this->assertOpen();
        $state = $this->state;
        if ($state->running) {
            return;
        }
        $state->running = true;
        $this->startTime = $state->windowStart = microtime(true);
        $state->requestCount = 0;
        try {
            while ($state->running) {
                while ($state->running && $state->queued !== [] && count($this->activeCurls) < $state->concurrency
                    && (!$state->rateLimited || $state->hasQuota($this))) {
                    $next = reset($state->queued);
                    if ($next instanceof Curl) {
                        if (array_key_exists($next->id, $this->pendingDownloads)) {
                            $destination = $this->pendingDownloads[$next->id];
                            unset($this->pendingDownloads[$next->id]);
                            $next->prepareDownload((string) $next->url, $destination);
                        }
                        $next->beginDownloadAttempt();
                    }
                    $state->initialize($this);
                }
                if (!$state->running || !$this->multiCurl instanceof \CurlMultiHandle) {
                    break;
                }
                if (count($this->activeCurls) === 0) {
                    if ($state->queued === []) {
                        break;
                    }
                    $state->waitForQuota($this);
                    continue;
                }
                do {
                    $result = curl_multi_exec($this->multiCurl, $active);
                } while ($result === CURLM_CALL_MULTI_PERFORM);
                if ($result !== CURLM_OK) {
                    throw new \RuntimeException('cURL multi execution failed: ' . curl_multi_strerror($result));
                }
                while ($state->running && $this->multiCurl instanceof \CurlMultiHandle
                    && ($info = curl_multi_info_read($this->multiCurl)) !== false) {
                    if ($info['msg'] === CURLMSG_DONE && isset($this->activeCurls[$info['handle']])) {
                        $this->completeRequest($info['handle'], $info['result']);
                    }
                }
                if (!$state->running) {
                    break;
                }
                $canStart = $state->queued !== [] && count($this->activeCurls) < $state->concurrency
                    && (!$state->rateLimited || $state->hasQuota($this));
                if (count($this->activeCurls) > 0 && !$canStart) {
                    // Execute first; only wait when there is outstanding I/O and no immediately fillable slot.
                    if (curl_multi_select($this->multiCurl, 0.2) === -1) {
                        usleep(1000);
                    }
                }
            }
        } catch (\Throwable $error) {
            try {
                $this->stop();
            } catch (\Throwable) {
                // Preserve the request/callback exception after attempting every resource release.
            }
            throw $error;
        } finally {
            $state->running = false;
            if ($state->queued === []) {
                $state->instanceOptions = [];
            }
            $this->stopTime = microtime(true);
        }
    }

    /** Cancel pending/active requests without delivering success/error callbacks for cancelled work. */
    public function stop()
    {
        if ($this->state === null) {
            return;
        }
        $this->state->running = false;
        $this->pendingDownloads = [];
        $failure = null;
        $queued = $this->state->queued;
        $this->state->queued = [];
        foreach ($queued as $curl) {
            try {
                $this->closeRequest($curl);
            } catch (\Throwable $error) {
                $failure ??= $error;
            }
        }
        foreach ($this->activeCurls as $handle => $curl) {
            unset($this->activeCurls[$handle]);
            try {
                if ($this->multiCurl instanceof \CurlMultiHandle) {
                    curl_multi_remove_handle($this->multiCurl, $handle);
                }
                if ($curl !== $this->dispatching) {
                    $this->closeRequest($curl);
                }
            } catch (\Throwable $error) {
                $failure ??= $error;
            }
        }
        $this->state->instanceOptions = [];
        $this->stopTime = microtime(true);
        if ($failure !== null) {
            throw $failure;
        }
    }

    /** Detach remaining native handles and release the group reference; repeated calls are harmless. */
    public function close()
    {
        try {
            $this->stop();
        } finally {
            if ($this->multiCurl instanceof \CurlMultiHandle) {
                curl_multi_close($this->multiCurl);
            }
            $this->multiCurl = null;
            $this->beforeSendCallback = $this->afterSendCallback = $this->successCallback = null;
            $this->errorCallback = $this->completeCallback = null;
            $this->options = $this->userSetOptions = [];
            $this->state?->releaseConfiguration($this);
        }
    }

    /** Destructor cleanup must not mask an unrelated application exception. */
    public function __destruct()
    {
        try {
            $this->close();
        } catch (\Throwable) {
            error_log('RCMAKER: failed to release a cURL multi resource.');
        }
    }

    /** Normalize the historical base-URL shorthand shared by GET/HEAD/OPTIONS/PUT/PATCH/SEARCH. */
    private function normalizeArguments(&$url, &$data): void
    {
        if (is_array($url)) {
            $data = $url;
            $url = $this->baseUrl;
        }
    }

    /** Create an RC child, then reuse upstream group headers/options and request setters. */
    private function queueRequest(string $setter, $url, array $arguments, $query = '')
    {
        $this->assertOpen();
        $curl = new Curl($this->baseUrl, $this->options);
        try {
            $this->addCurl($curl);
            $this->setUrl($url, $query);
            $curl->$setter($url, ...$arguments);
            return $curl;
        } catch (\Throwable $error) {
            unset($this->state->queued[$curl->id], $this->state->instanceOptions[$curl->id]);
            $this->closeRequest($curl);
            throw $error;
        }
    }

    /** Complete one upstream request, keeping its native handle alive until callbacks finish. */
    private function completeRequest(\CurlHandle $handle, int $result): void
    {
        $curl = $this->activeCurls[$handle];
        $multiHandle = $this->multiCurl;
        $this->dispatching = $curl;
        $keep = false;
        $failure = null;
        try {
            $curl->curlErrorCode = $result;
            $curl->exec($handle);
            if ($this->state->running) {
                if ($curl->attemptRetry() && $this->state->running) {
                    curl_multi_remove_handle($multiHandle, $handle);
                    if ($curl instanceof Curl) {
                        $curl->beginDownloadAttempt();
                    }
                    $code = curl_multi_add_handle($multiHandle, $handle);
                    if ($code !== CURLM_OK) {
                        throw new \RuntimeException('cURL multi retry failed: ' . curl_multi_strerror($code));
                    }
                    $curl->call($curl->beforeSendCallback);
                    $keep = $this->state->running;
                } elseif ($this->state->running) {
                    $curl->execDone();
                }
            }
        } catch (\Throwable $error) {
            $failure = $error;
        } finally {
            $this->dispatching = null;
            if (!$keep) {
                if (isset($this->activeCurls[$handle])) {
                    unset($this->activeCurls[$handle]);
                    curl_multi_remove_handle($multiHandle, $handle);
                }
                unset($this->state->instanceOptions[$curl->id]);
                try {
                    $this->closeRequest($curl);
                } catch (\Throwable $error) {
                    $failure ??= $error;
                }
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    /** Close both RC clients and explicitly supplied native upstream clients, including download streams. */
    private function closeRequest(\Curl\Curl $curl): void
    {
        $handle = $curl->fileHandle;
        try {
            $curl->close();
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            $curl->fileHandle = $curl->downloadCompleteCallback = null;
            $curl->beforeSendCallback = $curl->afterSendCallback = $curl->successCallback = null;
            $curl->errorCallback = $curl->completeCallback = $curl->retryDecider = null;
        }
    }

    /** Reject reuse after close instead of passing null handles into the extension. */
    private function assertOpen(): void
    {
        if (!$this->multiCurl instanceof \CurlMultiHandle) {
            throw new \LogicException('The cURL multi client is closed; create another instance.');
        }
    }
}
