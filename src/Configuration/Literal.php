<?php

declare(strict_types=1);

namespace RC\Configuration;

/** Persistence accepts PHP literal data, never resolved env calls, closures or object construction. */
final class Literal
{
    private array $tokens;
    private int $offset = 0;

    /** Validate source grammar before executing a file during a persistent update. */
    public static function assertSource(string $source): void
    {
        if (!function_exists('token_get_all')) { throw new \RuntimeException('Config persistence requires ext-tokenizer.'); }
        $parser = new self();
        $parser->tokens = array_values(array_filter(token_get_all($source, TOKEN_PARSE), static fn($token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        $parser->expect(T_OPEN_TAG);
        if ($parser->is(T_DECLARE)) {
            $parser->offset++; $parser->expect('('); $parser->expect(T_STRING, 'strict_types'); $parser->expect('=');
            if (!$parser->is(T_LNUMBER) || !in_array($parser->tokens[$parser->offset][1], ['0', '1'], true)) { $parser->reject(); }
            $parser->offset++; $parser->expect(')'); $parser->expect(';');
        }
        $parser->expect(T_RETURN); $parser->value(0); $parser->expect(';');
        if ($parser->is(T_CLOSE_TAG)) { $parser->offset++; }
        if ($parser->offset !== count($parser->tokens)) { $parser->reject(); }
    }

    /** Generated exports stay executable across repeated writes, including NUL strings and PHP_INT_MIN. */
    public static function export(array $value): string
    {
        self::assertValue($value, 0);
        $source = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($value, true) . ";\n";
        self::assertSource($source);
        return $source;
    }

    /** Reject recursive/oversized-depth graphs and values that var_export cannot restore safely. */
    private static function assertValue(mixed $value, int $depth): void
    {
        if ($depth > 64) { throw new \InvalidArgumentException('Config literal nesting exceeds 64 levels.'); }
        if (is_array($value)) { foreach ($value as $item) { self::assertValue($item, $depth + 1); } }
        elseif (!is_null($value) && !is_scalar($value)) { throw new \InvalidArgumentException('Config persistence supports scalar and array values only.'); }
        elseif (is_float($value) && !is_finite($value)) { throw new \InvalidArgumentException('Config floats must be finite.'); }
    }

    /** Recognize data expressions only; a string followed by () must never become a function call. */
    private function value(int $depth): void
    {
        if ($depth > 64) { $this->reject(); }
        if ($this->is('[')) { $this->offset++; $this->entries(']', $depth + 1); return; }
        if ($this->is(T_ARRAY)) { $this->offset++; $this->expect('('); $this->entries(')', $depth + 1); return; }
        if ($this->is('(')) { $this->offset++; $this->value($depth + 1); $this->expect(')'); return; }
        if ($this->is(T_CONSTANT_ENCAPSED_STRING)) {
            $this->offset++;
            while ($this->is('.')) { $this->offset++; $this->expect(T_CONSTANT_ENCAPSED_STRING); }
            return;
        }
        if ($this->is(T_STRING)) {
            if (!in_array(strtolower($this->tokens[$this->offset][1]), ['true', 'false', 'null'], true)) { $this->reject(); }
            $this->offset++; return;
        }
        if ($this->is('+') || $this->is('-')) { $this->offset++; }
        $this->number();
        while ($this->is('+') || $this->is('-')) { $this->offset++; $this->number(); }
    }

    /** Support short/long arrays, explicit keys and trailing commas, not unpacking or references. */
    private function entries(string $end, int $depth): void
    {
        while (!$this->is($end)) {
            $this->value($depth);
            if ($this->is(T_DOUBLE_ARROW)) { $this->offset++; $this->value($depth); }
            if ($this->is($end)) { break; }
            $this->expect(',');
        }
        $this->expect($end);
    }

    /** Only numeric tokens can participate in the PHP_INT_MIN export expression. */
    private function number(): void
    {
        if (!$this->is(T_LNUMBER) && !$this->is(T_DNUMBER)) { $this->reject(); }
        $this->offset++;
    }

    /** Compare a token without evaluating source text. */
    private function is(int|string $type): bool
    {
        $token = $this->tokens[$this->offset] ?? null;
        return is_int($type) ? is_array($token) && $token[0] === $type : $token === $type;
    }

    /** Consume one required token, optionally matching a fixed identifier. */
    private function expect(int|string $type, ?string $text = null): void
    {
        if (!$this->is($type) || ($text !== null && strtolower($this->tokens[$this->offset][1]) !== $text)) { $this->reject(); }
        $this->offset++;
    }

    /** Explain why resolved executable configuration must not be flattened into a generated file. */
    private function reject(): never
    {
        throw new \InvalidArgumentException('Config::set only persists literal-data PHP files; edit source for env expressions, callbacks or executable configuration.');
    }
}
