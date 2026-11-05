<?php

declare(strict_types=1);

namespace RC\Helper\Captcha;

/** Isolate the pinned native image/verification engine from PHP globals and native sessions. */
final class SliderEngine
{
    private const SOURCE_SHA256 = 'd3120493acd58db5de00f5ff0477d47f1e201b1a60e235f2392d4eae2acddd6d';
    private static ?\ReflectionClass $class = null;
    private static ?\Closure $initialize = null;
    private static ?\Closure $render = null;

    /** Bind one request-owned state array; this never invokes session_start() or modifies $_SESSION. */
    public static function create(array $config, array &$state, string $fingerprint): \zxf\Captcha\Captcha
    {
        self::bootstrap();
        $engine = self::$class->newInstanceWithoutConstructor();
        (self::$initialize)($engine, $config, $state, $fingerprint);
        return $engine;
    }

    /** Render only the gapped image and actual piece, not upstream's complete reference background. */
    public static function images(\zxf\Captcha\Captcha $engine): array
    {
        self::bootstrap();
        return (self::$render)($engine);
    }

    /** Prepare checked private hooks once per worker; exact upstream pin makes the contract explicit. */
    private static function bootstrap(): void
    {
        if (self::$class !== null) {
            return;
        }
        require_once __DIR__ . '/slider-bootstrap.php';
        if (!extension_loaded('gd')) {
            throw new \RuntimeException('Offline slider captcha requires GD.');
        }
        $class = new \ReflectionClass(\zxf\Captcha\Captcha::class);
        if (hash_file('sha256', $class->getFileName()) !== self::SOURCE_SHA256) {
            throw new \RuntimeException('RC slider requires unmodified zxf/captcha 1.1.0 sources.');
        }
        $initialize = \Closure::bind(static function ($engine, array $config, array &$state, string $fingerprint): void {
            $engine->config = array_merge($engine->getDefaultConfig(), $config);
            $engine->applyConfig();
            $engine->useMockSession = true;
            $engine->mockSession =& $state;
            $engine->requestFingerprint = $fingerprint;
        }, null, \zxf\Captcha\Captcha::class);
        $render = \Closure::bind(static function ($engine): array {
            $piece = null;
            $mask = null;
            $bufferLevel = ob_get_level();
            try {
                $engine->captchaType = \zxf\Captcha\Captcha::TYPE_SLIDE;
                $engine->setSessionValue($engine->sessionKeyType, $engine->captchaType);
                $engine->setSessionValue($engine->sessionKeyCheck, 'none');
                $engine->init();
                $engine->createSlide();
                $engine->createBg();
                $piece = imagecrop($engine->imSlide, [
                    'x' => 0, 'y' => $engine->posY, 'width' => $engine->markWidth, 'height' => $engine->markHeight,
                ]);
                if (!$piece instanceof \GdImage) {
                    throw new \RuntimeException('Unable to extract the slider piece.');
                }
                // The native sprite has an opaque black surround; use its matching mask to clip only the outside.
                $mask = imagecreatefrompng($engine->config['slide_transparent_img']);
                if (!$mask instanceof \GdImage || imagesx($mask) !== $engine->markWidth || imagesy($mask) !== $engine->markHeight) {
                    throw new \RuntimeException('Unable to load the slider cutout mask.');
                }
                imagealphablending($piece, false);
                $transparent = imagecolorallocatealpha($piece, 0, 0, 0, 127);
                for ($x = 0; $x < $engine->markWidth; $x++) {
                    for ($y = 0; $y < $engine->markHeight; $y++) {
                        $color = imagecolorsforindex($mask, imagecolorat($mask, $x, $y));
                        if ($color['alpha'] === 127) {
                            imagesetpixel($piece, $x, $y, $transparent);
                        }
                    }
                }
                imagesavealpha($piece, true);
                return [
                    'type' => 'slider', 'width' => $engine->bgWidth, 'height' => $engine->bgHeight,
                    'piece_width' => $engine->markWidth, 'piece_height' => $engine->markHeight,
                    'y' => $engine->posY,
                    'background' => 'data:image/png;base64,' . base64_encode($engine->outputImageToBuffer($engine->imBg)),
                    'piece' => 'data:image/png;base64,' . base64_encode($engine->outputImageToBuffer($piece)),
                ];
            } finally {
                $piece = null;
                $mask = null;
                $engine->destroy();
                while (ob_get_level() > $bufferLevel) {
                    ob_end_clean();
                }
            }
        }, null, \zxf\Captcha\Captcha::class);
        if (!$initialize instanceof \Closure || !$render instanceof \Closure) {
            throw new \RuntimeException('Unable to initialize the native slider adapter.');
        }
        self::$initialize = $initialize;
        self::$render = $render;
        self::$class = $class;
    }
}
