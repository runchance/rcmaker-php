<?php

declare(strict_types=1);

namespace RC\Helper\View;

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/Template/think/template/exception/TemplateNotFoundException.php';

/** Fresh per-render native engine; compiled PHP remains cached outside the application archive. */
class ThinkEngine extends \think\Template
{
    /** Initialize the optional native PSR cache before isCache() can access it. */
    public function __construct(array $config = [])
    {
        $this->cache = null;
        if (empty($config['compile_type']) || strcasecmp((string) $config['compile_type'], 'file') === 0) {
            $config['compile_type'] = ThinkFile::class;
        }
        parent::__construct($config);
    }

    /** Keep native compilation; adapt only optional rendered-page caching to the bound PSR-16 store. */
    public function fetch(string $template, array $vars = []): void
    {
        $enabled = $this->config['display_cache'];
        if (!is_bool($enabled)) {
            throw new \InvalidArgumentException('ThinkTemplate display_cache must be a boolean.');
        }
        $id = $this->config['cache_id'];
        if (!$enabled || $this->cache === null || $id === '') {
            parent::fetch($template, $vars);
            return;
        }
        if (!is_string($id)) {
            throw new \InvalidArgumentException('ThinkTemplate cache_id must be a string.');
        }
        $ttl = $this->config['cache_time'];
        if (!is_int($ttl) || $ttl < 0 || $ttl > PHP_INT_MAX - time()) {
            throw new \InvalidArgumentException('ThinkTemplate cache_time must be a nonnegative integer lifetime.');
        }
        $this->assign($vars);
        $cached = $this->cache->get($id);
        if (is_string($cached)) {
            echo $cached;
            return;
        }

        // The parent still owns parsing, includes, layouts and compiled-cache validation.
        $this->config['display_cache'] = false;
        try {
            $html = Runtime::capture(fn() => parent::fetch($template));
        } finally {
            $this->config['display_cache'] = $enabled;
        }
        $this->cache->set($id, $html, $ttl === 0 ? null : $ttl);
        echo $html;
    }

    /** Public cache probes recognize empty/zero-string HTML and never convert cached objects. */
    public function isCache(string $cacheId): bool
    {
        return $cacheId !== '' && $this->cache !== null && $this->config['display_cache'] === true
            && is_string($this->cache->get($cacheId));
    }
}
