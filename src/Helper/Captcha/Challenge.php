<?php

declare(strict_types=1);

namespace RC\Helper\Captcha;

use RC\Config;

/** Store and verify framework challenges; public callers continue to use captcha()/captchaCheck(). */
final class Challenge
{
    /** Issue one challenge; refreshing the same connection/name/client replaces its previous answer. */
    public static function generate($request, string $name = '', string $connect = 'default', $closure = null, $cache = null)
    {
        $config = self::configuration($connect);
        $builder = new PhraseBuilder($config['length'], $config['charset']);
        $renderPhrase = $builder->build();
        $phrase = strtolower($renderPhrase);
        $body = null;
        if ($config['return'] === 'image') {
            $image = new CaptchaBuilder($renderPhrase, $builder);
            $image->setImageType($config['image_type']);
            $image->build(...$config['phrase']);
            $body = $image->get($config['quality']);
        }
        $key = self::key($request, $name, $connect, $config['namePrefix']);
        $record = ['hash' => hash('sha256', $phrase), 'expires_at' => time() + $config['expire']];
        switch ($config['store']) {
            case 'session':
                $request->session()->put([$key => $record]);
                break;
            case 'closure':
                if (!$closure instanceof \Closure) {
                    throw new \InvalidArgumentException('Closure captcha storage requires a writer callback.');
                }
                if ($closure($key, $phrase, $config['expire']) === false) {
                    throw new \RuntimeException('The captcha writer rejected the challenge.');
                }
                break;
            default:
                $cache ??= \cache();
                if ($cache->set($key, $record, $config['expire']) === false) {
                    throw new \RuntimeException('Unable to persist the captcha challenge.');
                }
        }
        if ($config['return'] === 'text') {
            return $phrase;
        }
        return \response($request, $body, 200, [
            'Content-Type' => 'image/' . $config['image_type'],
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'X-Captcha-Expires-In' => (string) $config['expire'],
        ]);
    }

    /** Consume-on-attempt by default; malformed inputs and expired/missing records return false. */
    public static function check($request, string $name = '', $value = '', string $connect = 'default', $cache = null): bool
    {
        $config = self::configuration($connect);
        if ((!is_string($value) && !is_int($value)) || (string) $value === '' || strlen((string) $value) > 256) {
            return false;
        }
        $key = self::key($request, $name, $connect, $config['namePrefix']);
        $storage = $config['store'] === 'session' ? $request->session() : ($cache ?? \cache());
        $record = $config['autoDelete'] ? $storage->pull($key) : $storage->get($key);
        if (is_array($record) && is_string($record['hash'] ?? null) && is_int($record['expires_at'] ?? null)) {
            return $record['expires_at'] > time()
                && hash_equals($record['hash'], hash('sha256', strtolower((string) $value)));
        }
        // Custom writers retain the historical three arguments and must enforce their own storage TTL.
        return $config['store'] === 'closure' && is_string($record)
            && hash_equals($record, strtolower((string) $value));
    }

    /** Validate configuration before rendering or writing, without creating unrelated cache/session objects. */
    private static function configuration(string $connect): array
    {
        $config = Config::get('captcha', $connect);
        if (!is_array($config)) {
            throw new \InvalidArgumentException('Captcha connection is not configured: ' . $connect);
        }
        $config['autoDelete'] = $config['autoDelete'] ?? $config['autoDelte'] ?? true;
        $config += [
            'expire' => 300, 'namePrefix' => 'RC_CAPTCHA_', 'length' => 5,
            'charset' => 'abcdefghijklmnpqrstuvwxyz123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ',
            'store' => 'cache', 'return' => 'image', 'phrase' => [], 'image_type' => 'jpeg', 'quality' => 90,
        ];
        foreach (['expire', 'length', 'quality'] as $key) {
            if (!is_int($config[$key])) {
                throw new \InvalidArgumentException('Captcha ' . $key . ' must be an integer.');
            }
        }
        if ($config['expire'] < 1 || $config['expire'] > 86400 || $config['length'] < 1 || $config['length'] > 256
            || $config['quality'] < 0 || $config['quality'] > 100
            || !is_string($config['charset']) || $config['charset'] === '' || preg_match('/[^\x21-\x7e]/', $config['charset'])
            || !is_string($config['namePrefix']) || !is_array($config['phrase']) || !is_bool($config['autoDelete'])) {
            throw new \InvalidArgumentException('Invalid captcha lifetime, alphabet, length, quality or build options.');
        }
        foreach (['store', 'return', 'image_type'] as $key) {
            if (!is_string($config[$key])) {
                throw new \InvalidArgumentException('Captcha ' . $key . ' must be a string.');
            }
            $config[$key] = strtolower($config[$key]);
        }
        if (!in_array($config['store'], ['cache', 'session', 'closure'], true)
            || !in_array($config['return'], ['image', 'text'], true)
            || !in_array($config['image_type'], ['jpeg', 'png', 'gif'], true)) {
            throw new \InvalidArgumentException('Unknown captcha store, output mode or image format.');
        }
        return $config;
    }

    /** Length-delimited fields prevent concatenation collisions; the answer is never part of the key. */
    private static function key($request, string $name, string $connect, string $prefix): string
    {
        return hash('sha256', serialize(['rcmaker-captcha-v1', $connect, $prefix, $name, (string) $request->ip()]));
    }
}
