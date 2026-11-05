<?php

declare(strict_types=1);

namespace RC\Helper\Cache\think\cache\driver;

/** Legacy configuration guard; no verified stable WinCache runtime is supported on PHP 8.5. */
final class Wincache
{
    /** Explain migration instead of silently selecting a different storage backend. */
    public function __construct(array $options = [])
    {
        throw new \BadFunctionCallException(
            'WinCache is not supported by RCMAKER on PHP 8.5 without a verified stable extension. '
            . 'Change config/cache.php to File or Redis.'
        );
    }
}
