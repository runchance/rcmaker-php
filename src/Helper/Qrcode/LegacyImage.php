<?php

declare(strict_types=1);

namespace RC\Helper\Qrcode;

use Com\Tecnick\Barcode\Type\Square\Raw;

require_once dirname(__DIR__).'/QRcode.php';

/** @internal Adapt old image calls to native SRAW rendering and the existing QR publication rules. */
final class LegacyImage extends \RC\Helper\QRcode
{
    private const MAX_SIDE = 1024;

    /** Validate bounded rectangular rows; raw mode translates low bits without mutating caller data. */
    public static function rows(mixed $frame, bool $raw = false): array
    {
        if (!is_array($frame) || !array_is_list($frame) || $frame === [] || count($frame) > self::MAX_SIDE
            || !is_string($frame[0]) || ($width = strlen($frame[0])) < 1 || $width > self::MAX_SIDE) {
            throw new \InvalidArgumentException('QR frame must contain bounded, non-empty string rows.');
        }
        $rows = [];
        foreach ($frame as $row) {
            if (!is_string($row) || strlen($row) !== $width || (!$raw && strspn($row, '01') !== $width)) {
                throw new \InvalidArgumentException('QR frame rows must have equal width and contain only 0/1 modules.');
            }
            if ($raw) {
                for ($i = 0; $i < $width; ++$i) { $row[$i] = (ord($row[$i]) & 1) ? '1' : '0'; }
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /** Encode an existing grid once, then publish completely or write to a caller-owned stream. */
    public static function outputGrid(mixed $frame, mixed $filename, mixed $pixels, mixed $margin,
        string $format, mixed $quality, mixed $print): void
    {
        $rows = self::rows($frame);
        $pixels = self::integer($pixels, 1, PHP_INT_MAX, 'pixelPerPoint');
        $margin = self::integer($margin, 0, intdiv(self::MAX_SIDE, 2), 'outerFrame');
        if ($pixels > intdiv(self::MAX_SIDE, max(count($rows), strlen($rows[0])) + 2 * $margin)) {
            throw new \InvalidArgumentException('QR image dimensions exceed 1024 pixels.');
        }
        $quality = self::integer($quality, -1, 100, 'JPEG quality');
        if (!extension_loaded('gd') || !function_exists($format === 'png' ? 'imagepng' : 'imagejpeg')) {
            throw new \RuntimeException('QR '.$format.' output requires matching GD image support.');
        }
        $stream = is_resource($filename) && get_resource_type($filename) === 'stream' ? $filename : null;
        $target = $stream === null ? self::target($filename) : false;
        $symbol = new Raw(implode(',', $rows), -$pixels, -$pixels, 'black', [], array_fill(0, 4, -$margin));
        $symbol->setBackgroundColor('white');
        $image = $symbol->getGd();
        $level = ob_get_level();
        try {
            ob_start();
            $encoded = $format === 'png' ? imagepng($image) : imagejpeg($image, null, $quality);
            $bytes = ob_get_clean();
            if (!$encoded || !is_string($bytes) || $bytes === '') {
                throw new \RuntimeException('Unable to encode the QR image.');
            }
        } finally {
            while (ob_get_level() > $level) { ob_end_clean(); }
        }
        if ($stream !== null) {
            for ($offset = 0, $length = strlen($bytes); $offset < $length; $offset += $written) {
                $written = fwrite($stream, substr($bytes, $offset));
                if ($written === false || $written === 0) { throw new \RuntimeException('Unable to write QR image stream.'); }
            }
        } elseif ($target !== false) {
            self::save($target, $bytes);
        }
        if (($stream === null && $target === false) || $print === true) { echo $bytes; }
    }
}
