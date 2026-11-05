<?php

declare(strict_types=1);

namespace RC\Helper\Qrcode;

use Com\Tecnick\Barcode\Exception as BarcodeException;
use Com\Tecnick\Barcode\Type\Square\QrCode\Data;
use Com\Tecnick\Barcode\Type\Square\QrCode\Encode;

/** @internal Adapt minimum-version and explicit-segment policy; all actual QR encoding stays upstream. */
final class LegacyEncode extends Encode
{
    /** Initialize optional validated segments before the native constructor selects a version. */
    public function __construct(string $code, int $level, int $version, bool $kanji, ?array $segments,
        int $randomMask, bool $bestMask, int $defaultMask)
    {
        $this->segments = $segments;
        parent::__construct($code, $level, $version, $kanji, $randomMask, $bestMask, $defaultMask);
    }

    private ?array $segments;

    /** Preserve caller-chosen modes while delegating character-count splitting to the native encoder. */
    protected function getSegments(string $code, int $group, bool $kanji): array
    {
        return $this->segments === null ? parent::getSegments($code, $group, $kanji)
            : $this->splitLongSegments($this->segments, $group);
    }

    /** Search from the old minimum version using upstream segmentation, bit counts and capacity tables. */
    protected function setVersion(string $code, int $version, bool $kanji): array
    {
        if (strlen($code) > self::MAX_CHARACTERS) {
            throw new BarcodeException('The data does not fit in a QR Code symbol');
        }
        $cache = [];
        for ($candidate = max(Data::VERSION_MIN, $version); $candidate <= Data::VERSION_MAX; ++$candidate) {
            $group = $this->getVersionGroup($candidate);
            $cache[$group] ??= $this->getSegments($code, $group, $kanji);
            if ($this->getStreamBits($cache[$group], $group) <= 8 * self::getDataCodewords($candidate, $this->level)) {
                $this->version = $candidate;
                return $cache[$group];
            }
        }
        throw new BarcodeException('The data does not fit in a QR Code symbol');
    }
}
