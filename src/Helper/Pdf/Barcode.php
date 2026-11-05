<?php

declare(strict_types=1);

namespace RC\Helper\Pdf;

use Com\Tecnick\Barcode\Barcode as NativeBarcode;
use Com\Tecnick\Barcode\BarcodeType;
use Com\Tecnick\Barcode\Exception as BarcodeException;
use Com\Tecnick\Barcode\Model;
use RuntimeException;

/** Installed by Engine so the TCPDF facade cannot silently omit an invalid barcode. */
final class Barcode extends NativeBarcode
{
    /** Delegate encoding unchanged; expose errors with their native cause instead of a missing barcode. */
    public function getBarcodeObj(
        string|BarcodeType $type,
        string $code,
        int $width = -1,
        int $height = -1,
        string $color = 'black',
        array $padding = [0, 0, 0, 0],
    ): Model {
        try {
            return parent::getBarcodeObj($type, $code, $width, $height, $color, $padding);
        } catch (BarcodeException $error) {
            throw new RuntimeException('TCPDF: unable to generate barcode.', 0, $error);
        }
    }
}
