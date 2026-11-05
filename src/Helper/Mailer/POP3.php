<?php

namespace RC\Helper\Mailer;

require_once __DIR__ . '/bootstrap.php';

/** Keep the legacy POP-before-SMTP API while native PHPMailer owns the actual protocol. */
class POP3 extends \PHPMailer\PHPMailer\POP3
{
    private const MAX_RESPONSE_BYTES = 512;
    private const MAX_COMMAND_BYTES = 255;
    private ?array $endpoint = null;

    /** Use the RC lifecycle adapter, including when called through a project-specific subclass. */
    public static function popBeforeSmtp($host, $port = false, $timeout = false, $username = '', $password = '', $debug_level = 0)
    {
        return (new static())->authorise($host, $port, $timeout, $username, $password, $debug_level);
    }

    /** A one-shot authorization owns a fresh connection and releases it even when native/custom code throws. */
    public function authorise($host, $port = false, $timeout = false, $username = '', $password = '', $debug_level = 0)
    {
        $this->disconnect();
        $this->do_debug = (int) $debug_level;
        $this->errors = [];
        $credentials = $this->prepareCredentials($username, $password);
        if ($credentials === null) {
            return false;
        }
        [$username, $password] = $credentials;
        try {
            return parent::authorise($host, $port, $timeout, $username, $password, $debug_level);
        } finally {
            // Normal native authorization sends QUIT; an exception only closes, so cleanup cannot replace its cause.
            $this->releaseConnection();
        }
    }

    /** Reuse only the same live endpoint; contain native fsockopen's temporary error handler on every path. */
    public function connect($host, $port = false, $tval = 30)
    {
        $endpoint = is_string($host) && (is_int($port) || $port === false || (is_string($port) && ctype_digit($port)))
            ? [$host, $port === false ? static::DEFAULT_PORT : (int) $port] : null;
        if ($this->connected && is_resource($this->pop_conn) && $endpoint !== null && $endpoint === $this->endpoint) {
            return true;
        }
        $this->disconnect();
        $previous = get_error_handler();
        try {
            $result = parent::connect($host, $port, $tval);
            if ($result) {
                $this->endpoint = $endpoint;
            }
            return $result;
        } finally {
            // Pop only this native method's stranded closure; preserve the caller's callable and error-level mask.
            $current = get_error_handler();
            if ($current !== $previous && $current instanceof \Closure) {
                $handler = new \ReflectionFunction($current);
                if ($handler->getClosureThis() === $this
                    && $handler->getClosureScopeClass()?->getName() === \PHPMailer\PHPMailer\POP3::class) {
                    restore_error_handler();
                }
            }
            if (!$this->connected) {
                $this->releaseConnection();
            }
        }
    }

    /** Validate the exact native-cleaned credentials before any USER/PASS command, preserving literal zero. */
    public function login($username = '', $password = '')
    {
        if (!$this->connected || !is_resource($this->pop_conn)) {
            $this->releaseConnection();
            return parent::login($username, $password);
        }
        $credentials = $this->prepareCredentials(
            empty($username) && $username !== '0' ? $this->username : $username,
            empty($password) && $password !== '0' ? $this->password : $password
        );
        if ($credentials === null) {
            return false;
        }
        [$username, $password] = $credentials;
        $previousUser = $this->username;
        $previousPassword = $this->password;
        // Native empty() fallback must not replace an explicit zero or a value stripped to an empty string.
        if ($username === '' || $username === '0') {
            $this->username = $username;
        }
        if ($password === '' || $password === '0') {
            $this->password = $password;
        }
        try {
            return parent::login($username, $password);
        } finally {
            $this->username = $previousUser;
            $this->password = $previousPassword;
        }
    }

    /** Read a complete bounded status line; keep the legacy size argument for subclass compatibility. */
    protected function getResponse($size = 128)
    {
        return is_resource($this->pop_conn) ? parent::getResponse(self::MAX_RESPONSE_BYTES + 1) : false;
    }

    /** Never accept a truncated prefix or leave an unread overlong line in a reusable authentication stream. */
    protected function checkResponse($string)
    {
        if (!is_string($string) || strlen($string) > self::MAX_RESPONSE_BYTES || !str_ends_with($string, static::LE)
            || preg_match('/[\x00-\x1F\x7F]/', substr($string, 0, -2))) {
            $this->setError('Incomplete or malformed POP3 response.');
            $this->releaseConnection();
            return false;
        }
        // Greeting extension syntax is broader; USER/PASS responses require a delimited status token.
        if ($this->connected && $string !== '+OK' . static::LE && !str_starts_with($string, '+OK ')
            && $string !== '-ERR' . static::LE && !str_starts_with($string, '-ERR ')) {
            $this->setError('Invalid POP3 response status.');
            $this->releaseConnection();
            return false;
        }
        return parent::checkResponse($string);
    }

    /** Delegate stripping/coercion once, then reject invalid text and overlong wire commands without truncation. */
    private function prepareCredentials($username, $password): ?array
    {
        $credentials = [];
        foreach ([$username, $password] as $value) {
            if (!is_scalar($value) && !($value instanceof \Stringable)) {
                $this->setError('Invalid POP3 credential encoding or type.');
                return null;
            }
            $clean = parent::stripControls($value);
            if (!is_string($clean)) {
                $this->setError('Invalid POP3 credential encoding or type.');
                return null;
            }
            if (strlen($clean) + 7 > self::MAX_COMMAND_BYTES) {
                $this->setError('POP3 USER/PASS commands must not exceed 255 bytes.');
                return null;
            }
            $credentials[] = $clean;
        }
        return $credentials;
    }

    /** Idempotent disconnect: retain native QUIT, but never let a response exception strand the socket. */
    public function disconnect()
    {
        try {
            if (is_resource($this->pop_conn)) {
                parent::disconnect();
            }
        } finally {
            $this->releaseConnection();
        }
    }

    /** Release only this object's stream; no process-wide or cross-helper connection pool is introduced. */
    private function releaseConnection(): void
    {
        try {
            if (is_resource($this->pop_conn)) {
                fclose($this->pop_conn);
            }
        } finally {
            $this->pop_conn = false;
            $this->connected = false;
            $this->endpoint = null;
        }
    }
}
