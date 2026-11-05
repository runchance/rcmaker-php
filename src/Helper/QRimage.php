<?php

declare(strict_types=1);

namespace RC\Helper;

require_once __DIR__.'/Qrcode/LegacyImage.php';

/** Legacy matrix-to-image entry; QRimage::png(['101', '010', '101']) prints without re-encoding the matrix. */
class QRimage
{
    /** Print PNG, save to a local file/stream, or save and print when saveandprint is exactly true. */
    public static function png($frame, $filename = false, $pixelPerPoint = 4, $outerFrame = 4, $saveandprint = false)
    {
        Qrcode\LegacyImage::outputGrid($frame, $filename, $pixelPerPoint, $outerFrame, 'png', -1, $saveandprint);
    }

    /** Print or save JPEG at quality -1..100; the actual target PHP must provide GD JPEG support. */
    public static function jpg($frame, $filename = false, $pixelPerPoint = 8, $outerFrame = 4, $q = 85)
    {
        Qrcode\LegacyImage::outputGrid($frame, $filename, $pixelPerPoint, $outerFrame, 'jpeg', $q, false);
    }
}
