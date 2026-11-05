<?php

namespace RC\Helper\Jwt;

require_once __DIR__ . '/bootstrap.php';

/** Native JWKS behavior with collision-resistant cache identities for each configured source. */
class CachedKeySet extends \Firebase\JWT\CachedKeySet
{
    /** Keep the native API; scope keys by full URI/default algorithm, and rate budgets by full URI. */
    public function __construct(
        string $jwksUri,
        \Psr\Http\Client\ClientInterface $httpClient,
        \Psr\Http\Message\RequestFactoryInterface $httpFactory,
        \Psr\Cache\CacheItemPoolInterface $cache,
        ?int $expiresAfter = null,
        bool $rateLimit = false,
        ?string $defaultAlg = null
    ) {
        parent::__construct($jwksUri, $httpClient, $httpFactory, $cache, $expiresAfter, $rateLimit, $defaultAlg);
        // The native constructor creates metadata only. Bind its two private cache identities before any I/O.
        static $properties;
        $properties ??= [
            new \ReflectionProperty(\Firebase\JWT\CachedKeySet::class, 'cacheKey'),
            new \ReflectionProperty(\Firebase\JWT\CachedKeySet::class, 'rateLimitCacheKey'),
        ];
        $properties[0]->setValue($this, hash('sha256', serialize(['rcmaker-jwks-v1', 'keys', $jwksUri, $defaultAlg])));
        if ($rateLimit) {
            $properties[1]->setValue($this, hash('sha256', serialize(['rcmaker-jwks-v1', 'rate', $jwksUri])));
        }
    }
}
