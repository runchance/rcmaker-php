<?php

declare(strict_types=1);

namespace RC\Cli;

use RuntimeException;

/** Resolve trusted build-time INI inputs without serializing parsed values into a release. */
final class CustomIni
{
    public const MAX_BYTES = 1048576;
    private const ASSIGNMENT = '/\G[ \t]*[A-Za-z0-9_.-]+(?:\[[A-Za-z0-9_.-]*\])?[ \t]*=/';

    /** Accept a local path, legacy one-line shorthand, file:<path>, or ini:<verbatim text>. */
    public static function resolve(string $value, string $rootPath): string
    {
        if (str_contains($value, "\0") || strlen($value) > self::MAX_BYTES + 5) {
            throw new RuntimeException('Custom INI input contains NUL bytes or exceeds its size limit.');
        }
        if (trim($value) === '') { return ''; }
        $candidate = ltrim($value, " \t");
        if (str_starts_with($candidate, 'file:')) {
            $contents = self::read(substr($candidate, 5), $rootPath);
        } elseif (str_starts_with($candidate, 'ini:')) {
            $contents = substr($candidate, 4);
        } elseif (preg_match('~\A[a-z][a-z0-9+.-]*://~i', $candidate)) {
            throw new RuntimeException('Custom INI file paths must be local filesystem paths.');
        } elseif (strpbrk($value, "\r\n") !== false || str_contains($value, '=') || str_contains(';#[', $candidate[0])) {
            $contents = $value;
            if (strpbrk($value, "\r\n") === false && preg_match(self::ASSIGNMENT, $value, $match, 0, 0) === 1) {
                $contents = self::shorthand($value, strlen($match[0]));
            }
        } else {
            $contents = self::read(trim($value), $rootPath);
        }
        if (strlen($contents) > self::MAX_BYTES || str_contains($contents, "\0")) {
            throw new RuntimeException('Custom INI must not contain NUL bytes or exceed 1 MiB.');
        }
        // NORMAL checks the startup grammar. Its resolved values are discarded: only original bytes are embedded.
        if (@parse_ini_string($contents, true, INI_SCANNER_NORMAL) === false) {
            throw new RuntimeException('Custom INI syntax is invalid; use a standard php.ini file for complex settings.');
        }
        return $contents;
    }

    /** Read bounded bytes from a regular local file; configuration source links may resolve to regular files. */
    private static function read(string $path, string $rootPath): string
    {
        if ($path === '') { throw new RuntimeException('Custom INI file path is empty.'); }
        try { $path = Filesystem::resolvePath($path, $rootPath); }
        catch (\InvalidArgumentException $error) { throw new RuntimeException('Custom INI file requires an unambiguous local path.', 0, $error); }
        if (!is_file($path)) { throw new RuntimeException('Custom INI file does not exist or is not a regular file: ' . $path); }
        $stream = @fopen($path, 'rb');
        if (!is_resource($stream)) { throw new RuntimeException('Cannot open custom INI file: ' . $path); }
        try {
            $stat = fstat($stream);
            if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['size'] > self::MAX_BYTES) {
                throw new RuntimeException('Custom INI requires a regular file of at most 1 MiB.');
            }
            $contents = stream_get_contents($stream, self::MAX_BYTES + 1);
            if ($contents === false) { throw new RuntimeException('Cannot read custom INI file: ' . $path); }
            $after = fstat($stream);
            if ($after === false || strlen($contents) !== $stat['size'] || $after['size'] !== $stat['size']) {
                throw new RuntimeException('Custom INI file changed size or was not read completely.');
            }
            return $contents;
        } finally { fclose($stream); }
    }

    /** Split unquoted semicolons only before another simple assignment; preserve quotes and the rest of a comment. */
    private static function shorthand(string $value, int $offset): string
    {
        $quote = null; $length = strlen($value);
        for ($index = $offset; $index < $length; ++$index) {
            $character = $value[$index];
            if ($quote !== null) {
                if ($quote === '"' && $character === '\\') { ++$index; }
                elseif ($character === $quote) { $quote = null; }
                continue;
            }
            if ($character === '"' || $character === "'") { $quote = $character; continue; }
            if ($character !== ';') { continue; }
            if (preg_match(self::ASSIGNMENT, $value, $match, 0, $index + 1) !== 1) { break; }
            $value[$index] = "\n";
            $index += strlen($match[0]);
        }
        return $value;
    }
}
