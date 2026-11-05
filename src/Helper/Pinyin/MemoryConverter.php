<?php

namespace RC\Helper\Pinyin;

/** Unchanged native segment-at-a-time conversion with RC's additional syllabic-tone formatting. */
class MemoryConverter extends \Overtrue\Pinyin\Converters\MemoryOptimizedConverter
{
    use Tone;
}
