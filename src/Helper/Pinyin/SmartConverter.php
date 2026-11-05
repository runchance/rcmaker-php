<?php

namespace RC\Helper\Pinyin;

/** Unchanged native smart conversion with RC's additional syllabic-tone formatting. */
class SmartConverter extends \Overtrue\Pinyin\Converters\SmartConverter
{
    use Tone;
}
