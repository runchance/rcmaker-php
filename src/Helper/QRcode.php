<?php

declare(strict_types=1);

namespace RC\Helper;

use Com\Tecnick\Barcode\Model;
use Com\Tecnick\Barcode\Type\Square\QrCode as NativeQrCode;

require_once __DIR__ . '/TecnickAutoload.php';

// A local scope avoids overwriting variables belonging to direct-require callers.
(static function (): void {
    foreach (['QR_ECLEVEL_L' => 0, 'QR_ECLEVEL_M' => 1, 'QR_ECLEVEL_Q' => 2, 'QR_ECLEVEL_H' => 3,
        'QR_MODE_NUL' => -1, 'QR_MODE_NUM' => 0, 'QR_MODE_AN' => 1, 'QR_MODE_8' => 2,
        'QR_MODE_KANJI' => 3, 'QR_MODE_STRUCTURE' => 4, 'QRSPEC_VERSION_MAX' => 40] as $name => $value) {
        if (!defined($name)) {
            define($name, $value);
        }
    }
})();

/** QR facade over pristine tc-lib-barcode: png() prints; render() returns bytes; text()/raw() return rows. */
class QRcode
{
    private const MAX_IMAGE_SIDE = 1024;
    private const LEVELS = ['L', 'M', 'Q', 'H'];

    /** Selected version, width and byte-0/1 rows after a successful instance encoding. */
    public $version = null;
    public $width = null;
    public $data = null;

    /** Encode preselected QRinput segments with mask 0..7, or a negative value for configured selection. */
    public function encodeMask(QRinput $input, $mask)
    {
        [$text, $segments] = $input->rcmakerPayload();
        $symbol = self::legacySymbol($text, $input->getVersion(), $input->getErrorCorrectionLevel(), 2, true,
            $segments, self::integer($mask, PHP_INT_MIN, 7, 'mask'));
        $this->acceptLegacySymbol($symbol);
        $input->setVersion($this->version);
        return $this;
    }

    /** Encode QRinput entries using configured mask selection; the resolved version is written back to input. */
    public function encodeInput(QRinput $input)
    {
        return $this->encodeMask($input, -1);
    }

    /** Force a byte segment instead of numeric/alphanumeric compaction; version is the minimum allowed. */
    public function encodeString8bit($string, $version, $level)
    {
        $text = self::content($string);
        return $this->acceptLegacySymbol(self::legacySymbol($text, $version, $level, 2, true,
            [[2, 0, strlen($text)]]));
    }

    /** Preserve the old minimum-version and 8-bit/Kanji hint contract without restoring the old encoder. */
    public function encodeString($string, $version, $level, $hint, $casesensitive)
    {
        return $this->acceptLegacySymbol(self::legacySymbol($string, $version, $level, $hint, (bool) $casesensitive));
    }

    /** QRcode::png('payload') prints; a destination saves silently unless saveandprint is true. */
    public static function png($text, $outfile = false, $level = 0, $size = 3, $margin = 4, $saveandprint = false, array $options = []): void
    {
        echo self::render($text, 'png', $outfile, $level, $size, $margin, $saveandprint, $options);
    }

    /** SVG counterpart to png(); for framework responses or string values use render(..., 'svg'). */
    public static function svg($text, $outfile = false, $level = 0, $size = 3, $margin = 4, $saveandprint = false, array $options = []): void
    {
        echo self::render($text, 'svg', $outfile, $level, $size, $margin, $saveandprint, $options);
    }

    /** Return 01-string rows; supplying an outfile keeps the legacy save-and-return-null contract. */
    public static function text($text, $outfile = false, $level = 0, $size = 3, $margin = 4, array $options = []): ?array
    {
        $target = self::target($outfile);
        $rows = array_map(static fn(array $row): string => implode('', $row), self::native($text, $level, 1, 0, $options)->getGridArray());
        if ($target !== false) {
            self::save($target, implode("\n", $rows));
            return null;
        }
        return $rows;
    }

    /** Return byte-0/1 binary rows: module low-bit semantics remain, obsolete private flag bits do not. */
    public static function raw($text, $outfile = false, $level = 0, $size = 3, $margin = 4, array $options = []): array
    {
        // The old raw() ignored outfile; do not invent an on-disk binary format for existing callers.
        return array_map(static fn(array $row): string => implode('', $row),
            self::native($text, $level, 1, 0, $options)->getGridArray("\0", "\1"));
    }

    /** Return PNG/SVG bytes without PHP headers; save completely before returning optional printed output. */
    public static function render($text, $format = 'png', $outfile = false, $level = 0, $size = 3, $margin = 4, $saveandprint = false, array $options = []): string
    {
        if (!is_string($format) || !in_array(strtolower($format), ['png', 'svg'], true)) {
            throw new \InvalidArgumentException('QR render format must be png or svg.');
        }
        $format = strtolower($format);
        $target = self::target($outfile);
        $symbol = self::native($text, $level, $size, $margin, $options);
        return self::outputModel($symbol, $format, $target, (bool) $saveandprint);
    }

    /** Share rendering and atomic publication with the legacy factory; no QR encoding occurs here. */
    protected static function outputModel(Model $symbol, string $format, string|false $target, bool $saveandprint): string
    {
        $bufferLevel = ob_get_level();
        try {
            $bytes = $format === 'svg' ? $symbol->getSvgCode() : self::pngData($symbol);
        } finally {
            // Native PNG encoding uses a buffer; never close a caller's buffer after a rendering error.
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
        }
        if ($target !== false) {
            self::save($target, $bytes);
            if (!$saveandprint) {
                return '';
            }
        }
        return $bytes;
    }

    /** Obtain an independent native model for advanced colors/SVG; later native mutations are caller-owned. */
    public static function native($text, $level = 0, $size = 3, $margin = 4, array $options = []): Model
    {
        $text = self::content($text);
        $level = self::level($level);
        $size = self::integer($size, 1, PHP_INT_MAX, 'size');
        $margin = self::integer($margin, 0, intdiv(self::MAX_IMAGE_SIDE, 2), 'margin');
        $defaults = ['quality' => 'fast', 'version' => 0, 'mode' => '8B', 'case_sensitive' => true];
        $options += $defaults;
        $quality = $options['quality'];
        $version = self::integer($options['version'], 0, 40, 'version');
        if (!in_array($quality, ['fast', 'best'], true) || array_diff_key($options, $defaults) !== []
            || !in_array($options['mode'], ['8B', 'KJ'], true) || !is_bool($options['case_sensitive'])) {
            throw new \InvalidArgumentException('Invalid QR quality, mode, case_sensitive or unknown option.');
        }
        // Keep the historical two-candidate default; callers can opt into complete eight-mask assessment.
        $symbol = new NativeQrCode($text, -1, -1, 'black', [$level, $options['mode'], $version,
            (int) $options['case_sensitive'], $quality === 'best' ? -1 : 2, 1, 2]);
        return self::sizeModel($symbol, $size, $margin);
    }

    /** Apply the shared bounded integer scaling to an already encoded black/white symbol. */
    protected static function sizeModel(Model $symbol, int $size, int $margin): Model
    {
        $modules = $symbol->getArray()['ncols'];
        $scale = min($size, intdiv(self::MAX_IMAGE_SIDE, $modules + 2 * $margin));
        if ($scale < 1) {
            throw new \InvalidArgumentException('QR margin leaves no space inside the maximum image dimensions.');
        }
        $symbol->setSize(-$scale, -$scale, [-$margin, -$margin, -$margin, -$margin]);
        $symbol->setBackgroundColor('white');
        return $symbol;
    }

    /** Build a legacy-policy model lazily; normal static helpers keep the unmodified native path. */
    protected static function legacySymbol(mixed $text, mixed $version, mixed $level, mixed $hint, bool $casesensitive,
        ?array $segments = null, int $mask = -1): Qrcode\LegacySymbol
    {
        $text = self::content($text);
        $version = self::integer($version, 0, 40, 'version');
        $level = self::level($level);
        $hint = self::integer($hint, 2, 3, 'hint');
        require_once __DIR__ . '/Qrcode/LegacySymbol.php';
        return new Qrcode\LegacySymbol($text, $level, $version, $hint === 3, $casesensitive, $segments, $mask);
    }

    /** Replace public legacy results only after successful native encoding and complete row conversion. */
    protected function acceptLegacySymbol(Model $symbol): static
    {
        $rows = array_map(static fn(array $row): string => implode('', $row), $symbol->getGridArray("\0", "\1"));
        $width = count($rows);
        $this->version = intdiv($width - 17, 4);
        $this->width = $width;
        $this->data = $rows;
        return $this;
    }

    /** Validate content once before native or legacy-policy encoding; binary bytes are preserved. */
    protected static function content(mixed $text): string
    {
        if (!is_string($text) && !is_int($text) && !$text instanceof \Stringable) {
            throw new \InvalidArgumentException('QR content must be a string, integer or Stringable value.');
        }
        $text = (string) $text;
        if ($text === '' || strlen($text) > 7089) {
            throw new \InvalidArgumentException('QR content is empty or exceeds the maximum QR capacity.');
        }
        return $text;
    }

    /** Rasterize native black/white module bars once, then scale without interpolation or QR encoding logic. */
    private static function pngData(Model $symbol): string
    {
        if (!extension_loaded('gd')) {
            throw new \Com\Tecnick\Barcode\Exception('QR PNG output requires the gd extension.');
        }
        $shape = $symbol->getArray();
        $scale = (int) $shape['width_ratio'];
        $margin = intdiv($shape['padding']['L'], $scale);
        $side = $shape['ncols'] + 2 * $margin;
        $modules = imagecreate($side, $side);
        if (!$modules instanceof \GdImage) {
            throw new \RuntimeException('Unable to allocate the QR module image.');
        }
        $white = imagecolorallocate($modules, 255, 255, 255);
        $black = imagecolorallocate($modules, 0, 0, 0);
        imagefill($modules, 0, 0, $white);
        foreach ($shape['bars'] as [$x, $y, $width, $height]) {
            imagefilledrectangle($modules, $x + $margin, $y + $margin,
                $x + $width + $margin - 1, $y + $height + $margin - 1, $black);
        }
        $image = $modules;
        if ($scale !== 1) {
            $image = imagecreate($side * $scale, $side * $scale);
            if (!$image instanceof \GdImage || !imagecopyresized($image, $modules, 0, 0, 0, 0,
                $side * $scale, $side * $scale, $side, $side)) {
                throw new \RuntimeException('Unable to scale the QR image.');
            }
        }
        ob_start();
        if (!imagepng($image)) {
            throw new \RuntimeException('Unable to encode the QR PNG.');
        }
        $bytes = ob_get_clean();
        if (!is_string($bytes) || $bytes === '') {
            throw new \RuntimeException('Unable to read the QR PNG output.');
        }
        return $bytes;
    }

    /** Accept historical numeric/string ECC values, rejecting misspellings instead of silently selecting L. */
    protected static function level(mixed $value): string
    {
        if (is_int($value) && isset(self::LEVELS[$value])) {
            return self::LEVELS[$value];
        }
        if (is_string($value)) {
            $value = strtoupper($value);
            if (in_array($value, self::LEVELS, true)) {
                return $value;
            }
            if (strlen($value) === 1 && $value >= '0' && $value <= '3') {
                return self::LEVELS[(int) $value];
            }
        }
        throw new \InvalidArgumentException('QR correction level must be L/M/Q/H or 0/1/2/3.');
    }

    /** Accept config/environment decimal integers, not fractions, booleans or overflowing values. */
    protected static function integer(mixed $value, int $min, int $max, string $name): int
    {
        if ((!is_int($value) && !is_string($value))
            || ($parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]])) === false) {
            throw new \InvalidArgumentException('Invalid QR ' . $name . '.');
        }
        return $parsed;
    }

    /** Resolve an existing local parent once; wrappers, directories and symlink targets are not files. */
    protected static function target(mixed $path): string|false
    {
        if ($path === false || $path === null) {
            return false;
        }
        if (!is_string($path) || $path === '' || str_contains($path, "\0") || str_contains($path, '://')) {
            throw new \InvalidArgumentException('QR output must be a local filesystem path.');
        }
        $parent = realpath(dirname($path));
        if ($parent === false || !is_writable($parent) || is_link($path) || is_dir($path)
            || (file_exists($path) && (!is_file($path) || !is_writable($path)))) {
            throw new \RuntimeException('The QR destination directory is missing or the destination is not writable.');
        }
        return $parent . DIRECTORY_SEPARATOR . basename($path);
    }

    /** Publish a complete sibling staging file; keep the old destination and clean owned temporary data on failure. */
    protected static function save(string $target, string $bytes): void
    {
        $directory = dirname($target);
        $stage = false;
        $published = false;
        try {
            // Never publish a system-temp fallback; Windows may allocate it after a transient local failure.
            for ($allocation = 0; ; ++$allocation) {
                $stage = @tempnam($directory, '.rc-qr-');
                if ($stage !== false && realpath(dirname($stage)) === $directory) {
                    break;
                }
                if ($stage !== false) {
                    if (!@unlink($stage)) {
                        throw new \RuntimeException('Unable to remove a QR temporary fallback.');
                    }
                    $stage = false;
                }
                if (PHP_OS_FAMILY !== 'Windows' || $allocation >= 3) {
                    throw new \RuntimeException('Unable to create a QR staging file in the destination directory.');
                }
                clearstatcache(true, $directory);
                if (!is_dir($directory) || !is_writable($directory)) {
                    throw new \RuntimeException('The QR destination directory is no longer writable.');
                }
                usleep(1000 << $allocation);
            }
            if (@file_put_contents($stage, $bytes, LOCK_EX) !== strlen($bytes)) {
                throw new \RuntimeException('Unable to write the complete QR output.');
            }
            $mode = is_file($target) ? fileperms($target) & 0777 : 0666 & ~umask();
            if (!@chmod($stage, $mode) || is_link($target) || is_dir($target)) {
                throw new \RuntimeException('Unable to publish QR output; the previous file has not been removed.');
            }
            $published = @rename($stage, $target);
            if (!$published && PHP_OS_FAMILY === 'Windows') {
                for ($attempt = 0; $attempt < 3 && !$published; ++$attempt) {
                    clearstatcache(true, $target);
                    if (is_link($target) || is_dir($target) || (file_exists($target) && !is_writable($target))) {
                        break;
                    }
                    usleep(1000 << $attempt);
                    $published = @rename($stage, $target);
                }
            }
            if (!$published) {
                throw new \RuntimeException('Unable to publish QR output; the previous file has not been removed.');
            }
        } finally {
            // A successful rename releases this name; a later file at that name is no longer ours.
            if (!$published && $stage !== false && is_file($stage)) {
                if (PHP_OS_FAMILY === 'Windows') {
                    try { @chmod($stage, 0600); } catch (\Throwable) {}
                }
                @unlink($stage);
            }
        }
    }
}

// The historical merged file also exposed these facades to direct-require callers.
require_once __DIR__ . '/QRinput.php';
require_once __DIR__ . '/QRinputItem.php';
require_once __DIR__ . '/QRencode.php';
require_once __DIR__ . '/QRimage.php';
require_once __DIR__ . '/QRtools.php';
