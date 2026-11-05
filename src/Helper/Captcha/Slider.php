<?php

declare(strict_types=1);

namespace RC\Helper\Captcha;

/** Offline slider lifecycle over framework cache; each coordinate attempt and final proof is single-use. */
final class Slider
{
    /** Issue images and an opaque challenge ID; secret native state is stored only on the server. */
    public static function generate($request, string $name, string $connect, array $config, $cache = null): array
    {
        $config = self::configuration($config, true);
        $ip = self::clientIp($request, $config);
        $cache ??= \cache();
        if (!self::rateAllowed($request, $connect, $config, $cache, $ip)) {
            return self::failure('too_many_requests');
        }
        $context = self::context($request, $name, $connect, $ip);
        $state = [];
        $engine = SliderEngine::create(self::engineConfig($config), $state, $context);
        $images = SliderEngine::images($engine);
        $id = bin2hex(random_bytes(32));
        self::persist($cache, self::key('challenge', $id), [
            'context' => $context, 'expires_at' => time() + $config['expire'], 'state' => $state,
            'width' => $images['width'], 'height' => $images['height'], 'piece_width' => $images['piece_width'],
        ], $config['expire']);
        return ['success' => true, 'challenge_id' => $id, 'expires_in' => $config['expire']] + $images;
    }

    /** Consume a challenge and verify coordinates/track, issuing a separate one-time business proof on success. */
    public static function verify($request, string $name, mixed $data, string $connect, array $config, $cache = null): array
    {
        $config = self::configuration($config);
        $ip = self::clientIp($request, $config);
        $cache ??= \cache();
        if (!self::rateAllowed($request, $connect, $config, $cache, $ip)) {
            return self::failure('too_many_requests');
        }
        if (!is_array($data) || !self::identifier($data['challenge_id'] ?? null)) {
            return self::failure('invalid_challenge');
        }
        $record = $cache->pull(self::key('challenge', $data['challenge_id']));
        $context = self::context($request, $name, $connect, $ip);
        if (!self::validRecord($record, $context) || !self::validTrack($data, $record)) {
            return self::failure('invalid_challenge');
        }
        $state = $record['state'];
        $engine = SliderEngine::create(self::engineConfig($config), $state, $context);
        $result = $engine->verify((string) $data['offset'], null, [], $data['track']);
        if (($result['success'] ?? null) !== true || !self::identifier($result['token'] ?? null)) {
            return self::failure('verification_failed');
        }
        $token = $result['token'];
        // A business proof has its own deadline; native challenge creation time must not expire it early.
        $proofState = array_intersect_key($state, [
            'rc_slider_token' => true, 'rc_slider_token_expire' => true, 'rc_slider_check' => true,
        ]);
        self::persist($cache, self::key('proof', $token), [
            'context' => $context, 'expires_at' => time() + $config['token_expire'], 'state' => $proofState,
        ], $config['token_expire']);
        return ['success' => true, 'token' => $token, 'expires_in' => $config['token_expire']];
    }

    /** Consume the business proof from login/register/etc; challenge IDs and coordinates are not proofs. */
    public static function check($request, string $name, mixed $token, string $connect, array $config, $cache = null): bool
    {
        $config = self::configuration($config);
        if (!self::identifier($token)) {
            return false;
        }
        $ip = self::clientIp($request, $config);
        $cache ??= \cache();
        if (!self::rateAllowed($request, $connect, $config, $cache, $ip)) {
            return false;
        }
        $record = $cache->pull(self::key('proof', $token));
        $context = self::context($request, $name, $connect, $ip);
        if (!self::validRecord($record, $context, true)) {
            return false;
        }
        $state = $record['state'];
        $engine = SliderEngine::create(self::engineConfig($config), $state, $context);
        return ($engine->verify(null, $token)['success'] ?? null) === true;
    }

    /** Normalize a finite configuration surface; native frontend-only and reusable-proof modes are never exposed. */
    private static function configuration(array $config, bool $render = false): array
    {
        $config += ['expire' => 120, 'token_expire' => 60, 'width' => 280, 'height' => 160,
            'tolerance' => 3, 'backgrounds' => [], 'client_ip' => null,
            'rate_limit' => ['window' => 60, 'max_requests' => 30]];
        if ($config['client_ip'] !== null && !is_callable($config['client_ip'])) {
            throw new \InvalidArgumentException('Slider client_ip must be a trusted resolver callable or null.');
        }
        if (($config['store'] ?? 'cache') !== 'cache' || ($config['autoDelete'] ?? true) !== true
            || ($config['autoDelte'] ?? true) !== true || ($config['verify_mode'] ?? 'dual') !== 'dual') {
            throw new \InvalidArgumentException('Offline slider requires framework cache, single-use proofs and enabled server verification/rate limits.');
        }
        foreach (['expire' => [30, 600], 'token_expire' => [30, 300], 'width' => [200, 600],
            'height' => [120, 400], 'tolerance' => [0, 5]] as $name => [$min, $max]) {
            if (!is_int($config[$name]) || $config[$name] < $min || $config[$name] > $max) {
                throw new \InvalidArgumentException('Invalid offline slider setting: ' . $name);
            }
        }
        if (!is_array($config['backgrounds']) || count($config['backgrounds']) > 100
            || !is_array($config['rate_limit'])) {
            throw new \InvalidArgumentException('Invalid slider background or rate-limit configuration.');
        }
        if (isset($config['rate_limit']['enabled']) && $config['rate_limit']['enabled'] !== true) {
            throw new \InvalidArgumentException('Offline slider rate limits cannot be disabled.');
        }
        $config['rate_limit'] += ['window' => 60, 'max_requests' => 30];
        foreach (['window' => [1, 3600], 'max_requests' => [3, 1000]] as $name => [$min, $max]) {
            $value = $config['rate_limit'][$name];
            if (!is_int($value) || $value < $min || $value > $max) {
                throw new \InvalidArgumentException('Invalid slider rate-limit setting: ' . $name);
            }
        }
        foreach ($config['backgrounds'] as $file) {
            if (!is_string($file) || str_contains($file, "\0")
                || (str_contains($file, '://') && !str_starts_with($file, 'phar://'))
                || ($render && (!is_file($file) || !is_readable($file)))) {
                throw new \InvalidArgumentException('Slider backgrounds must be readable local or PHAR files.');
            }
        }
        return $config;
    }

    /** Supply only reviewed native settings; request state and local paths belong to RC. */
    private static function engineConfig(array $config): array
    {
        return [
            'captcha_type' => 'slide', 'verify_mode' => 'dual', 'session_prefix' => 'rc_slider',
            'captcha_expire' => $config['expire'], 'token_expire' => $config['token_expire'],
            'bg_width' => $config['width'], 'bg_height' => $config['height'], 'output_format' => 'png',
            'png_quality' => 5, 'bg_images' => $config['backgrounds'], 'max_error_count' => 1,
            'rate_limit' => ['enabled' => false], 'security' => ['fingerprint_check' => true],
            'slide' => ['mark_width' => 50, 'mark_height' => 50,
                'fault_tolerance' => $config['tolerance'], 'track_verify' => true, 'track_strictness' => 'normal'],
        ];
    }

    /** Verify finite ordered points and agreement with the submitted final pixel offset before native heuristics. */
    private static function validTrack(array $data, array $record): bool
    {
        foreach (['width' => [200, 600], 'height' => [120, 400], 'piece_width' => [50, 50]] as $name => [$min, $max]) {
            if (!is_int($record[$name] ?? null) || $record[$name] < $min || $record[$name] > $max) {
                return false;
            }
        }
        $offset = $data['offset'] ?? null;
        $track = $data['track'] ?? null;
        if ((!is_int($offset) && !is_float($offset)) || !is_finite((float) $offset)
            || $offset < 0 || $offset > $record['width'] - $record['piece_width']
            || !is_array($track) || !array_is_list($track) || count($track) < 8 || count($track) > 200) {
            return false;
        }
        $previous = -1;
        foreach ($track as $point) {
            if (!is_array($point)) {
                return false;
            }
            foreach (['x', 'y', 't'] as $field) {
                if ((!is_int($point[$field] ?? null) && !is_float($point[$field] ?? null))
                    || !is_finite((float) $point[$field])) {
                    return false;
                }
            }
            if ($point['x'] < 0 || $point['x'] > $record['width'] || $point['y'] < 0 || $point['y'] > $record['height']
                || $point['t'] < 0 || $point['t'] > 30000 || $point['t'] <= $previous) {
                return false;
            }
            $previous = $point['t'];
        }
        return abs($track[0]['x']) <= 1.5 && abs($track[array_key_last($track)]['x'] - $offset) <= 1.5
            && $track[array_key_last($track)]['t'] - $track[0]['t'] >= 200;
    }

    /** Enforce a fixed shared window per app/connection/IP, never per attacker-selectable challenge name. */
    private static function rateAllowed($request, string $connect, array $config, $cache, string $ip): bool
    {
        $window = $config['rate_limit']['window'];
        $key = hash('sha256', serialize(['rc-slider-rate-v1', $request->app['app'] ?? '', $connect,
            $ip, intdiv(time(), $window)]));
        $count = $cache->inc($key, 1, $window + 1);
        if (!is_int($count)) {
            throw new \RuntimeException('Unable to update the slider rate limit.');
        }
        return $count <= $config['rate_limit']['max_requests'];
    }

    /** Bind opaque artifacts to application, purpose, connection and the framework-resolved client identity. */
    private static function context($request, string $name, string $connect, string $ip): string
    {
        return hash('sha256', serialize([$request->app['app'] ?? '', $connect, $name,
            $ip, (string) $request->header('user-agent', '')]));
    }

    /** Default to the socket peer; forwarded headers require an explicitly trusted proxy resolver. */
    private static function clientIp($request, array $config): string
    {
        $ip = $config['client_ip'] === null ? $request->remoteIp() : ($config['client_ip'])($request);
        if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new \InvalidArgumentException('Slider client_ip must resolve to one valid IP address.');
        }
        return $ip;
    }

    /** Keep all keys portable to the framework cache drivers. */
    private static function key(string $stage, string $id): string
    {
        return hash('sha256', 'rc-slider-v1:' . $stage . ':' . $id);
    }

    /** Validate opaque server-generated IDs, never accepting a boolean frontend success flag. */
    private static function identifier(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    /** Validate expiry/context before passing any stored state to the native verifier. */
    private static function validRecord(mixed $record, string $context, bool $proof = false): bool
    {
        if (!(is_array($record) && is_string($record['context'] ?? null)
            && hash_equals($record['context'], $context) && is_int($record['expires_at'] ?? null)
            && $record['expires_at'] > time() && is_array($record['state'] ?? null))) {
            return false;
        }
        $state = $record['state'];
        if ($proof) {
            return self::identifier($state['rc_slider_token'] ?? null)
                && is_int($state['rc_slider_token_expire'] ?? null) && $state['rc_slider_token_expire'] > time()
                && ($state['rc_slider_check'] ?? null) === 'pending';
        }
        return is_int($state['rc_slider_r'] ?? null) && $state['rc_slider_r'] >= 50
            && is_int($state['rc_slider_created_at'] ?? null)
            && is_string($state['rc_slider_fingerprint'] ?? null)
            && hash_equals($context, $state['rc_slider_fingerprint']) && ($state['rc_slider_type'] ?? null) === 'slide';
    }

    /** Refuse successful issuance when the cache did not save its server-side counterpart. */
    private static function persist($cache, string $key, array $record, int $ttl): void
    {
        if ($cache->set($key, $record, $ttl) === false) {
            throw new \RuntimeException('Unable to save the offline slider challenge.');
        }
    }

    /** Return stable public error codes, not engine exceptions or filesystem paths. */
    private static function failure(string $code): array
    {
        return ['success' => false, 'error' => $code];
    }
}
