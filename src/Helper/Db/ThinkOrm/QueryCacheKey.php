<?php

declare(strict_types=1);

namespace RC\Helper\Db\ThinkOrm;

use RC\Helper\Db\SqlParameters;
use think\db\Connection;

/** Automatic query-cache identities are compiled data, never interpolated SQL or closure object identities. */
final class QueryCacheKey
{
    /** Include result shape, route, connection configuration, SQL and typed values without opening a PDO. */
    public static function make(Connection $connection, string $dialect, string $sql, array $bindings, array $shape): ?string
    {
        $tree = BoundSubqueries::signature($sql, $bindings);
        $remaining = CacheValueGuard::NODE_BUDGET;
        if ($tree === null || !CacheValueGuard::accept($shape, $remaining)) { return null; }
        $modes = match ($dialect) {
            'mysql' => [[true,true],[true,false],[false,false]],
            'pgsql' => [[false,false],[true,false]],
            default => [[false,false]],
        };
        $signatures = [];
        foreach ($modes as [$single,$double]) { $signatures[] = self::scope($tree, $dialect, $single, $double); }
        $identity = [];
        foreach (['type','hostname','hostport','database','dsn','charset','prefix','username','params','rcmaker_connection_id'] as $key) {
            $identity[$key] = $connection->getConfig($key);
        }
        if (!CacheValueGuard::accept($identity, $remaining)) { return null; }
        return 'rc_query_v1_' . hash('sha256', serialize([$identity,$shape,$signatures]));
    }

    /** Fingerprint every supported quote mode, so a cache hit needs no session query and cannot depend on guessed modes. */
    private static function scope(array $tree, string $dialect, bool $single, bool $double): array
    {
        [$sql,$bindings,$children] = $tree;
        $tokens = SqlParameters::opaqueTokens($dialect, $single, $double);
        $tokens[] = ':{2,}';
        $tokens[] = '\\?\\?';
        $tokens[] = '(?<!:):[A-Za-z0-9_]+';
        $tokens[] = $dialect === 'sqlite' ? '\\?(?:[1-9][0-9]*)?' : '\\?';
        $result = preg_match_all('~' . implode('|', $tokens) . '~s', $sql, $matches, PREG_OFFSET_CAPTURE);
        if ($result === false) { throw new \RuntimeException('Cannot tokenize query cache identity: ' . preg_last_error_msg()); }
        $parts = $used = $namedSlots = [];
        $offset = $slot = 0;
        foreach ($matches[0] as [$token,$position]) {
            if ($position > $offset) { $parts[] = ['sql',substr($sql,$offset,$position-$offset)]; }
            $key = null;
            if (preg_match('/\A:([A-Za-z0-9_]+)\z/D', $token, $named)) {
                $key = $named[1];
                if ($dialect === 'sqlite') { $namedSlots[$key] ??= ++$slot; }
            } elseif (preg_match('/\A\?([1-9][0-9]*)?\z/D', $token, $numbered)) {
                $index = isset($numbered[1]) ? (int)$numbered[1] : $slot + 1;
                $slot = max($slot,$index);
                $key = $index - 1;
            }
            if ($key !== null && array_key_exists($key,$bindings)) {
                $parts[] = ['parameter',$bindings[$key]];
                $used[$key] = true;
            } else { $parts[] = ['sql',$token]; }
            $offset = $position + strlen($token);
        }
        if ($offset < strlen($sql)) { $parts[] = ['sql',substr($sql,$offset)]; }
        $unused = array_diff_key($bindings,$used);
        $childSignatures = [];
        foreach ($children as $child) { $childSignatures[] = self::scope($child,$dialect,$single,$double); }
        return [$parts,$unused,$childSignatures];
    }

}
