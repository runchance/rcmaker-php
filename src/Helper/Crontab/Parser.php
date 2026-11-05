<?php

declare(strict_types=1);

namespace RC\Helper\Crontab;

require_once __DIR__ . '/CalendarFields.php';

/** Adapt pristine cron-expression fields to RC's five/six-field, AND-day, within-one-minute API. */
class Parser
{
    private const LIMIT = 32;
    private const RANGES = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];
    private const DATE_KEYS = ['minutes', 'hours', 'mday', 'mon', 'wday'];
    private const DATE_FORMATS = ['i', 'G', 'j', 'n', 'w'];
    private const MONTHS = ['JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6,
        'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12];
    private const WEEKDAYS = ['SUN' => 0, 'MON' => 1, 'TUE' => 2, 'WED' => 3, 'THU' => 4, 'FRI' => 5, 'SAT' => 6];

    private static ?CalendarFields $factory = null;
    private array $compiled = [];

    /** Return sorted timestamps in the selected local minute, not an unbounded next-run search. */
    public function parse($crontab_string, $start_time = null, ?string $timezone = null): array
    {
        if (!is_string($crontab_string) || ($start_time !== null && !is_int($start_time))) {
            throw new \InvalidArgumentException('Cron requires a string rule and an integer Unix timestamp.');
        }
        [$fields, $seconds] = $this->compile($crontab_string);
        $timestamp = $start_time ?? time();
        if ($fields === [] && $timezone === null) {
            $base = $timestamp - (int) date('s', $timestamp);
            return array_map(static fn(int $second): int => $base + $second, $seconds);
        }
        $date = null;
        if ($timezone === null) {
            $values = getdate($timestamp);
        } else {
            $date = (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone($timezone));
            $values = ['seconds' => (int) $date->format('s')];
            foreach (self::DATE_KEYS as $position => $key) {
                $values[$key] = (int) $date->format(self::DATE_FORMATS[$position]);
            }
        }
        foreach ($fields as $position => $field) {
            if (is_array($field)) {
                if (!isset($field[$values[self::DATE_KEYS[$position]]])) {
                    return [];
                }
                continue;
            }
            $date ??= (new \DateTimeImmutable('@' . $timestamp))
                ->setTimezone(new \DateTimeZone(date_default_timezone_get()));
            if (!self::fields()->getField($position)->isSatisfiedBy($date, $field, false)) {
                return [];
            }
        }
        $base = $timestamp - $values['seconds'];
        return array_map(static fn(int $second): int => $base + $second, $seconds);
    }

    /** Validate and precompile without timers, calendar searches, sessions or process registration. */
    public function isValid(string $crontab_string): bool
    {
        try {
            $this->compile($crontab_string);
            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /** Bound retained expressions; every scheduled task keeps its own single precompiled rule. */
    private function compile(string $expression): array
    {
        if (str_contains($expression, "\0")) {
            throw new \InvalidArgumentException('Cron rules cannot contain NUL bytes.');
        }
        $expression = trim($expression);
        if (isset($this->compiled[$expression])) {
            return $this->compiled[$expression];
        }
        if ($expression === '' || strlen($expression) > 1024) {
            throw new \InvalidArgumentException('Cron rule is empty or longer than 1024 bytes.');
        }
        $expanded = \Cron\CronExpression::MAPPINGS[strtolower($expression)] ?? $expression;
        $parts = preg_split('/\s+/', strtoupper($expanded));
        $second = count($parts) === 6 ? array_shift($parts) : '0';
        if (count($parts) !== 5) {
            throw new \InvalidArgumentException('Cron requires five fields, or six with leading seconds.');
        }
        foreach ($parts as $position => &$part) {
            $part = self::normalize($part, $position);
            if (!self::fields()->getField($position)->validate($part)) {
                throw new \InvalidArgumentException('Invalid cron calendar field.');
            }
        }
        unset($part);
        if ($parts[2] === '?' && $parts[4] === '?') {
            throw new \InvalidArgumentException('Both cron day fields cannot be unspecified.');
        }
        $second = self::normalize($second, 0);
        $seconds = self::values($second, 0);
        $parts = array_filter($parts, static fn(string $part): bool => $part !== '*' && $part !== '?');
        foreach ($parts as $position => &$part) {
            if (strpbrk($part, 'LW#') === false) {
                $part = array_fill_keys(self::values($part, $position), true);
            }
        }
        unset($part);
        if (count($this->compiled) >= self::LIMIT) {
            array_shift($this->compiled);
        }
        return $this->compiled[$expression] = [$parts, $seconds];
    }

    /** Expand native numeric ranges once; normalize maximal steps and Sunday aliases without rewriting the engine. */
    private static function values(string $expression, int $position): array
    {
        $field = self::fields()->getField($position);
        [$min, $max] = self::RANGES[$position];
        $selected = [];
        foreach (explode(',', $expression) as $part) {
            $values = $min === 0 && $part === '0-' . $max . '/' . $max ? [0, $max]
                : $field->getRangeForExpression($part === '*' ? $min . '-' . $max : $part, $max);
            foreach ($values as $value) {
                $value = (int) $value;
                $selected[$position === 4 && $value === 7 ? 0 : $value] = true;
            }
        }
        ksort($selected, SORT_NUMERIC);
        return array_keys($selected);
    }

    /** Share only immutable native field flyweights, never tasks or request-specific state. */
    private static function fields(): CalendarFields
    {
        return self::$factory ??= new CalendarFields();
    }

    /** Reject unsafe/malformed syntax before native calls; normalize known native large-step/range edge cases. */
    private static function normalize(string $field, int $position): string
    {
        if ($field === '?' && in_array($position, [2, 4], true)) {
            return $field;
        }
        if ($position === 2) {
            if (in_array($field, ['L', 'LW'], true)) {
                return $field;
            }
            if (preg_match('/^([0-9]+)W$/D', $field, $special)) {
                return self::number($special[1], $position) . 'W';
            }
        }
        if ($position === 4 && preg_match('/^([A-Z]{3}|[0-9]+)(L|#[1-5])$/D', $field, $special)) {
            return self::number($special[1], $position) . $special[2];
        }
        [$min, $max] = self::RANGES[$position];
        $result = [];
        foreach (explode(',', $field) as $part) {
            if (!preg_match('/^(\*|[A-Z]{3}|[0-9]+)(?:-([A-Z]{3}|[0-9]+))?(?:\/([0-9]+))?$/D', $part, $match)) {
                throw new \InvalidArgumentException('Invalid cron field syntax.');
            }
            $wildcard = $match[1] === '*';
            $from = $wildcard ? $min : self::number($match[1], $position);
            $hasRange = isset($match[2]) && $match[2] !== '';
            $hasStep = isset($match[3]) && $match[3] !== '';
            if ($wildcard && $hasRange) {
                throw new \InvalidArgumentException('A cron range cannot start with a wildcard.');
            }
            $to = $hasRange ? self::number($match[2], $position) : (($wildcard || $hasStep) ? $max : $from);
            if ($from > $to) {
                throw new \InvalidArgumentException('Cron ranges must be ascending.');
            }
            $step = 1;
            if ($hasStep) {
                $digits = ltrim($match[3], '0');
                if ($digits === '') {
                    throw new \InvalidArgumentException('Cron steps must be positive.');
                }
                // A step wider than this finite range selects only its first value; never wrap or overflow.
                if (strlen($digits) > 2 || (int) $digits > $to - $from) {
                    $result[] = (string) $from;
                    continue;
                }
                $step = (int) $digits;
            }
            if (!$hasStep && $wildcard) {
                $result[] = '*';
            } elseif ($from === $to) {
                $result[] = (string) $from;
            } else {
                $result[] = $from . '-' . $to . ($hasStep ? '/' . $step : '');
            }
        }
        return implode(',', array_unique($result));
    }

    /** Convert field-local names/decimal integers with explicit bounds, not PHP's loose numeric coercion. */
    private static function number(string $value, int $position): int
    {
        $names = $position === 3 ? self::MONTHS : ($position === 4 ? self::WEEKDAYS : []);
        if (isset($names[$value])) {
            return $names[$value];
        }
        $digits = ltrim($value, '0');
        if (preg_match('/^[0-9]+$/D', $value) !== 1 || strlen($digits) > 2) {
            throw new \InvalidArgumentException('Invalid cron field value.');
        }
        $value = (int) $value;
        [$min, $max] = self::RANGES[$position];
        if ($value < $min || $value > $max) {
            throw new \InvalidArgumentException('Cron field value is outside its range.');
        }
        return $value;
    }
}
