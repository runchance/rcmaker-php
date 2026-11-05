<?php

namespace RC\Helper\Pinyin;

/** Unchanged native cached conversion with RC's additional syllabic-tone formatting. */
class CachedConverter extends \Overtrue\Pinyin\Converters\CachedConverter
{
    use Tone;

    /** Format one indexed syllable without loading the native heteronym dictionary. */
    public function formatReading(string $reading, string $style): string
    {
        if (!in_array($style, ['symbol', 'none', 'number'], true)) { throw new \InvalidArgumentException('Unknown pinyin tone style.'); }
        return $this->formatTone($reading, $style);
    }
}
