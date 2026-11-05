<?php

namespace RC\Helper\GlobalData;

/** Bounded scalar/array wire values; use encode()/decode(), never unserialize network data directly. */
final class Codec
{
    public const DEFAULT_MAX_FRAME = 1048576;
    public const MAX_DEPTH = 64;
    public const MAX_NODES = 100000;

    /** Validate a total frame limit, including its four-byte header. */
    public static function frameLimit(mixed $limit): int
    {
        if (!is_int($limit) || $limit < 64 || $limit > 67108864) {
            throw new \InvalidArgumentException('GlobalData max_frame must be an integer from 64 to 67108864.');
        }
        return $limit;
    }

    /** Reject hooks/resources/recursive arrays before PHP serialization can invoke user code. */
    public static function validate(mixed $value): void
    {
        $nodes = 0;
        self::validateValue($value, 0, $nodes);
    }

    /** Encode an ordinary PHP value, retaining native serialization and binary-string semantics. */
    public static function encode(mixed $value, int $maxFrame): string
    {
        $nodes = 0;
        $strings = $maxFrame > 4 ? $maxFrame - 4 : 0;
        if (is_array($value)) {
            $nodes = 1;
            self::validateEncoding($value, 0, $nodes, $strings);
        } elseif (!is_null($value) && !is_scalar($value)) {
            throw new \UnexpectedValueException('GlobalData accepts only scalar, null and array values.');
        } elseif (is_string($value)) {
            $strings -= strlen($value);
        }
        if ($strings < 0) {
            // Expanded COW bytes may be huge; actual references may still fit the native wire format.
            $strings = $maxFrame > 4 ? $maxFrame - 4 : 0;
            $seen = [];
            $nodes = 0;
            $fits = self::stringBytesFit($value, 0, $nodes, $strings, $seen);
            unset($seen);
            if (!$fits) {
                throw new \UnexpectedValueException('GlobalData payload exceeds max_frame.');
            }
        }
        $payload = serialize($value);
        if (strlen($payload) > $maxFrame - 4) {
            throw new \UnexpectedValueException('GlobalData payload exceeds max_frame.');
        }
        return $payload;
    }

    /** Preflight the grammar before decoding: allowed_classes=false alone can still autoload enums. */
    public static function decode(string $payload, int $maxFrame): mixed
    {
        $length = strlen($payload);
        if ($length < 2 || $length > $maxFrame - 4) {
            throw new \UnexpectedValueException('Invalid GlobalData payload length.');
        }
        $offset = $nodes = 0;
        self::scan($payload, $offset, 0, $nodes);
        if ($offset !== $length) {
            throw new \UnexpectedValueException('Trailing GlobalData payload data.');
        }
        set_error_handler(static function (): never {
            throw new \UnexpectedValueException('Malformed GlobalData serialization.');
        });
        try {
            $value = unserialize($payload, ['allowed_classes' => false, 'max_depth' => self::MAX_DEPTH]);
        } catch (\Throwable $error) {
            throw new \UnexpectedValueException('Malformed GlobalData serialization.', 0, $error);
        } finally {
            restore_error_handler();
        }
        self::validate($value);
        return $value;
    }

    /** Validate native command envelopes before the unchanged upstream data engine receives them. */
    public static function request(mixed $data): void
    {
        if (!is_array($data) || !isset($data['cmd']) || !is_string($data['cmd'])
            || !array_key_exists('key', $data) || (!is_string($data['key']) && !is_int($data['key']))) {
            throw new \UnexpectedValueException('Invalid GlobalData command or key.');
        }
        $cmd = $data['cmd'];
        if (!in_array($cmd, ['get', 'set', 'add', 'increment', 'cas', 'delete'], true)) {
            throw new \UnexpectedValueException('Unknown GlobalData command.');
        }
        if (in_array($cmd, ['set', 'add', 'cas'], true) && !array_key_exists('value', $data)) {
            throw new \UnexpectedValueException('Missing GlobalData value.');
        }
        if ($cmd === 'increment' && (!isset($data['step']) || !is_numeric($data['step']) || !is_finite((float) $data['step']))) {
            throw new \UnexpectedValueException('GlobalData increment step must be finite and numeric.');
        }
        if ($cmd === 'cas' && (!isset($data['md5']) || !is_string($data['md5']) || !preg_match('/\A[0-9a-f]{32}\z/D', $data['md5']))) {
            throw new \UnexpectedValueException('Invalid GlobalData CAS token.');
        }
    }

    /** Bound traversal even for reference cycles and exponentially shared arrays. */
    private static function validateValue(mixed $value, int $depth, int &$nodes): void
    {
        if (++$nodes > self::MAX_NODES || $depth > self::MAX_DEPTH) {
            throw new \UnexpectedValueException('GlobalData value nesting or node limit exceeded.');
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (++$nodes > self::MAX_NODES) {
                    throw new \UnexpectedValueException('GlobalData value node limit exceeded.');
                }
                self::validateValue($item, $depth + 1, $nodes);
            }
        } elseif (!is_null($value) && !is_scalar($value)) {
            throw new \UnexpectedValueException('GlobalData accepts only scalar, null and array values.');
        }
    }

    /** Validate encode-only budgets; scalar leaves avoid another recursive call and decode retains its original path. */
    private static function validateEncoding(array $value, int $depth, int &$nodes, int &$strings): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \UnexpectedValueException('GlobalData value nesting or node limit exceeded.');
        }
        foreach ($value as $key => $item) {
            // Every entry contributes its key and value, including a nested array's root node.
            $nodes += 2;
            if ($nodes > self::MAX_NODES || $depth >= self::MAX_DEPTH) {
                throw new \UnexpectedValueException('GlobalData value nesting or node limit exceeded.');
            }
            if (is_string($key) && $strings >= 0) { $strings -= strlen($key); }
            if (is_array($item)) {
                self::validateEncoding($item, $depth + 1, $nodes, $strings);
            } elseif (is_string($item)) {
                if ($strings >= 0) { $strings -= strlen($item); }
            } elseif ($item !== null && !is_scalar($item)) {
                throw new \UnexpectedValueException('GlobalData accepts only scalar, null and array values.');
            }
        }
    }

    /** Recheck only oversized expanded graphs, charging actual PHP references once without serializing their values. */
    private static function stringBytesFit(mixed $value, int $depth, int &$nodes, int &$strings, array &$seen): bool
    {
        if (++$nodes > self::MAX_NODES || $depth > self::MAX_DEPTH) {
            throw new \UnexpectedValueException('GlobalData value nesting or node limit exceeded.');
        }
        if (is_string($value)) {
            $strings -= strlen($value);
            return $strings >= 0;
        }
        if (!is_array($value)) {
            if (!is_null($value) && !is_scalar($value)) {
                throw new \UnexpectedValueException('GlobalData accepts only scalar, null and array values.');
            }
            return true;
        }
        foreach ($value as $key => $item) {
            if (++$nodes > self::MAX_NODES) {
                throw new \UnexpectedValueException('GlobalData value node limit exceeded.');
            }
            if (is_string($key)) {
                $strings -= strlen($key);
                if ($strings < 0) { return false; }
            }
            $reference = \ReflectionReference::fromArrayElement($value, $key);
            if ($reference !== null) {
                $id = $reference->getId();
                if (isset($seen[$id])) { continue; }
                $seen[$id] = true;
            }
            if (!self::stringBytesFit($item, $depth + 1, $nodes, $strings, $seen)) { return false; }
        }
        return true;
    }

    /** Check token boundaries only; native PHP remains responsible for decoding values/references. */
    private static function scan(string $data, int &$offset, int $depth, int &$nodes, bool $key = false): void
    {
        if (++$nodes > self::MAX_NODES || $depth > self::MAX_DEPTH || $offset >= strlen($data)) {
            throw new \UnexpectedValueException('Invalid GlobalData serialization bounds.');
        }
        $type = $data[$offset++];
        if ($key && $type !== 'i' && $type !== 's') {
            throw new \UnexpectedValueException('Invalid GlobalData array key.');
        }
        if ($type === 'N') {
            self::expect($data, $offset, ';');
            return;
        }
        if (!in_array($type, ['b', 'i', 'd', 's', 'a', 'R'], true)) {
            throw new \UnexpectedValueException('Object and unknown GlobalData tokens are forbidden.');
        }
        self::expect($data, $offset, ':');
        if ($type === 's') {
            $length = self::unsigned($data, $offset);
            self::expect($data, $offset, ':"');
            if ($length > strlen($data) - $offset - 2) {
                throw new \UnexpectedValueException('Truncated GlobalData string.');
            }
            $offset += $length;
            self::expect($data, $offset, '";');
            return;
        }
        if ($type === 'a') {
            $count = self::unsigned($data, $offset);
            self::expect($data, $offset, ':{');
            if ($count > intdiv(strlen($data) - $offset, 6)) {
                throw new \UnexpectedValueException('Invalid GlobalData array count.');
            }
            for ($i = 0; $i < $count; ++$i) {
                self::scan($data, $offset, $depth + 1, $nodes, true);
                self::scan($data, $offset, $depth + 1, $nodes);
            }
            self::expect($data, $offset, '}');
            return;
        }
        $end = strpos($data, ';', $offset);
        if ($end === false || $end - $offset > 64) {
            throw new \UnexpectedValueException('Invalid GlobalData scalar token.');
        }
        $token = substr($data, $offset, $end - $offset);
        $valid = match ($type) {
            'b' => $token === '0' || $token === '1',
            'i' => preg_match('/\A-?[0-9]+\z/D', $token),
            'R' => preg_match('/\A[1-9][0-9]*\z/D', $token),
            'd' => preg_match('/\A(?:NAN|-?INF|-?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[Ee][+-]?[0-9]+)?)\z/D', $token),
        };
        if (!$valid) {
            throw new \UnexpectedValueException('Invalid GlobalData scalar token.');
        }
        $offset = $end + 1;
    }

    /** Read bounded lengths/counts without overflowing PHP integers. */
    private static function unsigned(string $data, int &$offset): int
    {
        $length = strspn($data, '0123456789', $offset);
        if ($length < 1 || $length > 9) {
            throw new \UnexpectedValueException('Invalid GlobalData length token.');
        }
        $number = (int) substr($data, $offset, $length);
        $offset += $length;
        return $number;
    }

    /** Consume a fixed delimiter without reading past the payload. */
    private static function expect(string $data, int &$offset, string $literal): void
    {
        if (substr_compare($data, $literal, $offset, strlen($literal)) !== 0) {
            throw new \UnexpectedValueException('Invalid GlobalData delimiter.');
        }
        $offset += strlen($literal);
    }
}
