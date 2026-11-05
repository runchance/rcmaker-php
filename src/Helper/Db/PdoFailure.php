<?php

declare(strict_types=1);

namespace RC\Helper\Db;

/** RC promotion of a native PDO false return; existing catch(PDOException) handlers remain valid. */
final class PdoFailure extends \PDOException
{
    /** Capture the failing object's errorInfo before cursor cleanup can replace its native state. */
    public static function fromPdo(\PDO|\PDOStatement $source, string $fallback): self
    {
        $info = $source->errorInfo();
        if (!is_string($info[0] ?? null) || !preg_match('/\A[A-Z0-9]{5}\z/D', $info[0])
            || in_array($info[0], ['00000', '02000'], true)) {
            $info = ['HY000', null, $fallback];
        }
        return new self($info, $fallback);
    }

    /** Keep SQLSTATE separate from the vendor code, exactly as native PDO exceptions do. */
    private function __construct(array $info, string $fallback)
    {
        parent::__construct(is_string($info[2] ?? null) && $info[2] !== '' ? $info[2] : $fallback);
        $this->code = $info[0];
        $this->errorInfo = $info;
    }

    /** Recognize lost sessions, not ordinary constraint/syntax errors or PostgreSQL query cancellation. */
    public static function isDisconnect(\Throwable $error): bool
    {
        $state = self::disconnectState($error);
        if ($state !== null) { return $state; }
        if (!$error instanceof \PDOException
            && !($error instanceof \ErrorException && preg_match('/\APDO(?:Statement)?::/', $error->getMessage()))) {
            return false;
        }
        foreach (['server has gone away', 'no connection to the server', 'lost connection', 'connection is closed',
            'is dead or not enabled', 'error while sending', 'decryption failed or bad record mac',
            'server closed the connection unexpectedly', 'SSL connection has been closed unexpectedly',
            'error writing data to the connection', 'failed with errno'] as $message) {
            if (stripos($error->getMessage(), $message) !== false) { return true; }
        }
        return false;
    }

    /** Internal tri-state: known transport/business status wins; null retains the driver's legacy detector. */
    public static function disconnectState(\Throwable $error): ?bool
    {
        if ($error instanceof \PDOException) {
            $info = $error->errorInfo;
            $state = $info[0] ?? null;
            if (!is_string($state) || !preg_match('/\A[A-Z0-9]{5}\z/D', $state) || in_array($state, ['00000', '02000'], true)) {
                $state = (string) $error->getCode();
            }
            $vendor = $info[1] ?? null;
            $message = is_string($info[2] ?? null) ? $info[2] : $error->getMessage();
        } elseif ($error instanceof \ErrorException && preg_match('/\APDO(?:Statement)?::/', $error->getMessage())) {
            preg_match('/SQLSTATE\[([A-Z0-9]{5})\](?:: [^:\r\n]+: (\d+) | \[(\d+)\] )?/', $error->getMessage(), $match);
            $state = $match[1] ?? '';
            $vendor = ($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? null);
            $message = isset($match[0]) ? substr($error->getMessage(), strpos($error->getMessage(), $match[0]) + strlen($match[0])) : '';
        } else {
            return null;
        }
        if (!preg_match('/\A[A-Z0-9]{5}\z/D', $state)) { return null; }
        if (str_starts_with($state, '08') || in_array($state, ['01002', '25P03', '57P01', '57P02', '57P03', '57P04', '57P05'], true)) {
            return true;
        }
        $vendor = is_int($vendor) || is_string($vendor) ? (string) $vendor : '';
        if ($vendor === '7' && preg_match('/\A(?:FATAL|PANIC):\s/', $message)) { return true; }
        if ($state === 'HY000') {
            if (in_array($vendor, ['2006', '2013', '2055'], true)) { return true; }
            if ($vendor === '1644') { return false; }
            if ($vendor === '7') {
                if (preg_match('/\A(?:ERROR|WARNING|NOTICE):\s/', $message)) { return false; }
            }
            return null;
        }
        if ($state === '57014' || in_array(substr($state, 0, 2), ['22', '23', '24', '25', '26', '27', '28', '2B', '2D', '2F',
            '34', '38', '39', '3B', '3D', '3F', '40', '42', '44', '45', '53', '54', '55', 'P0'], true)) { return false; }
        return null;
    }
}
