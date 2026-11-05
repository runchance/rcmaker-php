<?php

declare(strict_types=1);

namespace RC\Cli;

use RuntimeException;

final class TokenKey
{
    private const ALGORITHMS = ['RS256', 'RS384', 'RS512', 'PS256', 'ES256', 'ES384', 'ES256K', 'EDDSA'];

    public static function execute(string $rootPath, array $options, ?callable $output = null): array
    {
        $algorithm = strtoupper(trim((string)($options['algorithm'] ?? 'RS256')));
        if (!in_array($algorithm, self::ALGORITHMS, true)) {
            throw new RuntimeException('Unsupported signing algorithm: ' . $algorithm);
        }

        $configFile = trim((string)($options['openssl-config'] ?? ''));
        if ($configFile !== '') {
            $configFile = Filesystem::normalizedAbsolute($configFile, $rootPath);
            if (!is_file($configFile) || !is_readable($configFile)) {
                throw new RuntimeException('OpenSSL config is not readable: ' . $configFile);
            }
        }

        if ($algorithm === 'EDDSA') {
            if (!function_exists('sodium_crypto_sign_keypair')) {
                throw new RuntimeException('The sodium extension is required for EdDSA.');
            }
            $keyPair = sodium_crypto_sign_keypair();
            $privateKey = base64_encode(sodium_crypto_sign_secretkey($keyPair));
            $publicKey = base64_encode(sodium_crypto_sign_publickey($keyPair));
        } else {
            if (!extension_loaded('openssl')) {
                throw new RuntimeException('The openssl extension is required.');
            }
            [$privateKey, $publicKey] = self::opensslKeyPair($algorithm, $configFile);
        }

        $sslDir = rtrim($rootPath, DIRECTORY_SEPARATOR . '/\\') . DIRECTORY_SEPARATOR . 'ssl';
        Filesystem::mkdir($sslDir);
        [$privatePath, $publicPath] = self::allocatePaths($sslDir, $algorithm);
        $privateIdentity = self::writeExclusive($privatePath, $privateKey, 0600);
        try {
            self::writeExclusive($publicPath, $publicKey, 0644);
        } catch (\Throwable $throwable) {
            self::removeOwnedFile($privatePath, $privateIdentity);
            throw $throwable;
        }

        self::emit($output, 'Private key: ' . $privatePath);
        self::emit($output, 'Public key: ' . $publicPath);
        return ['algorithm' => $algorithm, 'private' => $privatePath, 'public' => $publicPath];
    }

    /** Generate with native OpenSSL; only an unconfigured Windows installation uses our minimal fallback. */
    private static function opensslKeyPair(string $algorithm, string $configFile): array
    {
        $temporary = null;
        try {
            if ($configFile === '' && self::needsWindowsConfig()) {
                $configFile = __DIR__ . '/openssl.cnf';
                // OpenSSL opens configuration through native I/O, not PHP's PHAR stream wrapper.
                if (str_starts_with($configFile, 'phar://')) {
                    $contents = file_get_contents($configFile);
                    if ($contents === false) {
                        throw new RuntimeException('Failed to read bundled OpenSSL config.');
                    }
                    [$temporary, $stream] = Filesystem::temporaryFile(sys_get_temp_dir() . '/rcmaker-openssl.cnf', 'rcmaker-token-config');
                    try {
                        Filesystem::writeToStream($stream, $contents);
                        if (!fflush($stream)) {
                            throw new RuntimeException('Failed to flush temporary OpenSSL config.');
                        }
                    } finally {
                        fclose($stream);
                    }
                    $configFile = $temporary;
                }
            }
            $config = self::opensslOptions($algorithm, $configFile);
            $resource = openssl_pkey_new($config);
            if ($resource === false) {
                throw new RuntimeException('Failed to generate private key: ' . self::opensslErrors());
            }
            if (!openssl_pkey_export($resource, $privateKey, null, $config)) {
                throw new RuntimeException('Failed to export private key: ' . self::opensslErrors());
            }
            $details = openssl_pkey_get_details($resource);
            if ($details === false || empty($details['key'])) {
                throw new RuntimeException('Failed to export public key: ' . self::opensslErrors());
            }
            return [$privateKey, $details['key']];
        } finally {
            if ($temporary !== null) {
                @unlink($temporary);
            }
        }
    }

    private static function needsWindowsConfig(): bool
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return false;
        }
        foreach (['OPENSSL_CONF', 'SSLEAY_CONF'] as $name) {
            $value = getenv($name);
            if ($value !== false) {
                return false;
            }
        }
        $area = openssl_get_cert_locations()['default_default_cert_area'] ?? '';
        $default = rtrim($area, '/\\') . '/openssl.cnf';
        return $area === '' || (!file_exists($default) && !is_link($default));
    }

    private static function opensslOptions(string $algorithm, string $configFile): array
    {
        $digest = [
            'RS256' => 'sha256',
            'RS384' => 'sha384',
            'RS512' => 'sha512',
            'PS256' => 'sha256',
            'ES256' => 'sha256',
            'ES384' => 'sha384',
            'ES256K' => 'sha256',
        ][$algorithm];
        $options = ['digest_alg' => $digest];
        if ($configFile !== '') {
            $options['config'] = $configFile;
        }
        if (str_starts_with($algorithm, 'RS') || $algorithm === 'PS256') {
            $options['private_key_type'] = OPENSSL_KEYTYPE_RSA;
            $options['private_key_bits'] = ['RS256' => 2048, 'RS384' => 3072, 'RS512' => 4096, 'PS256' => 2048][$algorithm];
        } else {
            $options['private_key_type'] = OPENSSL_KEYTYPE_EC;
            $options['curve_name'] = ['ES256' => 'prime256v1', 'ES384' => 'secp384r1', 'ES256K' => 'secp256k1'][$algorithm];
        }
        return $options;
    }

    private static function allocatePaths(string $directory, string $algorithm): array
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $suffix = (string)random_int(100000, 999999);
            $privatePath = $directory . DIRECTORY_SEPARATOR . $algorithm . '_' . $suffix . '.key';
            $publicPath = $directory . DIRECTORY_SEPARATOR . $algorithm . '_' . $suffix . '.pub';
            if (!file_exists($privatePath) && !file_exists($publicPath)) {
                return [$privatePath, $publicPath];
            }
        }
        throw new RuntimeException('Failed to allocate unique key filenames.');
    }

    /** CLI-only creation: make the private file restrictive from the first open, restoring the process mask immediately. */
    private static function writeExclusive(string $path, #[\SensitiveParameter] string $contents, int $mode): array
    {
        $mask = PHP_OS_FAMILY === 'Windows' ? null : umask(0077);
        try {
            $stream = @fopen($path, 'xb');
        } finally {
            if ($mask !== null) {
                umask($mask);
            }
        }
        if (!is_resource($stream)) {
            throw new RuntimeException('Failed to create key file: ' . $path);
        }
        $identity = fstat($stream);
        $failure = null;
        try {
            if ($identity === false || ($identity['mode'] & 0170000) !== 0100000) {
                throw new RuntimeException('Cannot identify newly created key file: ' . $path);
            }
            if (PHP_OS_FAMILY !== 'Windows' && ($identity['mode'] & 0077) !== 0) {
                throw new RuntimeException('Key file was created with unsafe permissions: ' . $path);
            }
            if (!@chmod($path, $mode)) {
                throw new RuntimeException('Failed to set key file permissions: ' . $path);
            }
            Filesystem::writeToStream($stream, $contents);
            if (!fflush($stream)) {
                throw new RuntimeException('Failed to flush key file: ' . $path);
            }
        } catch (\Throwable $throwable) {
            $failure = $throwable;
        } finally {
            $closed = fclose($stream);
        }
        if ($failure !== null || !$closed) {
            if (is_array($identity)) {
                self::removeOwnedFile($path, $identity);
            }
            throw $failure ?? new RuntimeException('Failed to close key file: ' . $path);
        }
        return $identity;
    }

    /** Do not remove an existing collision or a file that replaced our output between the two writes. */
    private static function removeOwnedFile(string $path, array $identity): void
    {
        clearstatcache(true, $path);
        $current = @lstat($path);
        if ($current !== false && ($current['mode'] & 0170000) === 0100000
            && $current['dev'] === $identity['dev'] && $current['ino'] === $identity['ino']) {
            if (!@unlink($path)) {
                throw new RuntimeException('Failed to remove incomplete key file: ' . $path);
            }
        }
    }

    private static function opensslErrors(): string
    {
        $errors = [];
        while ($error = openssl_error_string()) {
            $errors[] = $error;
        }
        return $errors !== [] ? implode("\n", $errors) : 'unknown OpenSSL error';
    }

    private static function emit(?callable $output, string $message): void
    {
        if ($output !== null) {
            $output($message);
        }
    }
}
