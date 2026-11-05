<?php

namespace RC\Helper\Excel;

/**
 * Compile saved Excel calendar/time masks without evaluating formulas or PHP code.
 * Internal use: compile($mask), then renderSerial($plan, $serial, $date1904) for value-dependent masks.
 */
final class DateFormatPlan
{
    /** Compile up to four quote-aware sections; ordinary masks keep a small, direct PHP-mask plan. */
    public static function compile(string $pattern): array
    {
        $sections = []; $tokens = []; $condition = null; $valid = true; $numeric = false;
        $length = strlen($pattern);
        for ($offset = 0; $offset < $length; ++$offset) {
            $character = $pattern[$offset];
            if ($character === ';') {
                if (count($sections) === 3) { $valid = false; break; }
                $sections[] = self::section($tokens, $condition, $numeric, $valid);
                $tokens = []; $condition = null; $numeric = false;
                continue;
            }
            if ($character === '"') {
                $literal = '';
                for (++$offset; $offset < $length && $pattern[$offset] !== '"'; ++$offset) { $literal .= $pattern[$offset]; }
                $valid = $valid && $offset < $length; self::appendLiteral($tokens, $literal); continue;
            }
            if ($character === '\\' || $character === '_' || $character === '*') {
                if (++$offset >= $length) { $valid = false; break; }
                $start = $offset;
                while ($offset + 1 < $length && (ord($pattern[$offset + 1]) & 0xc0) === 0x80) { ++$offset; }
                self::appendLiteral($tokens, $character === '\\' ? substr($pattern, $start, $offset - $start + 1) : ($character === '_' ? ' ' : ''));
                continue;
            }
            if ($character === '[') {
                $end = strpos($pattern, ']', $offset + 1);
                if ($end === false) { $valid = false; break; }
                $directive = substr($pattern, $offset + 1, $end - $offset - 1);
                if (preg_match('/\A(h+|m+|s+)\z/iD', $directive)) {
                    $tokens[] = ['elapsed-' . strtolower($directive[0]), strlen($directive)];
                } elseif (preg_match('/\A(<=|>=|<>|=|<|>)([+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?)\z/D', $directive, $match)) {
                    $valid = $valid && $condition === null && is_finite((float)$match[2]); $condition = [$match[1], (float)$match[2]];
                } elseif (preg_match('/\A[<>=]/D', $directive)) { $valid = false; }
                $offset = $end; continue;
            }
            $marker = substr($pattern, $offset, 5);
            if (strncasecmp(substr($pattern, $offset, 7), 'General', 7) === 0) {
                $numeric = true; self::appendLiteral($tokens, 'General'); $offset += 6; continue;
            }
            if (strcasecmp($marker, 'AM/PM') === 0 || strcasecmp(substr($marker, 0, 3), 'A/P') === 0) {
                $short = strcasecmp($marker, 'AM/PM') !== 0;
                $tokens[] = [$short ? 'short-meridiem' : 'meridiem', $character === 'A' ? 'A' : 'a'];
                $offset += $short ? 2 : 4; continue;
            }
            $field = strtolower($character);
            if (str_contains('ymdhs', $field)) {
                $run = 1;
                while ($offset + $run < $length && strtolower($pattern[$offset + $run]) === $field) { ++$run; }
                $tokens[] = [$field, $run]; $offset += $run - 1; continue;
            }
            if (($character === '.' || $character === ',') && $offset + 1 < $length && $pattern[$offset + 1] === '0'
                && $tokens !== [] && in_array($tokens[array_key_last($tokens)][0], ['s', 'elapsed-s'], true)) {
                $run = 1;
                while ($offset + 1 + $run < $length && $pattern[$offset + 1 + $run] === '0') { ++$run; }
                self::appendLiteral($tokens, $character); $tokens[] = ['fraction', $run]; $valid = $valid && $run <= 3;
                $offset += $run; continue;
            }
            if (str_contains('0#?@', $character)) { $numeric = true; }
            self::appendLiteral($tokens, $character);
        }
        $sections[] = self::section($tokens, $condition, $numeric, $valid);
        $date = $serial = false;
        foreach ($sections as $index => $section) {
            if ($index < 3) { $date = $date || $section['date']; }
            $serial = $serial || $section['raw'] || $section['condition'] !== null;
            $valid = $valid && ($index < 2 || $section['condition'] === null);
        }
        $serial = $serial || count($sections) > 1; $valid = $valid && count($sections) <= 4;
        $result = ['date' => $date, 'php' => $date && !$serial && $valid ? $sections[0]['php'] : null,
            'parts' => $date && !$serial && $valid ? $sections[0]['parts'] : null, 'serial' => $serial && $date];
        if ($result['serial']) { $result['sections'] = $sections; $result['valid'] = $valid; $result['nativeDate'] = $sections[0]['date']; }
        return $result;
    }

    /** Coalesce adjacent text before allocating per-field plans, including long escaped literal runs. */
    private static function appendLiteral(array &$tokens, string $literal): void
    {
        if ($literal === '') { return; }
        $last = array_key_last($tokens);
        if ($last !== null && $tokens[$last][0] === 'literal') { $tokens[$last][1] .= $literal; }
        else { $tokens[] = ['literal', $literal]; }
    }

    /** Preserve native calendar fields while extending the same tokens with elapsed units and fractional seconds. */
    private static function section(array $tokens, ?array $condition, bool $numeric, bool $valid): array
    {
        $php = ''; $parts = []; $date = $raw = false; $precision = 0; $elapsed = null;
        $twelveHour = false;
        foreach ($tokens as [$field]) { $twelveHour = $twelveHour || $field === 'meridiem' || $field === 'short-meridiem'; }
        foreach ($tokens as $index => [$field, $value]) {
            if ($field === 'literal') { $php .= self::literal($value); continue; }
            if ($field === 'fraction' || str_starts_with($field, 'elapsed-')) {
                if ($php !== '') { $parts[] = ['mask', $php]; $php = ''; }
                $parts[] = [$field, $value]; $raw = $date = true;
                if ($field === 'fraction') { $precision = max($precision, $value); }
                else { $valid = $valid && $elapsed === null; $elapsed = substr($field, -1); }
                continue;
            }
            $date = true;
            if ($field === 'meridiem') { $php .= $value; continue; }
            if ($field === 'short-meridiem') {
                if ($php !== '') { $parts[] = ['mask', $php]; $php = ''; }
                $parts[] = [$value === 'A' ? 'short-upper' : 'short-lower', null]; continue;
            }
            if ($field === 'm' && $value <= 2) {
                $previous = $next = null;
                for ($other = $index - 1; $other >= 0; --$other) { if ($tokens[$other][0] !== 'literal') { $previous = $tokens[$other][0]; break; } }
                for ($other = $index + 1; $other < count($tokens); ++$other) { if ($tokens[$other][0] !== 'literal') { $next = $tokens[$other][0]; break; } }
                if (in_array($previous, ['h', 'elapsed-h'], true) || in_array($next, ['s', 'elapsed-s'], true)) {
                    if ($value === 1) {
                        if ($php !== '') { $parts[] = ['mask', $php]; $php = ''; }
                        $parts[] = ['minute', null];
                    } else { $php .= 'i'; }
                } else { $php .= $value === 1 ? 'n' : 'm'; }
                continue;
            }
            if (($field === 'm' && $value === 5) || ($field === 's' && $value === 1)) {
                if ($php !== '') { $parts[] = ['mask', $php]; $php = ''; }
                $parts[] = [$field === 'm' ? 'month-initial' : 'second', null]; continue;
            }
            $php .= match ($field) {
                'y' => $value <= 2 ? 'y' : 'Y', 'm' => $value === 3 ? 'M' : 'F',
                'd' => match ($value) { 1 => 'j', 2 => 'd', 3 => 'D', default => 'l' },
                'h' => $value === 1 ? ($twelveHour ? 'g' : 'G') : ($twelveHour ? 'h' : 'H'), 's' => 's',
            };
            $valid = $valid && !(($field === 'm' && $value > 4) || ($field === 'd' && $value > 4)
                || ($field === 'h' && $value > 2) || ($field === 's' && $value !== 2) || ($field === 'y' && $value > 4));
        }
        $single = $parts === [] ? $php : null;
        $valid = $valid && !($date && $numeric);
        if ($elapsed !== null) {
            foreach ($tokens as [$field]) { $valid = $valid && $field !== $elapsed; }
        }
        if ($php !== '') { $parts[] = ['mask', $php]; }
        return ['date' => $date, 'php' => $date && $valid && !$raw ? $single : null, 'parts' => $valid ? $parts : null,
            'condition' => $condition, 'numeric' => $numeric, 'raw' => $raw, 'precision' => $precision, 'elapsed' => $elapsed];
    }

    /** Return a selected raw-value display, or null when the mask is unsupported rather than inventing a result. */
    public static function renderSerial(array $plan, float $serial, bool $date1904): ?array
    {
        if (!$plan['serial'] || !$plan['valid'] || !is_finite($serial)) { return null; }
        $sections = $plan['sections']; $count = min(count($sections), 3); $conditional = false;
        foreach (array_slice($sections, 0, 2) as $section) { $conditional = $conditional || $section['condition'] !== null; }
        $selected = null;
        if ($conditional) {
            for ($index = 0; $index < $count; ++$index) {
                $condition = $sections[$index]['condition'];
                if ($condition !== null ? self::compare($serial, $condition) : ($index > 0 || ($count === 2 ? $serial >= 0 : $serial > 0))) { $selected = $index; break; }
            }
        } else { $selected = $serial < 0 && $count > 1 ? 1 : ($serial == 0.0 && $count > 2 ? 2 : 0); }
        if ($selected === null || $sections[$selected]['parts'] === null) { return null; }
        $section = $sections[$selected];
        if (!$section['date'] && $section['numeric']) { return ['v' => $serial, 't' => 'number']; }
        if (!$section['date']) { return ['v' => self::renderParts($section['parts'], 0, 0, 0, 0), 't' => 'date']; }
        $scale = 10 ** $section['precision'];
        if (abs($serial) > (PHP_INT_MAX / 86400 / $scale) - 30000) { throw new \UnexpectedValueException('Spreadsheet serial exceeds supported integer precision.'); }
        $elapsed = $section['elapsed'] !== null;
        $value = $elapsed || (!$conditional && $serial < 0 && $selected === 1) ? abs($serial) : $serial;
        $days = (int)floor($value); $ticks = (int)round(($value - $days) * (86400 * $scale));
        if ($ticks >= 86400 * $scale) { ++$days; $ticks -= 86400 * $scale; }
        $seconds = intdiv($ticks, $scale); $fraction = $ticks % $scale; $total = $days * 86400 + $seconds;
        $calendarDay = $days + ($date1904 ? 1462 : 0);
        if ($calendarDay <= 59) { ++$calendarDay; }
        $timestamp = $elapsed ? $seconds : ($calendarDay - 25569) * 86400 + $seconds;
        $result = self::renderParts($section['parts'], $timestamp, $total, $fraction, $section['precision']);
        if ($elapsed && $serial < 0 && ($count === 1 || $conditional)) { $result = '-' . $result; }
        return ['v' => $result, 't' => 'date'];
    }

    /** Compare raw serials before any rounding, epoch conversion or sign normalization. */
    private static function compare(float $value, array $condition): bool
    {
        return match ($condition[0]) { '<' => $value < $condition[1], '<=' => $value <= $condition[1], '>' => $value > $condition[1],
            '>=' => $value >= $condition[1], '=' => $value == $condition[1], '<>' => $value != $condition[1] };
    }

    /** Keep fractional seconds of explicit ISO date cells using PHP's parser and the workbook's native date system. */
    public static function isoSerial(string $value, bool $date1904): float
    {
        $date = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        $serial = ($date->getTimestamp() + (int)$date->format('u') / 1000000) / 86400 + 25569;
        if ($date1904) { return $serial - 1462; }
        return $serial < 61 ? $serial - 1 : $serial;
    }

    /** Render a compiled part list using UTC fields and integer elapsed/fraction components. */
    private static function renderParts(array $parts, int $timestamp, int $total, int $fraction, int $precision): string
    {
        $result = '';
        foreach ($parts as [$type, $value]) {
            $result .= match ($type) {
                'mask' => gmdate($value, $timestamp), 'minute' => (string)(int)gmdate('i', $timestamp), 'second' => (string)(int)gmdate('s', $timestamp),
                'month-initial' => gmdate('M', $timestamp)[0], 'short-upper' => gmdate('A', $timestamp)[0], 'short-lower' => gmdate('a', $timestamp)[0],
                'elapsed-h' => str_pad((string)intdiv($total, 3600), $value, '0', STR_PAD_LEFT),
                'elapsed-m' => str_pad((string)intdiv($total, 60), $value, '0', STR_PAD_LEFT),
                'elapsed-s' => str_pad((string)$total, $value, '0', STR_PAD_LEFT),
                'fraction' => str_pad((string)intdiv($fraction, 10 ** ($precision - $value)), $value, '0', STR_PAD_LEFT),
            };
        }
        return $result;
    }

    /** Escape literal ASCII letters/backslashes for gmdate while preserving UTF-8 bytes. */
    private static function literal(string $literal): string
    {
        $result = '';
        for ($offset = 0, $length = strlen($literal); $offset < $length; ++$offset) {
            $character = $literal[$offset];
            if (($character >= 'A' && $character <= 'Z') || ($character >= 'a' && $character <= 'z') || $character === '\\') { $result .= '\\'; }
            $result .= $character;
        }
        return $result;
    }
}
