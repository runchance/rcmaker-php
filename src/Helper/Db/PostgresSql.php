<?php

declare(strict_types=1);

namespace RC\Helper\Db;

use PDO;
use RuntimeException;

/** Preserve PostgreSQL CR-ended comment semantics across PDO's LF-only placeholder scanner. */
final class PostgresSql
{
    /** Use the actual execution PDO; ordinary SQL performs no parsing, mode query or persistent caching. */
    public static function forPdo(string $sql, PDO $pdo): string
    {
        if (!str_contains($sql, "\r") || !str_contains($sql, '--')) {
            return $sql;
        }
        $standard = self::rewrite($sql, false);
        if (!str_contains($sql, '\\')) {
            return $standard;
        }
        $escaped = self::rewrite($sql, true);
        if ($standard === $escaped) {
            return $standard;
        }

        [$singleBackslash] = SqlParameters::escapeMode($pdo, 'pgsql');
        return $singleBackslash ? $escaped : $standard;
    }

    /** Add LF after a bare CR terminating a real line comment; never rewrite quoted content or parameters. */
    private static function rewrite(string $sql, bool $singleBackslash): string
    {
        $tokens = SqlParameters::opaqueTokens('pgsql', $singleBackslash, false);
        foreach ($tokens as &$token) {
            if (str_starts_with($token, '--')) {
                $token .= '(?:\r\n?|\n|$)';
            }
        }
        unset($token);
        $result = preg_replace_callback('~' . implode('|', $tokens) . '~s', static function (array $match): string {
            $token = $match[0];
            return str_starts_with($token, '--') && str_ends_with($token, "\r") ? $token . "\n" : $token;
        }, $sql);
        if ($result === null) {
            throw new RuntimeException('Cannot normalize PostgreSQL comments: ' . preg_last_error_msg());
        }
        return $result;
    }
}
