<?php

declare(strict_types=1);

namespace RC\Helper;

use Com\Tecnick\Barcode\Type\Square\QrCode\Data;

require_once __DIR__ . '/QRcode.php';

/** Legacy input container: append(NUM/AN/8/KANJI, byte length, str_split($text)), then QRcode::encodeInput(). */
class QRinput
{
    public $items = [];
    private int $version;
    private int $level;

    /** Create a segment list with minimum version 0..40 and numeric ECC 0..3. */
    public function __construct($version = 0, $level = QR_ECLEVEL_L)
    {
        $this->setVersion($version);
        $this->setErrorCorrectionLevel($level);
    }

    /** Return the requested minimum, or selected version after successful encoding. */
    public function getVersion()
    {
        return $this->version;
    }

    /** Set a valid minimum version; zero selects the smallest fitting symbol. */
    public function setVersion($version)
    {
        $this->version = self::number($version, 0, 40);
        return 0;
    }

    /** Return the numeric L/M/Q/H error-correction level. */
    public function getErrorCorrectionLevel()
    {
        return $this->level;
    }

    /** Set numeric ECC 0..3 without silently accepting an invalid level. */
    public function setErrorCorrectionLevel($level)
    {
        $this->level = self::number($level, 0, 3);
        return 0;
    }

    /** Append an existing entry; mutations are revalidated at encoding time. */
    public function appendEntry(QRinputItem $entry)
    {
        $this->items[] = $entry;
    }

    /** Append one byte-array entry, returning 0 on success or -1 for invalid data. */
    public function append($mode, $size, $data)
    {
        try { $entry = new QRinputItem($mode, $size, $data); }
        catch (\InvalidArgumentException) { return -1; }
        $this->items[] = $entry;
        return 0;
    }

    /** Validate the four supported segment modes; structured append and old bitstream objects are not emulated. */
    public static function check($mode, $size, $data)
    {
        if ((!is_int($mode) && !is_string($mode)) || !in_array((string) $mode, ['0', '1', '2', '3'], true)
            || (!is_int($size) && !is_string($size)) || !ctype_digit((string) $size)
            || (int) $size < 1 || (int) $size > 7089 || !is_array($data) || count($data) < (int) $size) {
            return false;
        }
        $bytes = array_slice($data, 0, (int) $size);
        foreach ($bytes as $byte) { if (!is_string($byte) || strlen($byte) !== 1) { return false; } }
        $text = implode('', $bytes);
        return match ((int) $mode) {
            0 => strspn($text, '0123456789') === strlen($text),
            1 => strspn($text, Data::AN_CHARS) === strlen($text),
            2 => true,
            3 => self::kanji($text),
        };
    }

    /** @internal Translate validated public entries into native segment descriptors; no bitstream encoding here. */
    public function rcmakerPayload(): array
    {
        if (!is_array($this->items) || $this->items === []) {
            throw new \InvalidArgumentException('QR input must contain at least one valid segment.');
        }
        $text = ''; $segments = [];
        foreach ($this->items as $entry) {
            if (!$entry instanceof QRinputItem || !self::check($entry->mode, $entry->size, $entry->data)) {
                throw new \InvalidArgumentException('Invalid QR input entry.');
            }
            $mode = (int) $entry->mode;
            $size = (int) $entry->size;
            $segments[] = [$mode, strlen($text), $mode === 3 ? intdiv($size, 2) : $size];
            $text .= implode('', array_slice($entry->data, 0, $size));
            if (strlen($text) > 7089) { throw new \InvalidArgumentException('QR input exceeds maximum capacity.'); }
        }
        return [$text, $segments];
    }

    /** Accept decimal configuration integers while rejecting booleans, fractions and overflow. */
    private static function number(mixed $value, int $minimum, int $maximum): int
    {
        if ((!is_int($value) && !is_string($value)) || ($number = filter_var($value, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => $minimum, 'max_range' => $maximum]])) === false) {
            throw new \InvalidArgumentException('Invalid QR input version or error-correction level.');
        }
        return $number;
    }

    /** Validate byte pairs against the same Shift-JIS ranges used by the native encoder. */
    private static function kanji(string $text): bool
    {
        if (strlen($text) % 2 !== 0) { return false; }
        for ($offset = 0, $length = strlen($text); $offset < $length; $offset += 2) {
            $pair = (ord($text[$offset]) << 8) | ord($text[$offset + 1]);
            if (!(($pair >= Data::KANJI_RANGES[0][0] && $pair <= Data::KANJI_RANGES[0][1])
                || ($pair >= Data::KANJI_RANGES[1][0] && $pair <= Data::KANJI_RANGES[1][1]))) { return false; }
        }
        return true;
    }
}
