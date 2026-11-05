<?php

declare(strict_types=1);

namespace RC\Helper\View;

require_once __DIR__ . '/bootstrap.php';

/** Preserve PHP archive URLs while native Smarty handles ordinary local paths. */
class SmartyEngine extends \Smarty\Smarty
{
    /** Explicit legacy includes receive the same immutable-file adapter as the RC view handler. */
    public function __construct()
    {
        if (!function_exists('smarty_ucfirst_ascii')) { require_once dirname(__DIR__, 2) . '/ThirdParty/smarty/src/functions.php'; }
        parent::__construct();
        $this->registerResource('file', new SmartyFile());
    }

    /** Preserve a literal final backslash before Smarty trims directory separators. */
    private static function nativeDirectory($directory)
    {
        return PHP_OS_FAMILY !== 'Windows' && is_string($directory) && str_ends_with($directory, '\\')
            ? $directory . '/.' : $directory;
    }

    /** Native setTemplateDir()/setConfigDir() both delegate here; named directory indexes remain unchanged. */
    public function addTemplateDir($template_dir, $key = null, $isConfig = false)
    {
        $directory = is_array($template_dir) ? array_map(self::nativeDirectory(...), $template_dir) : self::nativeDirectory($template_dir);
        return parent::addTemplateDir($directory, $key, $isConfig);
    }

    /** Compile in the requested native directory, not a trimmed sibling. */
    public function setCompileDir($compile_dir)
    {
        return parent::setCompileDir(self::nativeDirectory($compile_dir));
    }

    /** Keep the native output-cache directory distinct from a similarly named sibling. */
    public function setCacheDir($cache_dir)
    {
        return parent::setCacheDir(self::nativeDirectory($cache_dir));
    }

    /** Keep PHAR URLs and native POSIX backslash names intact; ordinary paths use Smarty unchanged. */
    public function _realpath($path, $realpath = null)
    {
        if (str_starts_with($path, 'phar://')) {
            return PHP_OS_FAMILY === 'Windows' ? str_replace('\\', '/', $path) : $path;
        }
        if (PHP_OS_FAMILY !== 'Windows' && str_contains($path, '\\') && !str_contains($path, '://')) {
            $resolved = \RC\Cli\Filesystem::resolvePath($path);
            if ($resolved !== '/' && str_ends_with($path, '/')) { $resolved .= '/'; }
            if ($realpath !== false) { return $resolved; }
            $directory = getcwd();
            if ($resolved === $directory) { return '.'; }
            return str_starts_with($resolved, $directory . '/') ? '.' . substr($resolved, strlen($directory)) : $resolved;
        }
        return parent::_realpath($path, $realpath);
    }
}
