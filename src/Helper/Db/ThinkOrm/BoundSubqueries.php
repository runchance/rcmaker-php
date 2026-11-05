<?php

declare(strict_types=1);

namespace RC\Helper\Db\ThinkOrm;

use Closure;
use InvalidArgumentException;
use RC\Helper\Db\SqlParameters;
use think\db\BaseQuery;

/** Query-owned SQL fragments keep child values bound until the actual read/write PDO has been selected. */
final class BoundSubqueries
{
    public const BINDING_KEY = "\0rcmaker.bound_subqueries";

    private array $fragments = [];

    /** Attach an opaque fragment to this query only; cloned builders do not mutate an earlier query's fragments. */
    public static function attach(BaseQuery $query, string $sql, array $bind): string
    {
        $bindings = $query->getBind(false);
        $existing = $bindings[self::BINDING_KEY] ?? null;
        if ($existing !== null && !$existing instanceof self) {
            throw new InvalidArgumentException('Invalid internal ThinkORM subquery bindings.');
        }
        $bag = $existing === null ? new self() : clone $existing;
        do { $marker = '/*__rc_subquery_' . bin2hex(random_bytes(16)) . '__*/'; }
        while (str_contains($sql, $marker) || isset($bag->fragments[$marker]));
        $bag->fragments[$marker] = [$sql, $bind];
        $query->bind([self::BINDING_KEY => $bag]);
        return $marker;
    }

    /** No mode lookup is needed when every owned SQL fragment is free of backslashes. Values are never SQL text. */
    public static function needsEscapeMode(string $sql, array $bind): bool
    {
        if (str_contains($sql, '\\')) { return true; }
        $bag = $bind[self::BINDING_KEY] ?? null;
        if ($bag instanceof self) {
            foreach ($bag->fragments as [$childSql, $childBind]) {
                if (self::needsEscapeMode($childSql, $childBind)) { return true; }
            }
        }
        return false;
    }

    /** Fingerprint compiled count fragments without interpolating values or retaining random transport markers. */
    public static function signature(string $sql, array $bind): ?array
    {
        $remaining = CacheValueGuard::NODE_BUDGET;
        return self::cacheSignature($sql, $bind, $remaining, 0);
    }

    /** Inspect nested fragments with one budget, rather than restarting a limit for every child. */
    private static function cacheSignature(string $sql, array $bind, int &$remaining, int $depth): ?array
    {
        if ($depth > CacheValueGuard::MAX_DEPTH || $remaining-- <= 0) { return null; }
        $bag = $bind[self::BINDING_KEY] ?? null;
        unset($bind[self::BINDING_KEY]);
        if (!CacheValueGuard::accept($bind, $remaining, $depth)) { return null; }
        $fragments = $markers = [];
        if ($bag instanceof self) {
            foreach ($bag->fragments as $marker => [$childSql, $childBind]) {
                $markers[$marker] = '/*__rc_cache_fragment_' . count($fragments) . '__*/';
                $signature = self::cacheSignature($childSql, $childBind, $remaining, $depth + 1);
                if ($signature === null) { return null; }
                $fragments[] = $signature;
            }
        }
        return [strtr($sql, $markers), $bind, $fragments];
    }

    /** Expand diagnostic text with the original ORM renderer, never executing it or querying session metadata. */
    public static function diagnostic(string $sql, array $bind, Closure $render): string
    {
        $bag = $bind[self::BINDING_KEY] ?? null;
        unset($bind[self::BINDING_KEY]);
        if ($bag instanceof self) {
            foreach ($bag->fragments as $marker => [$childSql, $childBind]) {
                $sql = str_replace($marker, self::diagnostic($childSql, $childBind, $render), $sql);
            }
        }
        return $render($sql, $bind);
    }

    /** Retain PDO types and per-child parameter scope; only executable tokens are renamed, never interpolated. */
    public static function expand(string $sql, array $bind, string $dialect, ?bool $singleBackslash = null, ?bool $doubleBackslash = null): array
    {
        $output = $bind;
        unset($output[self::BINDING_KEY]);
        foreach (array_keys($output) as $key) {
            if (is_int($key)) { unset($output[$key]); }
        }
        $prefix = '__rc_bind_' . bin2hex(random_bytes(16)) . '_';
        $sequence = 0;
        $tokens = SqlParameters::opaqueTokens($dialect, $singleBackslash, $doubleBackslash);
        $tokens[] = ':{2,}';
        $tokens[] = '\\?\\?';
        $tokens[] = '(?<!:):[A-Za-z0-9_]+';
        $tokens[] = '\\?';
        $pattern = '~' . implode('|', $tokens) . '~s';
        return [self::compileScope($sql, $bind, false, $pattern, $prefix, $output, $sequence), $output];
    }

    /** Recurse through methods rather than a self-capturing closure, so completed queries need no cyclic GC. */
    private static function compileScope(string $text, array $bindings, bool $rename, string $pattern, string $prefix,
        array &$output, int &$sequence): string
    {
        $bag = $bindings[self::BINDING_KEY] ?? null;
        unset($bindings[self::BINDING_KEY]);
        $named = $positional = [];
        foreach ($bindings as $name => $value) {
            if (is_int($name)) { $positional[$name] = $value; }
            else { $named[$name] = $value; }
        }
        $position = 0;
        $used = [];
        $rewritten = preg_replace_callback($pattern, static function (array $match) use ($bag, $rename, $named, $positional,
            &$position, &$used, &$output, &$sequence, $prefix, $pattern): string {
            $token = $match[0];
            if ($bag instanceof self && isset($bag->fragments[$token])) {
                [$childSql, $childBind] = $bag->fragments[$token];
                return self::compileScope($childSql, $childBind, true, $pattern, $prefix, $output, $sequence);
            }
            if ($token === '?') {
                $key = $position++;
                if (!array_key_exists($key, $positional)) {
                    throw new InvalidArgumentException('Missing positional ThinkORM subquery binding.');
                }
                $value = $positional[$key];
            } elseif (preg_match('/\A:([A-Za-z0-9_]+)\z/D', $token, $parameter)) {
                if (!$rename) { return $token; }
                $key = $parameter[1];
                if (!array_key_exists($key, $named)) { return $token; }
                $value = $named[$key];
            } else { return $token; }
            if (is_array($value) && (!array_key_exists(0, $value) || !isset($value[1]) || !is_int($value[1]))) {
                throw new InvalidArgumentException('ThinkORM subquery binding tuples require a value and PDO type.');
            }
            $used[$key] = true;
            do { $name = $prefix . ++$sequence; } while (array_key_exists($name, $output));
            $output[$name] = $value;
            return ':' . $name;
        }, $text);
        if ($rewritten === null) {
            throw new \RuntimeException('Cannot tokenize ThinkORM subquery bindings: ' . preg_last_error_msg());
        }
        if ($rename && count($used) !== count($bindings)) {
            throw new InvalidArgumentException('ThinkORM subquery has unused bound parameters.');
        }
        if (!$rename && $position !== count($positional)) {
            throw new InvalidArgumentException('ThinkORM query has unused positional parameters.');
        }
        return $rewritten;
    }
}
