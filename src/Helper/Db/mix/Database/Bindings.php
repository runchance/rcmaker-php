<?php

declare(strict_types=1);

namespace RC\Helper\Db\mix\Database;

/** Expand the legacy array-parameter API into PDO placeholders, never SQL literals. */
final class Bindings
{
    /** Scalar-only SQL stays on the short path; arrays require tokenizing quoted SQL/comment regions. */
    public static function expand(string $sql, array $values, string $dialect, ?\PDO $pdo = null): array
    {
        $hasArrays = false;
        foreach ($values as $value) { if (is_array($value)) { $hasArrays = true; break; } }
        if (!$hasArrays) { return [$sql, $values]; }

        $single = $double = null;
        if ($pdo !== null && str_contains($sql, '\\') && in_array($dialect, ['mysql', 'mariadb', 'pgsql'], true)
            && (str_contains($sql, "\\'") || ($dialect !== 'pgsql' && str_contains($sql, '\\"')))) {
            $candidates = $dialect === 'pgsql' ? [[false, false], [true, false]] : [[true, true], [true, false], [false, false]];
            $positions = null;
            foreach ($candidates as [$candidateSingle, $candidateDouble]) {
                $current = self::positions($sql, $dialect, $candidateSingle, $candidateDouble);
                if ($positions !== null && $positions !== $current) {
                    [$single, $double] = \RC\Helper\Db\SqlParameters::escapeMode($pdo, $dialect);
                    break;
                }
                $positions = $current;
            }
        }
        $tokens = \RC\Helper\Db\SqlParameters::opaqueTokens($dialect, $single, $double);
        $tokens[] = '\\?\\?';
        $tokens[] = '\\?';
        $index = 0;
        $bindings = [];
        $expanded = preg_replace_callback('~' . implode('|', $tokens) . '~s', static function (array $match) use ($values, &$index, &$bindings): string {
            if ($match[0] !== '?') { return $match[0]; }
            if (!array_key_exists($index, $values)) { throw new \InvalidArgumentException('Not enough Mix SQL parameters.'); }
            $value = $values[$index++];
            if (!is_array($value)) { $bindings[] = $value; return '?'; }
            if ($value === []) { return 'NULL'; }
            foreach ($value as $item) {
                if (is_array($item) || is_object($item)) { throw new \InvalidArgumentException('Mix array parameters must contain scalar values.'); }
                $bindings[] = $item;
            }
            return implode(', ', array_fill(0, count($value), '?'));
        }, $sql);
        if ($expanded === null) { throw new \RuntimeException('Cannot tokenize Mix SQL parameters: ' . preg_last_error_msg()); }
        if ($index !== count($values)) { throw new \InvalidArgumentException('Mix SQL has unused positional parameters.'); }
        return [$expanded, $bindings];
    }

    /** Compare actual placeholder offsets without reading values or failing under a nonselected escape mode. */
    private static function positions(string $sql, string $dialect, bool $single, bool $double): array
    {
        $tokens = \RC\Helper\Db\SqlParameters::opaqueTokens($dialect, $single, $double);
        $tokens[] = '\\?\\?'; $tokens[] = '\\?';
        if (preg_match_all('~' . implode('|', $tokens) . '~s', $sql, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            throw new \RuntimeException('Cannot locate Mix SQL parameters: ' . preg_last_error_msg());
        }
        $positions = [];
        foreach ($matches as $match) { if ($match[0][0] === '?') { $positions[] = $match[0][1]; } }
        return $positions;
    }
}
