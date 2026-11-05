<?php

declare(strict_types=1);

namespace RC\Helper\Pdf;

use Com\Tecnick\Pdf\Font\Exception as FontException;
use PhpToken;

/** Read generated TCPDF PHP font data as literals, never as executable PHP. */
final class LegacyFontDefinition
{
    public const SIDECAR_SUFFIX = '.rcfont.json';
    public const MAX_SOURCE_BYTES = 8 * 1024 * 1024;
    private array $tokens;
    private int $position = 0;
    private int $values = 0;
    private const FIELDS = ['type', 'name', 'displayname', 'desc', 'up', 'ut', 'dw', 'cw', 'enc', 'diff',
        'file', 'ctg', 'originalsize', 'size1', 'size2', 'cbbox', 'cidinfo', 'subset'];

    /** Recognize protected bytes without executing the RCMB program. */
    public static function isProtected(string $bytes): bool
    {
        return str_starts_with($bytes, 'RCMB');
    }

    /** Treat a truncated comment/variable as undecided, while skipping ordinary class/namespace/return files. */
    public static function mayContainData(string $prefix): bool
    {
        $tokens = PhpToken::tokenize($prefix);
        if (($tokens[0]->id ?? null) !== T_OPEN_TAG) { return false; }
        foreach ($tokens as $index => $token) {
            if ($index === 0 || $token->isIgnorable()) { continue; }
            if ($token->text === '$') { return true; }
            if ($token->id !== T_VARIABLE) { return false; }
            foreach (self::FIELDS as $field) {
                if (str_starts_with('$'.$field, $token->text)) { return true; }
            }
            return false;
        }
        // The prefix can end before the first assignment; full parsing still enforces grammar and size.
        return true;
    }

    /** Serialize data-only metadata bound to the exact published PHP bytes; this is not an authenticity signature. */
    public static function metadata(array $font, string $sourceHash, string $fileHash): string
    {
        $record = ['schema' => 'rcmaker-font-v1', 'source_sha256' => $sourceHash, 'file_sha256' => $fileHash, 'font' => $font];
        $json = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::readMetadata($json);
        return $json;
    }

    /** Validate reserved sidecar shape without trusting it to override native font paths or document state. */
    public static function readMetadata(string $json): array
    {
        if (strlen($json) > 16 * 1024 * 1024) { throw new FontException('Legacy font metadata exceeds 16 MiB.'); }
        try { $record = json_decode($json, true, 16, JSON_THROW_ON_ERROR); }
        catch (\JsonException $error) { throw new FontException('Invalid legacy font metadata JSON.', 0, $error); }
        if (!is_array($record) || ($record['schema'] ?? null) !== 'rcmaker-font-v1') { throw new FontException('Unknown legacy font metadata schema.'); }
        foreach (['source_sha256', 'file_sha256'] as $key) {
            if (!is_string($record[$key] ?? null) || preg_match('/\A[0-9a-f]{64}\z/D', $record[$key]) !== 1) {
                throw new FontException('Invalid legacy font metadata digest.');
            }
        }
        $font = $record['font'] ?? null;
        if (!is_array($font) || !is_string($font['type'] ?? null) || !is_string($font['name'] ?? null)
            || !is_array($font['desc'] ?? null) || !is_array($font['cw'] ?? null) || $font['cw'] === []
            || array_diff(array_keys($font), [...self::FIELDS, 'isUnicode', 'enc_map', 'cwu']) !== []) {
            throw new FontException('Invalid legacy font metadata fields.');
        }
        return $record;
    }

    /** Reject stale metadata before native loading; both resources still need the document's allowlist. */
    public static function fromMetadata(string $fontBytes, string $json): array
    {
        $record = self::readMetadata($json);
        if (!hash_equals($record['file_sha256'], hash('sha256', $fontBytes))) { throw new FontException('Legacy font metadata does not match the protected font file.'); }
        return $record['font'];
    }

    /** Decode one static generated font file; calls, includes, interpolation and other PHP statements are rejected. */
    public static function decode(string $source): array
    {
        if (strlen($source) > self::MAX_SOURCE_BYTES) { throw new FontException('Legacy font definition exceeds 8 MiB.'); }
        try { $tokens = PhpToken::tokenize($source, TOKEN_PARSE); }
        catch (\ParseError $error) { throw new FontException('Invalid legacy font data syntax.', 0, $error); }
        if (count($tokens) > 1_000_000 || ($tokens[0]->id ?? null) !== T_OPEN_TAG) { throw new FontException('Invalid legacy font data.'); }
        $parser = new self();
        $parser->tokens = array_values(array_filter($tokens, static fn(PhpToken $token): bool => !$token->isIgnorable() && $token->id !== T_OPEN_TAG));
        $data = [];
        while (isset($parser->tokens[$parser->position])) {
            $token = $parser->tokens[$parser->position++];
            if ($token->id === T_CLOSE_TAG) {
                foreach (array_slice($parser->tokens, $parser->position) as $tail) {
                    if ($tail->id !== T_INLINE_HTML || trim($tail->text) !== '') { throw new FontException('Output is not allowed in legacy font data.'); }
                }
                break;
            }
            $field = substr($token->text, 1);
            if ($token->id !== T_VARIABLE || !in_array($field, self::FIELDS, true)) {
                throw new FontException('Legacy font files must contain only supported static data assignments.');
            }
            $parser->expect('=');
            $data[$field] = $parser->value(0);
            $parser->expect(';');
        }
        if (!is_string($data['type'] ?? null) || !is_string($data['name'] ?? null)
            || !is_array($data['desc'] ?? null) || !is_array($data['cw'] ?? null) || $data['cw'] === []) {
            throw new FontException('Incomplete legacy font definition.');
        }
        $data['type'] = $data['type'] === 'core' ? 'Core' : $data['type'];
        if (!in_array($data['type'], ['Core', 'TrueType', 'TrueTypeUnicode', 'Type1', 'cidfont0'], true)) {
            throw new FontException('Unsupported legacy font type.');
        }
        $data['isUnicode'] = $data['type'] === 'TrueTypeUnicode';
        if (in_array($data['type'], ['Core', 'Type1', 'TrueType'], true)) {
            $encoding = $data['enc'] ?? '';
            if ($encoding === '' && $data['type'] === 'Core') {
                $encoding = $data['name'] === 'Symbol' ? 'symbol' : 'cp1252';
            }
            $map = is_string($encoding) ? (\Com\Tecnick\Unicode\Data\Encoding::MAP[$encoding] ?? []) : [];
            $data['enc_map'] = $map;
            $data['cwu'] = [];
            foreach ($map as $byte => $glyph) {
                if ($glyph === '.notdef') { continue; }
                try { $unicode = $encoding === 'symbol' ? LegacyFontEncoding::unicode($glyph)
                    : mb_ord(mb_convert_encoding(chr($byte), 'UTF-8', $encoding), 'UTF-8'); }
                catch (\ValueError) { $unicode = LegacyFontEncoding::unicode($glyph); }
                if ($unicode !== null && isset($data['cw'][$byte])) { $data['cwu'][$unicode] = $data['cw'][$byte]; }
            }
        }
        return $data;
    }

    /** Consume punctuation without accepting concatenation, expressions or variable references. */
    private function expect(string $text): void
    {
        if (($this->tokens[$this->position++]->text ?? null) !== $text) { throw new FontException('Nonliteral legacy font data.'); }
    }

    /** Parse bounded scalar/array literals; recursive input cannot turn into PHP execution. */
    private function value(int $depth): mixed
    {
        if ($depth > 8 || ++$this->values > 1_000_000 || !isset($this->tokens[$this->position])) {
            throw new FontException('Legacy font data limit exceeded.');
        }
        $token = $this->tokens[$this->position++];
        if ($token->id === T_ARRAY || $token->text === '[') {
            $end = $token->id === T_ARRAY ? ')' : ']';
            if ($token->id === T_ARRAY) { $this->expect('('); }
            $result = [];
            while (($this->tokens[$this->position]->text ?? null) !== $end) {
                $first = $this->value($depth + 1);
                if (($this->tokens[$this->position]->id ?? null) === T_DOUBLE_ARROW) {
                    ++$this->position;
                    if (!is_int($first) && !is_string($first)) { throw new FontException('Invalid legacy font array key.'); }
                    $result[$first] = $this->value($depth + 1);
                } else { $result[] = $first; }
                if (($this->tokens[$this->position]->text ?? null) === $end) { break; }
                $this->expect(',');
            }
            $this->expect($end);
            return $result;
        }
        $sign = 1;
        $signed = $token->text === '-' || $token->text === '+';
        if ($signed) {
            $sign = $token->text === '-' ? -1 : 1;
            $token = $this->tokens[$this->position++] ?? null;
        }
        if ($token?->id === T_LNUMBER || $token?->id === T_DNUMBER) {
            $number = str_replace('_', '', $token->text);
            $value = $sign * ($token->id === T_LNUMBER ? intval($number, 0) : (float) $number);
            if (!is_finite((float) $value)) { throw new FontException('Nonfinite legacy font number.'); }
            return $value;
        }
        if ($signed || $token === null) { throw new FontException('Invalid legacy font scalar.'); }
        if ($token->id === T_CONSTANT_ENCAPSED_STRING) { return $this->string($token->text); }
        if ($token->id === T_STRING && in_array(strtolower($token->text), ['true', 'false', 'null'], true)) {
            return match (strtolower($token->text)) { 'true' => true, 'false' => false, default => null };
        }
        throw new FontException('Executable PHP is not allowed in legacy font definitions.');
    }

    /** Decode PHP constant-string escapes without eval, include or interpolation. */
    private function string(string $literal): string
    {
        if ($literal[0] === 'b' || $literal[0] === 'B') { $literal = substr($literal, 1); }
        $quote = $literal[0]; $text = substr($literal, 1, -1);
        if ($quote === "'") {
            $result = '';
            for ($i = 0, $length = strlen($text); $i < $length; ++$i) {
                if ($text[$i] === '\\' && isset($text[$i + 1]) && in_array($text[$i + 1], ['\\', "'"], true)) { ++$i; }
                $result .= $text[$i];
            }
            return $result;
        }
        return preg_replace_callback('/\\\\(x[0-9A-Fa-f]{1,2}|[0-7]{1,3}|u\{[0-9A-Fa-f]+\}|[nrtvef\\\\$"])/', static function (array $match): string {
            $escape = $match[1];
            $simple = ['n' => "\n", 'r' => "\r", 't' => "\t", 'v' => "\v", 'e' => "\x1b", 'f' => "\f", '\\' => '\\', '$' => '$', '"' => '"'];
            if (isset($simple[$escape])) { return $simple[$escape]; }
            if ($escape[0] === 'x') { return chr(hexdec(substr($escape, 1))); }
            if ($escape[0] !== 'u') { return chr(octdec($escape) & 255); }
            $code = hexdec(substr($escape, 2, -1));
            if ($code > 0x10FFFF || ($code >= 0xD800 && $code <= 0xDFFF)) { throw new FontException('Invalid legacy font Unicode escape.'); }
            $encoded = $code <= 0xFFFF ? sprintf('\\u%04X', $code)
                : sprintf('\\u%04X\\u%04X', 0xD800 + (($code - 0x10000) >> 10), 0xDC00 + (($code - 0x10000) & 1023));
            return json_decode('"'.$encoded.'"', true, 512, JSON_THROW_ON_ERROR);
        }, $text);
    }
}
