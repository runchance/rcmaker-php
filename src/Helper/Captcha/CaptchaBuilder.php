<?php

declare(strict_types=1);

namespace RC\Helper\Captcha;

require_once __DIR__ . '/bootstrap.php';

/** RC lifecycle/PHAR adapter; all image drawing remains in pristine Gregwar Captcha. */
class CaptchaBuilder extends \Gregwar\Captcha\CaptchaBuilder implements CaptchaBuilderInterface
{
    /** Use the secure RC phrase generator by default while accepting upstream custom builders. */
    public function __construct(string|int|null $phrase = null, ?\Gregwar\Captcha\PhraseBuilderInterface $builder = null)
    {
        $builder ??= new PhraseBuilder();
        parent::__construct(is_int($phrase) ? $builder->build($phrase) : $phrase, $builder);
    }

    /** Preserve the legacy RC factory identity instead of returning a raw upstream builder. */
    public static function create(string|int|null $phrase = null): static
    {
        return new static($phrase);
    }

    /** Compare normalized phrases strictly, including literal zero, rather than PHP numeric-string equality. */
    public function testPhrase(string $phrase): bool
    {
        $expected = $this->getPhrase();
        return $expected !== null && $expected !== '' && $this->builder !== null
            && hash_equals($this->builder->niceize($expected), $this->builder->niceize($phrase));
    }

    /** Render trusted configured dimensions, rejecting accidental excessive allocations before GD is called. */
    public function build(int $width = 150, int $height = 40, ?string $font = null, ?array $fingerprint = null): static
    {
        if ($width < 1 || $height < 1 || $width > 2048 || $height > 2048 || $width * $height > 1048576) {
            throw new \InvalidArgumentException('Captcha dimensions must be positive, at most 2048 each and 1048576 pixels.');
        }
        if (!function_exists('imagettfbbox')) {
            throw new \RuntimeException('Captcha images require GD with FreeType support.');
        }
        $this->contents = null;
        try {
            return parent::build($width, $height, $font, $fingerprint);
        } catch (\Throwable $error) {
            $this->contents = null;
            throw $error;
        }
    }

    /** Capture image bytes without leaving output buffers behind when upstream output raises an error. */
    public function get(int $quality = 90): string
    {
        $level = ob_get_level();
        try {
            return parent::get($quality);
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }

    /** Accept the documented 0-100 quality scale without passing PNG compression level 10 to GD. */
    public function save(?string $filename = null, int $quality = 90): void
    {
        if ($quality < 0 || $quality > 100) {
            throw new \InvalidArgumentException('Captcha image quality must be between 0 and 100.');
        }
        if (!function_exists('image' . $this->getImageType())) {
            throw new \RuntimeException('GD does not support the selected captcha image format.');
        }
        parent::save($filename, $this->getImageType() === 'png' ? min(90, $quality) : $quality);
    }

    /** Materialize only the selected PHAR font before invoking the original drawing implementation. */
    protected function writePhrase(\GdImage $image, ?string $phrase, string $font, int $width, int $height): ?int
    {
        return parent::writePhrase($image, $phrase, FontCache::resolve($font), $width, $height);
    }
}
