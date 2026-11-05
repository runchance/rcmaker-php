<?php

declare(strict_types=1);

namespace RC\Helper\Db;

/** Shared RC parameter transport lexer, extracted from Mix bindings; SQL execution/grammar stay in upstream engines. */
final class SqlParameters
{
    /** Read live escape flags from the execution PDO; callers decide whether their SQL needs this metadata. */
    public static function escapeMode(\PDO $pdo, string $dialect): array
    {
        $sql = match ($dialect) {
            'pgsql' => 'SHOW standard_conforming_strings',
            'mysql', 'mariadb' => 'SELECT @@SESSION.sql_mode',
            default => throw new \InvalidArgumentException('No session escape mode for SQL driver: ' . $dialect),
        };
        $statement = $pdo->query($sql);
        if ($statement === false) { throw PdoFailure::fromPdo($pdo, 'Cannot read the database string-literal mode.'); }
        $failure = null;
        try {
            $mode = $statement->fetchColumn();
            if ($mode === false && !in_array($statement->errorCode(), [null, '00000', '02000'], true)) {
                throw PdoFailure::fromPdo($statement, 'Cannot fetch the database string-literal mode.');
            }
        }
        catch (\Throwable $error) { $failure = $error; }
        finally {
            try {
                if (!$statement->closeCursor()) { throw PdoFailure::fromPdo($statement, 'Cannot close the database mode cursor.'); }
            }
            catch (\Throwable $error) { $failure ??= $error; }
        }
        if ($failure !== null) { throw $failure; }
        if (!is_string($mode)) { throw new \RuntimeException('Invalid database string-literal mode.'); }
        if ($dialect === 'pgsql') {
            if ($mode !== 'on' && $mode !== 'off') { throw new \RuntimeException('Invalid PostgreSQL string-literal mode.'); }
            return [$mode === 'off', false];
        }
        $modes = array_map('trim', explode(',', strtoupper($mode)));
        $single = !in_array('NO_BACKSLASH_ESCAPES', $modes, true);
        return [$single, $single && !in_array('ANSI_QUOTES', $modes, true)];
    }

    /** Quoted regions/comments must remain opaque to placeholder rewriting. Flags reflect the actual session. */
    public static function opaqueTokens(string $dialect, ?bool $singleBackslash = null, ?bool $doubleBackslash = null): array
    {
        $mysql = in_array($dialect, ['mysql', 'mariadb'], true);
        $singleBackslash ??= $mysql;
        $doubleBackslash ??= $mysql;
        $single = $singleBackslash ? "'(?:''|\\\\.|[^'\\\\])*'" : "'(?:''|[^'])*'";
        $double = $doubleBackslash ? '"(?:""|\\\\.|[^"\\\\])*"' : '"(?:""|[^"])*"';
        $comment = in_array($dialect, ['pgsql', 'sqlsrv', 'dblib'], true)
            ? '(?<block>/\\*(?:[^*/]|/(?!\\*)|\\*(?!/)|(?&block))*\\*/)'
            : '/\\*.*?\\*/';
        $lineBody = in_array($dialect, ['mysql', 'mariadb', 'sqlite'], true) ? '[^\\n]*' : '[^\\r\\n]*';
        $tokens = [$single, $double, '`(?:``|[^`])*`', $comment,
            $mysql ? '--(?=\\s|$)' . $lineBody : '--' . $lineBody];
        if (in_array($dialect, ['sqlite', 'sqlsrv', 'dblib'], true)) { $tokens[] = '\\[(?:\\]\\]|[^\\]])*\\]'; }
        if ($mysql) { $tokens[] = '\\#' . $lineBody; }
        if ($dialect === 'pgsql') {
            array_unshift($tokens, "[Ee]'(?:''|\\\\.|[^'\\\\])*'");
            $tokens[] = '(?<![A-Za-z0-9_\\x80-\\xff$])\\$(?<tag>[A-Za-z_\\x80-\\xff][A-Za-z0-9_\\x80-\\xff]*|)\\$.*?\\$\\k<tag>\\$';
        }
        return $tokens;
    }

    /** Expand names in occurrence order, including repeated names, without interpolating any bound value. */
    public static function toPositional(string $sql, array $values, string $dialect, ?bool $singleBackslash = null, ?bool $doubleBackslash = null): array
    {
        $named = $positional = [];
        foreach ($values as $key => $value) {
            if (is_int($key)) { $positional[] = $value; continue; }
            $name = ltrim($key, ':');
            if (!preg_match('/\A[A-Za-z0-9_]+\z/D', $name) || array_key_exists($name, $named)) {
                throw new \InvalidArgumentException('Invalid or duplicate named SQL parameter.');
            }
            $named[$name] = $value;
        }
        $tokens = self::opaqueTokens($dialect, $singleBackslash, $doubleBackslash);
        $tokens[] = ':{2,}';
        $tokens[] = '\\?\\?';
        $tokens[] = '(?<!:):[A-Za-z0-9_]+';
        $tokens[] = '\\?';
        $bindings = $used = []; $position = 0;
        $rewritten = preg_replace_callback('~' . implode('|', $tokens) . '~s', static function (array $match) use ($named, $positional, &$bindings, &$used, &$position): string {
            $token = $match[0];
            if ($token === '?') {
                if (!array_key_exists($position, $positional)) { throw new \InvalidArgumentException('Missing positional SQL parameter.'); }
                $value = $positional[$position++];
            } elseif (preg_match('/\A:([A-Za-z0-9_]+)\z/D', $token, $parameter)) {
                $name = $parameter[1];
                if (!array_key_exists($name, $named)) { throw new \InvalidArgumentException('Missing named SQL parameter: ' . $name); }
                $used[$name] = true; $value = $named[$name];
            } else { return $token; }
            if (!is_array($value)) { $bindings[] = $value; return '?'; }
            if ($value === []) { return 'NULL'; }
            foreach ($value as $item) {
                if (is_array($item)) { throw new \InvalidArgumentException('Nested SQL parameter arrays are not supported.'); }
                $bindings[] = $item;
            }
            return implode(', ', array_fill(0, count($value), '?'));
        }, $sql);
        if ($rewritten === null) { throw new \RuntimeException('Cannot tokenize SQL parameters: ' . preg_last_error_msg()); }
        if ($position !== count($positional) || count($used) !== count($named)) {
            throw new \InvalidArgumentException('SQL has unused bound parameters.');
        }
        return [$rewritten, $bindings];
    }
}
