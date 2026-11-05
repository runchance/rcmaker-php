<?php

declare(strict_types=1);

namespace RC\Helper;

require_once __DIR__ . '/QRcode.php';

/** One legacy segment descriptor; pass str_split($text) and its byte count, not a custom bitstream. */
class QRinputItem
{
    public $mode;
    public $size;
    public $data;
    public $bstream;

    /** Validate and retain the requested prefix; native encoding owns the actual bitstream. */
    public function __construct($mode, $size, $data, $bstream = null)
    {
        if (!QRinput::check($mode, $size, $data) || $bstream !== null) {
            throw new \InvalidArgumentException('Invalid QR segment; custom legacy bitstreams are not supported.');
        }
        $this->mode = (int) $mode;
        $this->size = (int) $size;
        $this->data = array_slice($data, 0, $this->size);
        $this->bstream = null;
    }
}
