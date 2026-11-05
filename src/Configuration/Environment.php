<?php

declare(strict_types=1);

namespace RC\Configuration;

/** Parse the existing INI-style .env without expanding PHP constants or process secrets. */
final class Environment
{
    private const BOOLEANS = ['true' => '1', 'on' => '1', 'yes' => '1', 'false' => '', 'off' => '', 'no' => '', 'none' => '', 'null' => ''];

    /** Preserve legacy flags and quoted literals without expanding constants or variables. */
    public static function read(string $path): array
    {
        if (str_contains($path, "\0") || (str_contains($path, '://') && !str_starts_with(strtolower($path), 'phar://'))) {
            throw new \InvalidArgumentException('Environment paths must be local or PHAR files.');
        }
        if (!is_file($path)) {
            if (file_exists($path) || is_link($path)) { throw new \RuntimeException('Environment path is not a readable regular file.'); }
            return [];
        }
        $source = @file_get_contents($path);
        if ($source === false) { throw new \RuntimeException('Cannot read the environment file.'); }
        [$source, $literals] = self::protectLiterals($source);
        $raw = @parse_ini_string($source, true, INI_SCANNER_RAW);
        if ($raw === false) { throw new \RuntimeException('Invalid INI environment configuration.'); }
        $metadata = @parse_ini_string(self::valueMetadata($source), true, INI_SCANNER_RAW);
        if ($metadata === false) { throw new \RuntimeException('Unsupported INI environment quoting.'); }
        return self::applyMetadata($raw, $metadata, $literals);
    }

    /** Replace complete quoted values before RAW parsing; PHP still owns INI keys, sections and arrays. */
    private static function protectLiterals(string $source): array
    {
        if (str_contains($source, "\0")) { throw new \RuntimeException('Invalid INI environment configuration.'); }
        $prefix = '__rc_ini_literal_';
        while (str_contains($source, $prefix)) { $prefix .= '_'; }
        $length = strlen($source);
        $cursor = $copied = 0;
        $normalized = '';
        $literals = [];
        while ($cursor < $length) {
            $lineEnd = $cursor + strcspn($source, "\r\n", $cursor);
            $statement = substr($source, $cursor, $lineEnd - $cursor);
            $trimmed = ltrim($cursor === 0 ? preg_replace('/\A\xEF\xBB\xBF/', '', $statement) : $statement);
            $equal = strpos($statement, '=');
            if ($trimmed !== '' && !in_array($trimmed[0], ['[', ';', '#'], true) && $equal !== false) {
                $start = $cursor + $equal + 1;
                $start += strspn($source, " \t", $start);
                if ($start < $length && in_array($source[$start], ['"', "'"], true)) {
                    [$end, $guarded] = self::quotedExpression($source, $start);
                    $tail = $end + strspn($source, " \t", $end);
                    if ($tail === $length || in_array($source[$tail], ["\r", "\n", ';'], true)) {
                        // Only quoted tokens reach NORMAL mode, with interpolation disabled first.
                        $decoded = @parse_ini_string('value=' . $guarded . "\n", false, INI_SCANNER_NORMAL);
                        if (!is_array($decoded) || array_keys($decoded) !== ['value'] || !is_string($decoded['value'])) {
                            throw new \RuntimeException('Invalid quoted INI environment value.');
                        }
                        $marker = $prefix . count($literals);
                        $literals[$marker] = $decoded['value'];
                        $normalized .= substr($source, $copied, $start - $copied) . '"' . $marker . '"';
                        $copied = $end;
                        $lineEnd = $tail + strcspn($source, "\r\n", $tail);
                    }
                }
            }
            $cursor = $lineEnd;
            if ($cursor < $length && $source[$cursor] === "\r") { ++$cursor; }
            if ($cursor < $length && $source[$cursor] === "\n") { ++$cursor; }
        }
        return [$normalized . substr($source, $copied), $literals];
    }

    /** Isolate adjacent quoted tokens and escape only unescaped dollars before invoking PHP's literal decoder. */
    private static function quotedExpression(string $source, int $start): array
    {
        $length = strlen($source);
        $cursor = $start;
        $guarded = '';
        do {
            $quote = $source[$cursor++];
            $guarded .= $quote;
            $closed = false;
            while ($cursor < $length) {
                $character = $source[$cursor++];
                if ($character === $quote) {
                    $guarded .= $character;
                    $closed = true;
                    break;
                }
                if ($quote === '"' && $character === '\\' && $cursor < $length) {
                    // PHP permits a trailing Windows path backslash before a quote at the line end.
                    if ($source[$cursor] === '"' && ($cursor + 1 === $length || in_array($source[$cursor + 1], ["\r", "\n"], true))) {
                        $guarded .= '\\"';
                        ++$cursor;
                        $closed = true;
                        break;
                    }
                    $guarded .= $character . $source[$cursor++];
                } else {
                    $guarded .= $quote === '"' && $character === '$' ? '\\$' : $character;
                }
            }
            if (!$closed) { throw new \RuntimeException('Unterminated quoted INI environment value.'); }
            $next = $cursor + strspn($source, " \t", $cursor);
            if ($next >= $length || !in_array($source[$next], ['"', "'"], true)) { break; }
            $guarded .= substr($source, $cursor, $next - $cursor);
            $cursor = $next;
        } while (true);
        return [$cursor, $guarded];
    }

    /** PHP parses keys/sections twice; metadata distinguishes flags and literal quote delimiters. */
    private static function valueMetadata(string $source): string
    {
        return implode("\n", array_map(self::maskStatement(...), preg_split('/\r\n|\r|\n/', $source)));
    }

    /** Bare flags need legacy conversion; protected quoted literals must never become boolean words. */
    private static function maskStatement(string $statement): string
    {
        $trimmed = ltrim($statement);
        if ($trimmed === '' || in_array($trimmed[0], ['[', ';', '#'], true)) { return $statement; }
        $equal = strpos($statement, '=');
        if ($equal !== false) {
            $value = substr($statement, $equal + 1);
            if (preg_match('/\A\s*(true|on|yes|false|off|no|none|null)\s*(?:[;#][^\r\n]*)?\s*\z/iD', $value, $match)) {
                return substr($statement, 0, $equal + 1) . strtolower($match[1]);
            }
            return substr($statement, 0, $equal + 1) . '"__rc_non_boolean__"';
        }
        return $statement;
    }

    /** Require identical parser structure; unsupported syntax must not silently enable a false flag. */
    private static function applyMetadata(array $raw, array $metadata, array $literals): array
    {
        if (array_keys($raw) !== array_keys($metadata)) { throw new \RuntimeException('Inconsistent INI environment structure.'); }
        foreach ($raw as $key => $value) {
            if (is_array($value)) {
                if (!is_array($metadata[$key])) { throw new \RuntimeException('Inconsistent INI environment arrays.'); }
                $raw[$key] = self::applyMetadata($value, $metadata[$key], $literals);
            } elseif (is_string($value) && isset($literals[$value])) {
                $raw[$key] = $literals[$value];
            } elseif (is_string($value) && is_string($metadata[$key])
                && isset(self::BOOLEANS[strtolower($metadata[$key])]) && strcasecmp($value, $metadata[$key]) === 0) {
                $raw[$key] = self::BOOLEANS[strtolower($metadata[$key])];
            }
        }
        return $raw;
    }
}
