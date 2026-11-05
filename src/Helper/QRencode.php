<?php

declare(strict_types=1);

namespace RC\Helper;

require_once __DIR__ . '/QRcode.php';

/** Legacy configurable encoder; factory(ECC, pixels, margin), then encode()/encodeRAW()/encodePNG(). */
class QRencode extends QRcode
{
    public $casesensitive = true;
    public $eightbit = false;
    public $version = 0;
    public $size = 3;
    public $margin = 4;
    public $structured = 0;
    public $level = QR_ECLEVEL_L;
    public $hint = QR_MODE_8;

    /** Create independent controls; named ECC letters are normalized to legacy numeric values. */
    public static function factory($level = QR_ECLEVEL_L, $size = 3, $margin = 4)
    {
        $encoder = new self();
        $encoder->level = array_search(self::level($level), ['L', 'M', 'Q', 'H'], true);
        $encoder->size = $size;
        $encoder->margin = $margin;
        return $encoder;
    }

    /** Return byte-0/1 rows, retaining ignored outfile and pixel controls for the old raw API. */
    public function encodeRAW($intext, $outfile = false)
    {
        return array_map(static fn(array $row): string => implode('', $row), $this->symbol($intext)->getGridArray("\0", "\1"));
    }

    /** Return 01-string rows, or atomically save newline-delimited rows and return null. */
    public function encode($intext, $outfile = false)
    {
        $target = self::target($outfile);
        $rows = array_map(static fn(array $row): string => implode('', $row), $this->symbol($intext)->getGridArray());
        if ($target !== false) { self::save($target, implode("\n", $rows)); return null; }
        return $rows;
    }

    /** Print PNG, save silently, or save-and-print; use the shared bounded rasterizer and atomic writer. */
    public function encodePNG($intext, $outfile = false, $saveandprint = false)
    {
        $target = self::target($outfile);
        $size = self::integer($this->size, 1, PHP_INT_MAX, 'size');
        $margin = self::integer($this->margin, 0, 512, 'margin');
        $symbol = self::sizeModel($this->symbol($intext), $size, $margin);
        echo self::outputModel($symbol, 'png', $target, (bool) $saveandprint);
    }

    /** Encode only once for each operation; byte mode never silently falls back to auto compaction. */
    private function symbol(mixed $text): Qrcode\LegacySymbol
    {
        if ($this->eightbit) {
            $text = self::content($text);
            return self::legacySymbol($text, $this->version, $this->level, 2, true, [[2, 0, strlen($text)]]);
        }
        return self::legacySymbol($text, $this->version, $this->level, $this->hint, (bool) $this->casesensitive);
    }
}
