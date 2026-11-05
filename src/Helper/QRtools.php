<?php

declare(strict_types=1);

namespace RC\Helper;

require_once __DIR__.'/QRcode.php';

/** Legacy business-facing matrix utilities; encoding remains in the pristine native barcode engine. */
class QRtools
{
    /** Convert the low bit of raw QR bytes to 01-string rows, e.g. binarize(QRcode::raw($text)). */
    public static function binarize($frame)
    {
        return Qrcode\LegacyImage::rows($frame, true);
    }

    /** Return the old num_rows/num_cols/bcode array; mode may be 'QR,H' or ['QR', 'H']. */
    public static function tcpdfBarcodeArray($code, $mode = 'QR,L', $tcPdfVersion = '4.5.037')
    {
        if (is_string($mode)) { $mode = explode(',', $mode); }
        if (!is_array($mode)) { throw new \InvalidArgumentException('QR mode must be a string or array.'); }
        $rows = QRcode::native($code, $mode[1] ?? 'L', 1, 0)->getGridArray();
        return ['num_rows' => count($rows), 'num_cols' => count($rows[0]),
            'bcode' => array_map(static fn(array $row): array => array_map('intval', $row), $rows)];
    }
}
