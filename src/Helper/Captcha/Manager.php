<?php

declare(strict_types=1);

namespace RC\Helper\Captcha;

use RC\Config;

/** Select local image or offline slider while preserving the existing captcha()/captchaCheck() entry points. */
final class Manager
{
    /** Existing image connections return images/text; slider connections return structured challenge responses. */
    public static function generate($request, string $name = '', string $connect = 'default', $closure = null, $cache = null)
    {
        $config = self::configuration($connect);
        if ($config['provider'] === 'image') {
            return Challenge::generate($request, $name, $connect, $closure, $cache);
        }
        if ($closure !== null) {
            throw new \InvalidArgumentException('Slider storage uses the framework cache, not an image writer closure.');
        }
        return \json($request, Slider::generate($request, $name, $connect, $config, $cache))
            ->withHeaders(['Cache-Control' => 'no-store, private']);
    }

    /** Check a typed answer for images, or consume the one-time final business proof for sliders. */
    public static function check($request, string $name = '', $value = '', string $connect = 'default', $cache = null): bool
    {
        $config = self::configuration($connect);
        return $config['provider'] === 'image'
            ? Challenge::check($request, $name, $value, $connect, $cache)
            : Slider::check($request, $name, $value, $connect, $config, $cache);
    }

    /** Verify a slider coordinate attempt; controllers return the result through $req->json(). */
    public static function verify($request, string $name, mixed $data, string $connect = 'default', $cache = null): array
    {
        $config = self::configuration($connect);
        if ($config['provider'] !== 'slider') {
            throw new \InvalidArgumentException('The image provider uses captchaCheck() directly.');
        }
        if (is_array($data)) {
            if (is_string($data['offset'] ?? null) && strlen($data['offset']) <= 32
                && preg_match('/^\d+(?:\.\d+)?$/D', $data['offset'])) {
                $data['offset'] = (float) $data['offset'];
            }
            if (is_string($data['track'] ?? null)) {
                try {
                    $data['track'] = strlen($data['track']) <= 32768
                        ? json_decode($data['track'], true, 8, JSON_THROW_ON_ERROR) : [];
                } catch (\JsonException) {
                    $data['track'] = [];
                }
            }
        }
        return Slider::verify($request, $name, $data, $connect, $config, $cache);
    }

    /** Serve only bundled widget assets, including directly from a PHAR, without exposing arbitrary paths. */
    public static function asset($request, string $name)
    {
        $files = ['widget.js' => ['widget.js', 'application/javascript; charset=utf-8'],
            'widget.css' => ['widget.css', 'text/css; charset=utf-8']];
        if (!isset($files[$name])) {
            return \response($request, 'Not Found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        [$file, $mime] = $files[$name];
        $bytes = file_get_contents(__DIR__ . '/assets/' . $file);
        if ($bytes === false) {
            throw new \RuntimeException('The bundled captcha widget asset is unavailable.');
        }
        $etag = '"' . hash('sha256', $bytes) . '"';
        $headers = ['Content-Type' => $mime, 'Cache-Control' => 'public, no-cache',
            'X-Content-Type-Options' => 'nosniff', 'ETag' => $etag];
        return $request->header('if-none-match') === $etag
            ? \response($request, '', 304, $headers) : \response($request, $bytes, 200, $headers);
    }

    /** Only explicitly supported offline providers are selectable; misspellings never silently disable verification. */
    private static function configuration(string $connect): array
    {
        $config = Config::get('captcha', $connect);
        if (!is_array($config)) {
            throw new \InvalidArgumentException('Captcha connection is not configured: ' . $connect);
        }
        $config += ['provider' => 'image'];
        if (!in_array($config['provider'], ['image', 'slider'], true)) {
            throw new \InvalidArgumentException('Unknown local captcha provider.');
        }
        return $config;
    }
}
