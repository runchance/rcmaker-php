<?php

namespace RC\Helper\Pinyin;

/** Only immutable first readings are retained for legacy APIs; full native dictionaries remain available on demand. */
final class FirstReadings
{
    private static ?array $dictionary = null;
    private const MAX_PLAIN_READINGS = 4096;
    private static array $plain = [];

    /** Build a small input-specific map and format each distinct Han character only once. */
    public static function map(array $characters, bool $withoutTone): array
    {
        self::$dictionary ??= require __DIR__ . '/first-readings.php';
        $formatter = null;
        $map = [];
        foreach ($characters as $character) {
            if (!isset($map[$character]) && isset(self::$dictionary[$character])) {
                $reading = self::$dictionary[$character];
                if (!$withoutTone) { $map[$character] = $reading; continue; }
                if (isset(self::$plain[$character])) { $map[$character] = self::$plain[$character]; continue; }
                $formatter ??= new CachedConverter();
                $map[$character] = $formatter->formatReading($reading, 'none');
                if (count(self::$plain) < self::MAX_PLAIN_READINGS) { self::$plain[$character] = $map[$character]; }
            }
        }
        return $map;
    }

    /** Release only derived immutable dictionaries/readings; no caller input strings are cached. */
    public static function clear(): void
    {
        self::$dictionary = null;
        self::$plain = [];
    }
}
