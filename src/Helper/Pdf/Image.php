<?php

declare(strict_types=1);

namespace RC\Helper\Pdf;

use Com\Tecnick\Pdf\Encrypt\Encrypt;

/** Preserve loaded images, file policy and the upstream cache adapter across configuration changes. */
final class Image extends \Com\Tecnick\Pdf\Image\Import
{
    /** Document::imagePixelSize() primes native import once; Image::add() reuses the same key without another GET. */
    public function originalDimensions(string $image, int $quality): array
    {
        $data = $this->import($image, quality: $quality);
        return [$data['width'], $data['height']];
    }

    /** Called by Engine before pages exist; streams are encrypted by upstream during final output. */
    public function configure(float $ratio, Encrypt $encrypt): void
    {
        $this->kunit = $ratio;
        $this->encrypt = $encrypt;
    }

    /** Engine-only switch for optional palette/ICC streams, not the image format's own encoding. */
    public function setStreamCompression(bool $compress): void
    {
        $this->compress = $compress;
    }
}
