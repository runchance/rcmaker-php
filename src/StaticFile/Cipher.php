<?php

declare(strict_types=1);

namespace RC\StaticFile;

use InvalidArgumentException;
use RuntimeException;

/** Authenticated portable static-asset envelope; use encrypt()/decrypt(), never an unauthenticated stream cipher. */
final class Cipher
{
    public const MAGIC = "\x89RCSTATIC";
    public const DEFAULT_LIMIT = 16777216;
    private const ALGORITHMS = ['aes-256-gcm' => 1, 'xchacha20-poly1305' => 2];
    private static array $availableAlgorithms = [];
    private string $algorithm;
    private string $keyId;
    private array $keys = [];
    private array $extensions;
    private int $limit;

    /** Configure algorithm, active key_id, base64/hex encoded 32-byte keys and required encrypted extensions. */
    public function __construct(#[\SensitiveParameter] array $config)
    {
        $this->algorithm = strtolower((string) ($config['algorithm'] ?? 'aes-256-gcm'));
        self::requireAlgorithm($this->algorithm);
        $this->keyId = (string) ($config['key_id'] ?? 'v1');
        if (!is_array($config['keys'] ?? null)) { throw new InvalidArgumentException('Static encryption keys must be an associative array.'); }
        foreach ($config['keys'] as $id => $value) {
            $id = (string) $id;
            if (preg_match('/\A[A-Za-z0-9_.-]{1,64}\z/D', $id) !== 1 || !is_string($value)) { throw new InvalidArgumentException('Static encryption key identifiers/values are invalid.'); }
            $this->keys[$id] = self::decodeKey($value);
        }
        if (!isset($this->keys[$this->keyId])) { throw new InvalidArgumentException('The active static encryption key_id has no configured key.'); }
        $limit = $config['max_file_size'] ?? self::DEFAULT_LIMIT;
        if (!is_int($limit) || $limit < 0 || $limit > PHP_INT_MAX - 129) { throw new InvalidArgumentException('Static encryption max_file_size must be a bounded nonnegative integer.'); }
        $this->limit = $limit;
        $this->extensions = self::normalizeExtensions($config['extensions'] ?? '*');
    }

    /** Return a generated, explicitly encoded 256-bit key suitable for a config profile. */
    public static function generateKey(): string { return 'base64:' . base64_encode(random_bytes(32)); }

    /** Keep diagnostic dumps useful without exposing configured key bytes. */
    public function __debugInfo(): array
    {
        return ['algorithm' => $this->algorithm, 'key_id' => $this->keyId, 'extensions' => $this->extensions, 'max_file_size' => $this->limit, 'keys' => '[redacted]'];
    }

    /** Normalize '*' or a comma-separated/list extension selection; an empty selection is an error. */
    public static function normalizeExtensions(array|string $extensions): array
    {
        if (is_string($extensions)) { $extensions = preg_split('/[,\s]+/', $extensions, -1, PREG_SPLIT_NO_EMPTY); }
        $result = [];
        foreach ($extensions as $extension) {
            if (!is_string($extension)) { throw new InvalidArgumentException('Static extensions must be strings.'); }
            $extension = strtolower(ltrim(trim($extension), '.'));
            if ($extension === '*') { return ['*']; }
            if (preg_match('/\A[a-z0-9][a-z0-9_-]*\z/D', $extension) !== 1) { throw new InvalidArgumentException('Invalid static file extension.'); }
            $result[$extension] = $extension;
        }
        if ($result === []) { throw new InvalidArgumentException('Select all static files or at least one extension.'); }
        return array_values($result);
    }

    /** Test which plaintext files must be encrypted; corrupted magic cannot silently downgrade those files. */
    public function requiresEncryption(string $file): bool
    {
        return $this->extensions === ['*'] || in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), $this->extensions, true);
    }

    /** Inspect a prefix without allocating or parsing an entire untrusted asset. */
    public static function isEncrypted(string $prefix): bool { return str_starts_with($prefix, self::MAGIC); }

    /** Encrypt one bounded asset with a fresh nonce and an authenticated version/key/length header. */
    public function encrypt(#[\SensitiveParameter] string $plaintext): string
    {
        if (strlen($plaintext) > $this->limit) { throw new RuntimeException('Static asset exceeds the configured encryption size limit.'); }
        $nonce = random_bytes($this->algorithm === 'aes-256-gcm' ? 12 : 24);
        $header = self::MAGIC . pack('CCCCJ', 1, self::ALGORITHMS[$this->algorithm], strlen($this->keyId), strlen($nonce), strlen($plaintext)) . $this->keyId . $nonce;
        if ($this->algorithm === 'aes-256-gcm') {
            $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $this->keys[$this->keyId], OPENSSL_RAW_DATA, $nonce, $tag, $header, 16);
            if ($ciphertext === false || strlen($tag) !== 16) { throw new RuntimeException('Static asset encryption failed.'); }
            return $header . $ciphertext . $tag;
        }
        return $header . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $header, $nonce, $this->keys[$this->keyId]);
    }

    /** Protect one bounded source file; an existing envelope must authenticate with its original configured cipher. */
    public function protectFile(string $file, ?self $sourceCipher = null): string
    {
        $stream = @fopen($file, 'rb');
        if ($stream === false) { throw new RuntimeException('Cannot read static encryption input.'); }
        try { $prefix = fread($stream, strlen(self::MAGIC)); } finally { fclose($stream); }
        if (is_string($prefix) && self::isEncrypted($prefix)) {
            if ($sourceCipher === null) { throw new RuntimeException('Existing static ciphertext requires its original configured key.'); }
            $plain = $sourceCipher->read($file);
        } else { $plain = file_get_contents($file, false, null, 0, $this->limit + 1); }
        if (!is_string($plain)) { throw new RuntimeException('Cannot read static encryption input.'); }
        $encrypted = $this->encrypt($plain);
        if ($this->decrypt($encrypted) !== $plain) { throw new RuntimeException('Cannot verify prepared static ciphertext.'); }
        return $encrypted;
    }

    /** Authenticate before returning any plaintext; old key IDs/algorithms remain readable for key rotation. */
    public function decrypt(#[\SensitiveParameter] string $payload): string
    {
        $fixed = strlen(self::MAGIC) + 12;
        if (!self::isEncrypted($payload) || strlen($payload) < $fixed + 1 + 12 + 16) { throw new RuntimeException('Invalid encrypted static asset.'); }
        $meta = unpack('Cversion/Calgorithm/Ckey_size/Cnonce_size/Jsize', substr($payload, strlen(self::MAGIC), 12));
        $algorithm = array_search($meta['algorithm'], self::ALGORITHMS, true);
        if ($meta['version'] !== 1 || $algorithm === false || $meta['key_size'] < 1 || $meta['key_size'] > 64
            || $meta['nonce_size'] !== ($algorithm === 'aes-256-gcm' ? 12 : 24) || $meta['size'] < 0 || $meta['size'] > $this->limit) {
            throw new RuntimeException('Invalid encrypted static asset header.');
        }
        $headerSize = $fixed + $meta['key_size'] + $meta['nonce_size'];
        if (strlen($payload) !== $headerSize + $meta['size'] + 16) { throw new RuntimeException('Truncated or extended encrypted static asset.'); }
        $id = substr($payload, $fixed, $meta['key_size']);
        if (!isset($this->keys[$id])) { throw new RuntimeException('Encrypted static asset uses an unavailable key_id.'); }
        self::requireAlgorithm($algorithm);
        $header = substr($payload, 0, $headerSize); $nonce = substr($header, -$meta['nonce_size']); $body = substr($payload, $headerSize);
        $plaintext = $algorithm === 'aes-256-gcm'
            ? openssl_decrypt(substr($body, 0, -16), 'aes-256-gcm', $this->keys[$id], OPENSSL_RAW_DATA, $nonce, substr($body, -16), $header)
            : sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($body, $header, $nonce, $this->keys[$id]);
        if (!is_string($plaintext) || strlen($plaintext) !== $meta['size']) { throw new RuntimeException('Cannot authenticate encrypted static asset.'); }
        return $plaintext;
    }

    /** Reuse an existing preload read; selected plaintext/corrupt assets fail instead of being emitted as success. */
    public function decode(string $bytes, string $file): string
    {
        if (self::isEncrypted($bytes)) { return $this->decrypt($bytes); }
        if ($this->requiresEncryption($file)) { throw new RuntimeException('Expected an encrypted static asset.'); }
        return $bytes;
    }

    /** Read ciphertext only when needed; unselected ordinary assets keep the original file/stream response path. */
    public function read(string $file): ?string
    {
        $stream = @fopen($file, 'rb'); if ($stream === false) { throw new RuntimeException('Cannot read static asset.'); }
        try {
            $prefix = fread($stream, strlen(self::MAGIC));
            if (!is_string($prefix)) { throw new RuntimeException('Cannot inspect static asset.'); }
            if (!self::isEncrypted($prefix)) {
                if ($this->requiresEncryption($file)) { throw new RuntimeException('Expected an encrypted static asset.'); }
                return null;
            }
            $maximum = $this->limit + 128;
            $rest = stream_get_contents($stream, $maximum + 1);
            if (!is_string($rest) || strlen($prefix) + strlen($rest) > $maximum) { throw new RuntimeException('Static asset exceeds the configured decryption size limit.'); }
            return $this->decrypt($prefix . $rest);
        } finally { fclose($stream); }
    }

    /** Reject implicit password padding/truncation: profiles carry exactly 32 random key bytes. */
    private static function decodeKey(#[\SensitiveParameter] string $value): string
    {
        $key = match (true) {
            str_starts_with($value, 'base64:') => base64_decode(substr($value, 7), true),
            str_starts_with($value, 'hex:') && preg_match('/\A[0-9a-fA-F]{64}\z/D', substr($value, 4)) === 1 => hex2bin(substr($value, 4)),
            default => false,
        };
        if (!is_string($key) || strlen($key) !== 32) { throw new InvalidArgumentException('Static keys require base64: or hex: encoding of exactly 32 random bytes.'); }
        return $key;
    }

    /** Restrict formats to maintained authenticated native primitives, never a configurable unauthenticated mode. */
    private static function requireAlgorithm(string $algorithm): void
    {
        if (isset(self::$availableAlgorithms[$algorithm])) { return; }
        if (!isset(self::ALGORITHMS[$algorithm])) { throw new InvalidArgumentException('Use aes-256-gcm or xchacha20-poly1305 for static encryption.'); }
        if ($algorithm === 'aes-256-gcm' && (!function_exists('openssl_encrypt') || !in_array('aes-256-gcm', openssl_get_cipher_methods(), true))) {
            throw new RuntimeException('AES-256-GCM requires an OpenSSL runtime with GCM support.');
        }
        if ($algorithm === 'xchacha20-poly1305' && !function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            throw new RuntimeException('XChaCha20-Poly1305 requires the sodium extension.');
        }
        self::$availableAlgorithms[$algorithm] = true;
    }
}
