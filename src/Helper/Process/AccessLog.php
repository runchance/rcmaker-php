<?php

declare(strict_types=1);

namespace RC\Helper\Process;

/** Internal access-log schema and formatting shared by the asynchronous sender and logger process. */
final class AccessLog
{
    public const MAX_PACKET_BYTES = 32768;
    public const MAX_SEND_BUFFER_BYTES = 65536;
    private const FIELD_LIMITS = ['path' => 4096, 'ip' => 64, 'protocol' => 32, 'referer' => 2048, 'method' => 32, 'agent' => 2048];

    /** Keep the existing wire fields, bound strings and reject structured values instead of implicit conversions. */
    public static function normalize(array $record): array
    {
        if (!isset($record['app']) || !is_string($record['app']) || $record['app'] === '' || strlen($record['app']) > 255) {
            throw new \InvalidArgumentException('Invalid access-log application.');
        }
        $result = ['app' => $record['app']];
        foreach (self::FIELD_LIMITS as $field => $limit) {
            $value = $record[$field] ?? (in_array($field, ['referer', 'agent'], true) ? '' : null);
            if (!is_string($value)) { throw new \InvalidArgumentException('Invalid access-log field.'); }
            $result[$field] = strlen($value) <= $limit ? $value : substr($value, 0, $limit - 14) . '...[truncated]';
        }
        foreach (['time' => [0, 253402300799], 'status' => [100, 599]] as $field => [$minimum, $maximum]) {
            $value = $record[$field] ?? null;
            if ((!is_int($value) && !is_string($value)) || filter_var($value, FILTER_VALIDATE_INT) === false
                || (int) $value < $minimum || (int) $value > $maximum) {
                throw new \InvalidArgumentException('Invalid access-log numeric field.');
            }
            $result[$field] = (int) $value;
        }
        return $result;
    }

    /** Encode one bounded Text frame; invalid UTF-8 is replaced only in this diagnostic copy. */
    public static function packet(array $record): string
    {
        $packet = json_encode(['type' => 'log', 'data' => self::normalize($record)],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        if (strlen($packet) >= self::MAX_PACKET_BYTES) { throw new \LengthException('Access-log packet is too large.'); }
        return $packet;
    }

    /** Build a single safe filename from a normalized label and the record's day in the logger timezone. */
    public static function fileName(array $record): string
    {
        $application = rawurlencode($record['app']);
        if (strlen($application) > 160) { $application = substr($application, 0, 120) . '-' . substr(hash('sha256', $record['app']), 0, 24); }
        return 'rcmaker_access_[' . $application . ']_' . date('Y-m-d', $record['time']) . '.log';
    }

    /** Preserve the combined-log shape while preventing a field from creating another line or quoted column. */
    public static function line(array $record, string $frame = 'workerman'): string
    {
        return date('Y-m-d H:i:s', $record['time']) . (IS_CLI ? '[CLI][' . $frame . ']' : '[fpm]') . ' - ['
            . self::escape($record['ip']) . '] - "' . self::escape($record['method']) . ' ' . self::escape($record['path']) . ' '
            . self::escape($record['protocol']) . '" - ' . $record['status'] . ' "' . self::escape($record['referer']) . '" "'
            . self::escape($record['agent']) . '"' . "\n";
    }

    /** Escape in one pass so inserted backslashes are not escaped again. */
    private static function escape(string $value): string
    {
        static $map;
        if ($map === null) {
            $map = ['\\' => '\\\\', '"' => '\\"', "\x7f" => '\\x7f'];
            for ($byte = 0; $byte < 32; ++$byte) { $map[chr($byte)] = sprintf('\\x%02x', $byte); }
        }
        return strtr($value, $map);
    }
}
