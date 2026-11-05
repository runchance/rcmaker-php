<?php

namespace RC\Helper\Pinyin;

/** Complete syllabic n/m and e-circumflex tones absent from the native 6.0.1 formatter; retain its ordinary algorithm. */
trait Tone
{
    private const OUTSIDE_TONES = '/m[\x{0300}\x{0304}]|ê[\x{0300}\x{0301}\x{0304}\x{030C}]|[ɑüāáǎàōóǒòēéěèīíǐìūúǔùǖǘǚǜńňǹḿêếề]/u';

    // Two malformed 6.0.1 word readings; use the same release's 九/燵 character readings.
    private const READING_CORRECTIONS = ['jiǔì' => 'jiǔ', 'tātuī' => 'dá'];

    /** Native mb helpers assume UTF-8; reject invalid input/configuration instead of corrupting a conversion. */
    public function convert(string $string): \Overtrue\Pinyin\Collection
    {
        if (!mb_check_encoding($string, 'UTF-8') || strcasecmp(mb_internal_encoding(), 'UTF-8') !== 0) {
            throw new \InvalidArgumentException('Pinyin converters require valid UTF-8 text and mb_internal_encoding=UTF-8.');
        }
        $outside = [];
        if (!$this->cleanup && !$this->noWords && !$this->heteronym
            && $this->toneStyle !== \Overtrue\Pinyin\ToneStyle::SYMBOL && preg_match(self::OUTSIDE_TONES, $string)) {
            // Keep original Latin accents distinct from generated pinyin before native tone formatting.
            $prefix = 'RCPY' . bin2hex(random_bytes(16));
            if (str_contains($string, $prefix)) { throw new \RuntimeException('Pinyin temporary token collision.'); }
            $string = preg_replace_callback(self::OUTSIDE_TONES, static function (array $match) use ($prefix, &$outside): string {
                $key = $prefix . count($outside) . 'Z'; $outside[$key] = $match[0]; return $key;
            }, $string);
        }
        $result = parent::convert($string);
        return $outside === [] ? $result : $result->map(static fn(string $item): string => strtr($item, $outside));
    }

    /** Preserve native cleanup options while extending Han detection to supplementary Unicode planes. */
    protected function preprocessString(string $string): string
    {
        if (!str_contains($this->regexps['hans'], '\\p{Han}')) { $this->regexps['hans'] = '\\p{Han}' . $this->regexps['hans']; }
        return parent::preprocessString($string);
    }

    /** Apply the native formatter first; additional valid dictionary syllables keep none/number styles ASCII. */
    protected function formatTone(string $pinyin, string $style): string
    {
        $pinyin = self::READING_CORRECTIONS[$pinyin] ?? $pinyin;
        if ($style !== 'symbol' && str_contains($pinyin, 'ü')) {
            // The accent belongs to e: neutral ü must not append a second tone number.
            $pinyin = strtr($pinyin, [
                'üē' => $this->yuTo . 'ē', 'üé' => $this->yuTo . 'é',
                'üě' => $this->yuTo . 'ě', 'üè' => $this->yuTo . 'è',
            ]);
        }
        $result = parent::formatTone($pinyin, $style);
        if ($style === 'symbol' || !preg_match('/[^\x00-\x7f]/', $result)) { return $result; }
        foreach (["ê\u{0304}" => ['e', 1], 'ế' => ['e', 2], "ê\u{030C}" => ['e', 3], 'ề' => ['e', 4],
            'ń' => ['n', 2], 'ň' => ['n', 3], 'ǹ' => ['n', 4], "m\u{0304}" => ['m', 1], 'ḿ' => ['m', 2], "m\u{0300}" => ['m', 4], 'ê' => ['e', 5]] as $accent => [$letter, $tone]) {
            if (str_contains($result, $accent)) {
                return str_replace($accent, $letter, $result) . ($style === 'number' ? $tone : '');
            }
        }
        return $result;
    }
}
