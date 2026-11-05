<?php

declare(strict_types=1);

namespace RC\Helper\Curl;

/**
 * Internal bridge to PHP-Curl-Class 13's private scheduler hooks, not a second configuration store.
 * References are captured once; no owner-bound closure or hot-loop reflection is retained.
 */
final class MultiState
{
    public array $queued = [];
    public array $instanceOptions = [];
    public bool $running = false;
    public int $concurrency = 25;
    public bool $rateLimited = false;
    public ?float $windowStart = null;
    public int $requestCount = 0;

    /** Bind the known upstream 13.x fields without modifying its source or copying its options. */
    public function __construct(\Curl\MultiCurl $owner)
    {
        static $capture;
        $capture ??= \Closure::bind(static function (\Curl\MultiCurl $owner): array {
            return ['queued' => &$owner->queuedCurls, 'instanceOptions' => &$owner->instanceSpecificOptions,
                'running' => &$owner->isStarted, 'concurrency' => &$owner->concurrency,
                'rateLimited' => &$owner->rateLimitEnabled, 'windowStart' => &$owner->currentStartTime,
                'requestCount' => &$owner->currentRequestCount];
        }, null, \Curl\MultiCurl::class);
        $fields = $capture($owner);
        foreach ($fields as $name => &$value) {
            $this->$name =& $value;
        }
        unset($value);
    }

    /** Initialize one request through upstream callbacks, decoders, cookies, proxies and retries. */
    public function initialize(\Curl\MultiCurl $owner): void
    {
        self::invoke($owner, 'initHandle');
    }

    /** Reuse upstream request-quota accounting. */
    public function hasQuota(\Curl\MultiCurl $owner): bool
    {
        return self::invoke($owner, 'hasRequestQuota');
    }

    /** Reuse upstream rate-limit waiting instead of creating a second timing policy. */
    public function waitForQuota(\Curl\MultiCurl $owner): void
    {
        self::invoke($owner, 'waitUntilRequestQuotaAvailable');
    }

    /** Release private configuration callbacks when the owner is explicitly closed. */
    public function releaseConfiguration(\Curl\MultiCurl $owner): void
    {
        static $release;
        $release ??= \Closure::bind(static function (\Curl\MultiCurl $owner): void {
            $owner->retry = $owner->jsonDecoder = $owner->xmlDecoder = $owner->proxies = null;
            $owner->cookies = [];
            $owner->headers = new \Curl\CaseInsensitiveArray();
        }, null, \Curl\MultiCurl::class);
        $release($owner);
    }

    /** Invoke only the fixed scheduler hooks used above; the static closure holds no owner. */
    private static function invoke(\Curl\MultiCurl $owner, string $method): mixed
    {
        static $invoke;
        $invoke ??= \Closure::bind(static function (\Curl\MultiCurl $owner, string $method): mixed {
            return $owner->$method();
        }, null, \Curl\MultiCurl::class);
        return $invoke($owner, $method);
    }
}
