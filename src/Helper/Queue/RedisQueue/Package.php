<?php

declare(strict_types=1);

namespace RC\Helper\Queue\RedisQueue;

/** Existing wire format with strict validation; payload false/0/null remain valid application values. */
final class Package
{
    /** Create a cross-process unique delayed-set member, normalizing the callable-delay overload first. */
    public static function create(string $queue, mixed $data, int $delay): array
    {
        self::queue($queue);
        return ['id' => bin2hex(random_bytes(16)), 'time' => time(), 'delay' => $delay,
            'attempts' => 0, 'queue' => $queue, 'data' => $data];
    }

    /** Decode only complete queue envelopes, not arbitrary truthy JSON arrays. */
    public static function decode(string $json): array
    {
        $package = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($package) || !isset($package['id'], $package['time'], $package['delay'], $package['attempts'], $package['queue'])
            || !array_key_exists('data', $package)
            || !is_string($package['id']) && !is_int($package['id'])
            || (string) $package['id'] === '' || !is_string($package['queue'])) {
            throw new \UnexpectedValueException('Invalid queue message envelope.');
        }
        self::queue($package['queue']);
        foreach (['time', 'delay', 'attempts'] as $field) {
            if (!is_int($package[$field]) || $package[$field] < 0 || $package[$field] === PHP_INT_MAX) {
                throw new \UnexpectedValueException('Invalid queue message ' . $field . '.');
            }
        }
        return $package;
    }

    /** Encode without silently replacing application data or storing false after a JSON failure. */
    public static function encode(array $package): string
    {
        return json_encode($package, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** Queue names are Redis-key suffixes and JSON strings, not filesystem paths. */
    public static function queue(string $queue): void
    {
        if ($queue === '' || str_contains($queue, "\0")) {
            throw new \InvalidArgumentException('Queue name must be nonempty and contain no NUL.');
        }
    }
}
