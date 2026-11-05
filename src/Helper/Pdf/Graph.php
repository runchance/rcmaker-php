<?php

declare(strict_types=1);

namespace RC\Helper\Pdf;

use Com\Tecnick\Pdf\Encrypt\Encrypt;

/** Retain graphic styles and resources while rebinding document encryption. */
final class Graph extends \Com\Tecnick\Pdf\Graph\Draw
{
    /** Called by Engine before pages exist; upstream retains ownership of all drawing operations. */
    public function configure(float $ratio, Encrypt $encrypt): void
    {
        $this->setKUnit($ratio);
        $this->encrypt = $encrypt;
    }

    /** Engine-only switch; retain already registered gradients and transparency masks. */
    public function setStreamCompression(bool $compress): void
    {
        $this->compress = $compress;
    }
}
