<?php

namespace RC\Helper;

use InvalidArgumentException;
use Overtrue\Pinyin\Contracts\ConverterInterface;
use RC\Helper\Pinyin\Runtime;
use RC\Helper\Pinyin\FirstReadings;

require_once __DIR__ . '/Pinyin/bootstrap.php';

/** Legacy RC string APIs plus fresh upstream converters; dictionaries are immutable process caches. */
class PinYin
{
    private string $charset = 'UTF-8';
    private bool $utf8 = true;
    private static ?array $textEncodings = null;

    /** Input may use a supported mbstring encoding; all returned text is UTF-8. */
    public function __construct($charset = 'utf-8')
    {
        if (!extension_loaded('mbstring')) { throw new \RuntimeException('Pinyin requires the mbstring PHP extension.'); }
        if (!is_string($charset) || $charset === '' || strlen($charset) > 128) { throw new InvalidArgumentException('Pinyin charset must name a supported text encoding.'); }
        $key = strtolower($charset);
        self::$textEncodings ??= self::textEncodings();
        if (!isset(self::$textEncodings[$key])) { throw new InvalidArgumentException('Unsupported pinyin text encoding.'); }
        // A MIME name can lose CP932/eucJP-win distinctions; mbstring must receive the original name.
        $this->charset = $charset;
        $this->utf8 = $key === 'utf-8' || $key === 'utf8';
    }

    /** Retain per-character readings and the trailing separator: TransformWithTone('你好') returns 'nǐ hǎo '. */
    public function TransformWithTone($input_char, $delimiter = ' ', $outside_ignore = false)
    {
        return $this->transform($input_char, $delimiter, $outside_ignore, false);
    }

    /** Convert Han only; original foreign text/delimiters are not stripped of their accents. */
    public function TransformWithoutTone($input_char, $delimiter = '', $outside_ignore = true)
    {
        return $this->transform($input_char, $delimiter, $outside_ignore, true);
    }

    /** Uppercase one initial for each converted Han character; '0' is a valid separator. */
    public function TransformUcwords($input_char, $delimiter = '')
    {
        if (!is_string($delimiter)) { throw new InvalidArgumentException('Pinyin delimiter must be a string.'); }
        $text = $this->text($input_char);
        if ($text === '' || !preg_match('/\p{Han}/u', $text)) { return ''; }
        $characters = mb_str_split($text, 1, 'UTF-8');
        $map = FirstReadings::map($characters, true);
        $initials = [];
        foreach ($characters as $character) {
            if (isset($map[$character])) { $initials[] = mb_strtoupper(mb_substr($map[$character], 0, 1, 'UTF-8'), 'UTF-8'); }
        }
        return implode($delimiter, $initials);
    }

    /** Fresh options per call; converter()->surname()->withToneStyle('number')->convert('单田芳'). */
    public function converter(string $strategy = 'cached'): ConverterInterface
    {
        return Runtime::converter($strategy);
    }

    /** Optional end-of-batch release; do not discard shared dictionaries on every HTTP request. */
    public function clearCache(): void
    {
        if (class_exists(FirstReadings::class, false)) { FirstReadings::clear(); }
        foreach ([\Overtrue\Pinyin\Converters\CachedConverter::class, \Overtrue\Pinyin\Converters\SmartConverter::class] as $class) {
            if (class_exists($class, false)) { $class::clearCache(); }
        }
    }

    /** Native-derived first readings with original order/repeats and legacy separators. */
    private function transform(mixed $input, mixed $delimiter, mixed $ignore, bool $withoutTone): string
    {
        if (!is_string($delimiter) || !is_bool($ignore)) {
            throw new InvalidArgumentException('Pinyin delimiter must be a string and outside_ignore must be a boolean.');
        }
        $text = $this->text($input);
        if ($text === '' || !preg_match('/\p{Han}/u', $text)) { return $ignore ? '' : $text; }
        $characters = mb_str_split($text, 1, 'UTF-8');
        $map = FirstReadings::map($characters, $withoutTone);
        $output = '';
        foreach ($characters as $character) {
            if (isset($map[$character])) { $output .= $map[$character] . $delimiter; }
            elseif (!$ignore) { $output .= $character; }
        }
        return $output;
    }

    /** Validate before mb conversion so malformed byte sequences are never silently replaced. */
    private function text(mixed $input): string
    {
        if (!is_string($input) || !mb_check_encoding($input, $this->charset)) {
            throw new InvalidArgumentException('Pinyin input must be valid text in the declared encoding.');
        }
        return $this->utf8 ? $input : mb_convert_encoding($input, 'UTF-8', $this->charset);
    }

    /** One bounded metadata map, not a cache of caller-provided names; transfer formats are not charsets. */
    private static function textEncodings(): array
    {
        $allowed = [];
        foreach (mb_list_encodings() as $encoding) {
            $transfer = in_array(strtolower($encoding), ['base64', 'uuencode', 'html-entities', 'quoted-printable', '7bit', '8bit'], true);
            if ($transfer) { continue; }
            foreach ([$encoding, ...mb_encoding_aliases($encoding)] as $name) {
                $allowed[strtolower($name)] = true;
            }
        }
        return $allowed;
    }
}
