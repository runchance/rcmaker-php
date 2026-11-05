<?php

namespace RC;

use RC\Configuration\Environment;
use RC\Configuration\Files;

defined('ENV_PATH') || define('ENV_PATH', phar_path() . '/.env');
defined('CONFIG_PATH') || define('CONFIG_PATH', BASE_PATH . '/config');

/** Process-local configuration. Use get('app', 'debug') and rcEnv('app.debug', false). */
final class Config
{
    use FileOperator;

    private static $_c = [];
    private static $_envData = [];
    private static array $missing = [];
    private static array $loading = [];
    private static array $envFile = [];
    private static array $envOverrides = [];
    private static array $systemEnv = [];
    private static array $envNames = [];
    private static bool $envLoaded = false;
    private static bool $refreshing = false;

    /** Read one normalized env name, or the file/runtime map when name is null. */
    public static function getEnv($name = null, $default = null)
    {
        if (!self::$envLoaded) { self::reloadEnv(); }
        if ($name === null) { return self::$_envData; }
        if (is_string($name) && isset(self::$envNames[$name])) { $name = self::$envNames[$name]; }
        else {
            $normalized = self::envName($name);
            if (count(self::$envNames) >= 256) { unset(self::$envNames[array_key_first(self::$envNames)]); }
            self::$envNames[$name] = $normalized; $name = $normalized;
        }
        if (isset(self::$_envData[$name])) { return self::envValue(self::$_envData[$name]); }
        $split = strrpos($name, '_');
        if ($split !== false) {
            $parent = self::$_envData[substr($name, 0, $split)] ?? null;
            $key = substr($name, $split + 1);
            if (is_array($parent) && isset($parent[$key])) { return self::envValue($parent[$key]); }
        }
        $value = self::getSystemEnv($name);
        return $value === null ? $default : self::envValue($value);
    }

    /** Cache positive PHP_* lookups until the next explicit environment reload. */
    protected static function getSystemEnv(string $name, $default = null)
    {
        if (array_key_exists($name, self::$systemEnv)) { return self::$systemEnv[$name]; }
        $value = getenv('PHP_' . $name);
        if ($value === false) { return $default; }
        return self::$systemEnv[$name] = match ($value) { 'false' => false, 'true' => true, default => $value };
    }

    /** Override process-local env values; setEnv(['app' => ['debug' => true]]) is also supported. */
    public static function setEnv($env, $value = null)
    {
        $values = is_array($env) ? self::flattenEnv($env) : [self::envName($env) => $value];
        self::$envOverrides = array_replace(self::$envOverrides, $values);
        self::$_envData = array_replace(self::$envFile, self::$envOverrides);
    }

    /** Atomically reload .env; removed keys disappear, explicit overrides survive unless requested otherwise. */
    public static function reloadEnv(bool $clearOverrides = false): void
    {
        $values = self::flattenEnv(Environment::read(ENV_PATH));
        if ($clearOverrides) { self::$envOverrides = []; }
        self::$envFile = $values;
        self::$_envData = array_replace($values, self::$envOverrides);
        self::$systemEnv = [];
        self::$envLoaded = true;
    }

    /** Get cached PHP configuration. A truthy force reloads .env and the selected file. */
    public static function get($file = null, $key = null, $force = null, $default = null)
    {
        if (!$force && is_string($file) && isset(self::$_c[$file]) && (is_string($key) || is_int($key))) {
            if (is_array(self::$_c[$file])) { return self::$_c[$file][$key] ?? $default; }
            return self::select(self::$_c[$file], $key, $default);
        }
        if ($file === null) { return false; }
        if (!is_string($file) && !is_int($file)) { self::key($file); }
        if ($key !== null && !is_string($key) && !is_int($key)) { self::key($key); }
        if (!$force && array_key_exists($file, self::$_c)) {
            if ($key === null) { return self::$_c[$file]; }
            $values = self::$_c[$file];
            return is_array($values) || $values instanceof \ArrayAccess ? ($values[$key] ?? $default) : $default;
        }
        return self::load($file, $key, $force, $default);
    }

    /** Keep filesystem checks and transactional state out of the cached-read VM frame. */
    private static function load($file, $key, $force, $default)
    {
        $file = Files::name((string) $file);
        if (!$force && array_key_exists($file, self::$_c)) { return self::select(self::$_c[$file], $key, $default); }
        if (!$force && isset(self::$missing[$file])) { return $default; }
        if (isset(self::$loading[$file])) { throw new \LogicException('Circular configuration dependency: ' . $file); }
        $state = self::snapshot();
        try {
            if ($force) { self::reloadEnv(); }
            $path = CONFIG_PATH . '/' . $file . '.php';
            if (!is_file($path)) {
                if (file_exists($path) || is_link($path)) { throw new \RuntimeException('Configuration is not a regular file: ' . $file); }
                unset(self::$_c[$file]);
                if (count(self::$missing) >= 256) { unset(self::$missing[array_key_first(self::$missing)]); }
                self::$missing[$file] = true;
                return $default;
            }
            self::$loading[$file] = true;
            self::$_c[$file] = Files::load($path, (bool) $force || self::$refreshing);
            unset(self::$missing[$file]);
            return self::select(self::$_c[$file], $key, $default);
        } catch (\Throwable $error) {
            self::restore($state); throw $error;
        } finally { unset(self::$loading[$file]); }
    }

    /** Enumerate top-level PHP files, including in PHAR; excluded names never appear in the result. */
    public static function getAll($force = null, $exclude = [])
    {
        if (!is_array($exclude)) { throw new \InvalidArgumentException('Configuration exclusions must be an array.'); }
        $excluded = [];
        foreach ($exclude as $name) { self::key($name); $excluded[Files::name((string) $name)] = true; }
        $state = self::snapshot();
        try {
            $names = Files::listing(CONFIG_PATH);
            if ($force) {
                self::reloadEnv();
                self::$_c = array_intersect_key(self::$_c, $excluded);
                self::$missing = [];
                self::$refreshing = true;
            }
            $result = [];
            foreach ($names as $name) {
                if (!isset($excluded[$name])) { $result[$name] = self::get($name); }
            }
            return $result;
        } catch (\Throwable $error) { self::restore($state); throw $error; }
        finally { self::$refreshing = $state[7]; }
    }

    /** Persist literal array data: true on change, false if unchanged; failures throw without changing cache. */
    public static function set($file = null, $key = null, $value = null)
    {
        if ($file === null || $key === null) { return false; }
        self::key($file); self::key($key);
        if (self::$loading) { throw new \LogicException('Cannot persist configuration while loading configuration.'); }
        $file = Files::name((string) $file);
        [$changed, $values] = Files::update(CONFIG_PATH, $file, $key, $value);
        self::$_c[$file] = $values;
        unset(self::$missing[$file]);
        return $changed;
    }

    /** Whole files may return scalars; keyed reads never interpret a string as character offsets. */
    private static function select($values, $key, $default)
    {
        if ($key === null) { return $values; }
        return is_array($values) || $values instanceof \ArrayAccess ? ($values[$key] ?? $default) : $default;
    }

    /** Do not silently convert objects, arrays or booleans into filenames/config keys. */
    private static function key($key, bool $nullable = false): void
    {
        if (!is_string($key) && !is_int($key) && !($nullable && $key === null)) {
            throw new \InvalidArgumentException('Configuration names and keys must be strings or integers.');
        }
    }

    /** Normalize app.debug to APP_DEBUG without evaluating environment expressions. */
    private static function envName($name): string
    {
        self::key($name); $name = strtoupper(str_replace('.', '_', (string) $name));
        if ($name === '' || preg_match('/[\x00-\x20=\x7f]/', $name)) { throw new \InvalidArgumentException('Invalid environment variable name.'); }
        return $name;
    }

    /** Preserve the existing one-section INI naming convention; nested array values stay arrays. */
    private static function flattenEnv(array $env): array
    {
        $result = [];
        foreach ($env as $name => $value) {
            $name = self::envName($name);
            if (is_array($value)) {
                foreach ($value as $key => $item) { $result[$name . '_' . self::envName($key)] = $item; }
            } else { $result[$name] = $value; }
        }
        return $result;
    }

    /** Return a fresh JSON object each time, so mutations cannot pollute another request. */
    private static function envValue($value)
    {
        if (is_string($value) && str_starts_with(ltrim($value), '{')) {
            $object = json_decode($value);
            if (is_object($object)) { return $object; }
        }
        return $value;
    }

    /** Cold loads are transactional for this class's state, not arbitrary application side effects. */
    private static function snapshot(): array
    {
        return [self::$_c, self::$missing, self::$_envData, self::$envFile, self::$envOverrides, self::$systemEnv, self::$envLoaded, self::$refreshing];
    }

    /** Restore process-local values after an include, INI or filesystem failure. */
    private static function restore(array $state): void
    {
        [self::$_c, self::$missing, self::$_envData, self::$envFile, self::$envOverrides, self::$systemEnv, self::$envLoaded, self::$refreshing] = $state;
    }
}
