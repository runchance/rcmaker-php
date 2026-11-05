<?php

declare(strict_types=1);

namespace RC\Helper\Qrcode;

use Com\Tecnick\Barcode\Type\Square\QrCode;

require_once __DIR__ . '/LegacyEncode.php';

/** @internal Native model with only legacy input/version policy replaced; pixels and bars remain native data. */
final class LegacySymbol extends QrCode
{
    private ?array $segments;

    /** Consume validated legacy controls without adding them to the normal public native() options. */
    public function __construct(string $code, string $level, int $version, bool $kanji, bool $caseSensitive,
        ?array $segments, int $mask)
    {
        $this->segments = $segments;
        $best = $mask < 0 && (!defined('QR_FIND_BEST_MASK') || (bool) QR_FIND_BEST_MASK);
        $default = $mask >= 0 ? $mask : (defined('QR_DEFAULT_MASK') ? (int) QR_DEFAULT_MASK % 8 : 2);
        $random = defined('QR_FIND_FROM_RANDOM') ? (int) QR_FIND_FROM_RANDOM : 2;
        parent::__construct($code, -1, -1, 'black', [$level, $kanji ? 'KJ' : '8B', $version,
            (int) $caseSensitive, $random, (int) $best, $default]);
    }

    /** Keep upstream Kanji-aware folding, encoding, matrix placement, masking and bar conversion. */
    protected function setBars(): void
    {
        $code = $this->case_sensitive ? $this->getEncodedPayload() : $this->toUpper($this->getEncodedPayload());
        $encode = new LegacyEncode($code, $this->level, $this->version, $this->kanji, $this->segments,
            $this->random_mask, $this->best_mask, $this->default_mask);
        $this->version = $encode->getVersion();
        $this->processBinarySequence($encode->getGrid());
    }
}
