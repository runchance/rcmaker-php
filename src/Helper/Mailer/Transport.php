<?php

declare(strict_types=1);

namespace RC\Helper\Mailer;

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/bootstrap.php';

/** Keep message lifecycle and error localization in RC; MIME, validation and transport stay upstream. */
final class Transport extends PHPMailer
{
    private array $translations = [];
    private bool $dkimSigned = false;
    private ?\Throwable $smtpFailure = null;

    /** Apply RC's narrow SMTP compatibility fix by default; respect an explicitly supplied native engine. */
    public function getSMTPInstance()
    {
        if ($this->smtp === null) {
            $this->smtp = new SmtpClient();
        }
        return $this->smtp;
    }

    /** Split the legacy local SingleTo option through native full-message sends, including recipient-specific signatures. */
    public function postSend()
    {
        if ($this->Mailer === 'smtp') {
            return $this->postSendSmtp();
        }
        if (!$this->SingleTo || !in_array($this->Mailer, ['mail', 'sendmail', 'qmail'], true)) {
            return parent::postSend();
        }
        $firstFailure = null;
        try {
            foreach ($this->to ?: [null] as $recipient) {
                $copy = clone $this;
                $copy->smtp = null;
                $copy->SingleTo = false;
                $copy->exceptions = true;
                $copy->mailHeader = '';
                $copy->MessageID = $this->lastMessageID;
                $copy->clearAllRecipients();
                if ($recipient !== null) {
                    $copy->addAddress($recipient[0], $recipient[1]);
                }
                foreach (['cc' => 'addCC', 'bcc' => 'addBCC'] as $kind => $method) {
                    foreach ($this->{$kind} as $address) {
                        $copy->{$method}($address[0], $address[1]);
                    }
                }
                $callback = $this->action_function;
                $outcome = (object) ['sent' => null, 'callbackComplete' => false];
                $copy->action_function = static function (...$arguments) use ($callback, $outcome): void {
                    $outcome->sent = $arguments[0];
                    if (!empty($callback) && is_callable($callback)) {
                        call_user_func_array($callback, $arguments);
                    }
                    $outcome->callbackComplete = true;
                };
                try {
                    if (!$copy->send()) {
                        throw new Exception($copy->ErrorInfo ?: self::lang('instantiate'), self::STOP_CRITICAL);
                    }
                } catch (Exception $error) {
                    // Native mail() SingleTo attempts every To; sendmail/qmail and callback failures stop immediately.
                    if ($this->Mailer !== 'mail' || $outcome->sent !== false || !$outcome->callbackComplete
                        || $error->getMessage() !== self::lang('instantiate')) {
                        throw $error;
                    }
                    $firstFailure ??= $error;
                }
            }
            if ($firstFailure !== null) {
                throw $firstFailure;
            }
            return true;
        } catch (\Throwable $error) {
            $this->setError($error->getMessage());
            if ($error instanceof Exception && !$this->exceptions) {
                return false;
            }
            throw $error;
        }
    }

    /** Preserve an escaping SMTP failure through native error logging/reset; close aborted sends without replay. */
    private function postSendSmtp(): bool
    {
        $previous = $this->smtpFailure;
        $this->smtpFailure = null;
        try {
            return parent::postSend();
        } catch (\Throwable $error) {
            $first = $this->smtpFailure ?? $error;
            try { $this->smtp?->close(); } catch (\Throwable) {}
            if ($this->smtpFailure !== null && $first instanceof Exception && !$this->exceptions) {
                return false;
            }
            throw $first;
        } finally {
            $this->smtpFailure = $previous;
        }
    }

    /** Native mail() mutates this process-wide setting; restore it even if a callback or local submission throws. */
    protected function mailSend($header, $body)
    {
        $previous = ini_get('sendmail_from');
        try {
            return parent::mailSend($header, $body);
        } finally {
            if ($previous !== false && ini_get('sendmail_from') !== $previous) {
                ini_set('sendmail_from', $previous);
            }
        }
    }

    /** Remember the actual SMTP failure before native postSend() runs fallible diagnostics and reset. */
    protected function smtpSend($header, $body)
    {
        try {
            return $this->smtpSendWithIdentity($header, $body);
        } catch (\Throwable $error) {
            $this->smtpFailure = $error;
            throw $error;
        }
    }

    /** Finish XCLIENT before native envelope/DATA handling; never retry after delivery has started. */
    private function smtpSendWithIdentity($header, $body)
    {
        if ($this->SMTPXClient === []) {
            return parent::smtpSend($header, $body);
        }
        $attributes = $this->SMTPXClient;
        $host = $this->Host;
        $ready = false;
        $lastError = null;
        try {
            foreach (explode(';', $host ?? 'localhost') as $candidate) {
                $this->Host = $candidate;
                try {
                    if (!parent::smtpConnect($this->SMTPOptions)) {
                        throw new Exception(self::lang('smtp_connect_failed'), self::STOP_CRITICAL);
                    }
                    $smtp = $this->getSMTPInstance();
                    if (!$smtp->xclient($attributes)) {
                        throw new Exception('SMTP XCLIENT failed: ' . implode(', ', $smtp->getError()), self::STOP_CRITICAL);
                    }
                    if (substr($smtp->getLastReply(), 0, 3) === '220') {
                        $hello = $attributes['HELO'] ?? '';
                        if ($hello === '' || strcasecmp((string) $hello, '[UNAVAILABLE]') === 0) {
                            $hello = $this->Helo ?: $this->serverHostname();
                        }
                        $greeted = $smtp instanceof SmtpClient
                            ? $smtp->helloAfterXclient((string) $hello, (string) ($attributes['PROTO'] ?? 'ESMTP'))
                            : $smtp->hello((string) $hello);
                        if (!$greeted) {
                            throw new Exception('SMTP greeting after XCLIENT failed.', self::STOP_CRITICAL);
                        }
                        $externalLogin = isset($attributes['LOGIN']) && strcasecmp((string) $attributes['LOGIN'], '[UNAVAILABLE]') !== 0;
                        if ($this->SMTPAuth && !$externalLogin
                            && !$smtp->authenticate($this->Username, $this->Password, $this->AuthType, $this->oauth)) {
                            throw new Exception(self::lang('authenticate'), self::STOP_CRITICAL);
                        }
                    }
                    $ready = true;
                    break;
                } catch (\Throwable $error) {
                    $lastError = $error;
                    try {
                        if ($error instanceof Exception) {
                            try {
                                $this->smtp?->quit();
                            } finally {
                                $this->smtp?->close();
                            }
                        } else {
                            $this->smtp?->close();
                        }
                    } catch (\Throwable) {
                        throw $error;
                    }
                    if (!$error instanceof Exception) {
                        throw $error;
                    }
                }
            }
            if (!$ready) {
                throw $lastError ?? new Exception(self::lang('smtp_connect_failed'), self::STOP_CRITICAL);
            }
            $this->SMTPXClient = [];
            $this->Host = $host;
            return parent::smtpSend($header, $body);
        } finally {
            $this->SMTPXClient = $attributes;
            $this->Host = $host;
        }
    }

    /** Cache at most 128 locale codes; preserve native fallback and restore upstream's global language. */
    public function configureLanguage(string $code): void
    {
        static $cache = [];
        $code = strtolower($code);
        if (!isset($cache[$code])) {
            $previous = parent::$language;
            try {
                if (!parent::setLanguage($code)) {
                    throw new \InvalidArgumentException('Unknown PHPMailer language: ' . $code);
                }
                if (count($cache) >= 128) {
                    unset($cache[array_key_first($cache)]);
                }
                $cache[$code] = parent::$language;
            } finally {
                parent::$language = $previous;
            }
        }
        $this->translations = $cache[$code];
    }

    /** Return this helper's language, not the most recently constructed helper's language. */
    public function getTranslations()
    {
        return $this->translations ?: parent::getTranslations();
    }

    /** Permit only bundled language text in PHAR; upstream's general attachment wrapper policy stays intact. */
    protected static function fileIsAccessible($path)
    {
        if (!is_string($path) || !str_starts_with($path, 'phar://')) {
            return parent::fileIsAccessible($path);
        }
        static $languageRoot = null;
        $languageRoot ??= str_replace('\\', '/', dirname((new \ReflectionClass(PHPMailer::class))->getFileName(), 2)) . '/language/';
        $path = str_replace('\\', '/', $path);
        return str_starts_with($path, $languageRoot)
            && preg_match('/^phpmailer\.lang-[a-z_]+\.php$/D', substr($path, strlen($languageRoot))) === 1
            && is_file($path) && is_readable($path);
    }

    /** Localize only error output; never hold a process-global language override across network I/O. */
    protected function setError($msg)
    {
        parent::setError($msg);
        $replacements = [];
        foreach (parent::getTranslations() as $key => $source) {
            if ($source !== '' && isset($this->translations[$key]) && $source !== $this->translations[$key]) {
                $replacements[$source] = $this->translations[$key];
            }
        }
        $this->ErrorInfo = strtr($this->ErrorInfo, $replacements);
    }

    /** Preserve literal "0" and reject failed explicit signatures before any delivery transport is invoked. */
    public function preSend()
    {
        if ($this->hasBuildError()) {
            return false;
        }
        $allowEmpty = $this->AllowEmpty;
        $exceptions = $this->exceptions;
        $dkim = ($this->DKIM_domain ?? '') !== '' || ($this->DKIM_selector ?? '') !== ''
            || ($this->DKIM_private ?? '') !== '' || ($this->DKIM_private_string ?? '') !== '';
        $smime = ($this->sign_cert_file ?? '') !== '' || ($this->sign_key_file ?? '') !== '' || ($this->sign_extracerts_file ?? '') !== '';
        $signKey = $this->sign_key_file;
        $signChain = $this->sign_extracerts_file;
        if ($this->Body === '0') {
            $this->AllowEmpty = true;
        }
        try {
            if ($dkim || $smime) {
                // Upstream createBody() otherwise swallows S/MIME errors when exceptions are disabled.
                $this->exceptions = true;
                $this->dkimSigned = false;
                $this->validateSigning($dkim, $smime);
                // Upstream uses truthiness for these paths; an existing file literally named "0" is still valid.
                foreach ($smime ? ['sign_key_file', 'sign_extracerts_file'] : [] as $name) {
                    if ($this->{$name} === '0') {
                        $resolved = realpath($this->{$name});
                        if ($resolved === false) {
                            throw new Exception(self::lang('signing') . 'S/MIME signing file is no longer readable.', self::STOP_CRITICAL);
                        }
                        $this->{$name} = $resolved;
                    }
                }
            }
            $prepared = parent::preSend();
            if ($prepared && $dkim && !$this->dkimSigned) {
                // The native gate also skips the nonempty selector/domain/key-path "0"; still delegate all signing.
                $signature = $this->DKIM_Add($this->MIMEHeader . $this->mailHeader,
                    $this->encodeHeader($this->secureHeader($this->Subject)), $this->MIMEBody);
                if (!$this->dkimSigned) {
                    throw new Exception(self::lang('signing') . 'DKIM did not produce a signature.', self::STOP_CRITICAL);
                }
                $this->MIMEHeader = static::stripTrailingWSP($this->MIMEHeader) . static::$LE
                    . static::normalizeBreaks($signature) . static::$LE;
            }
            return $prepared;
        } catch (\Throwable $error) {
            if (!$dkim && !$smime) {
                throw $error;
            }
            $error = $error instanceof Exception ? $error : new Exception(
                self::lang('signing') . 'Unable to create the requested signature.', self::STOP_CRITICAL, $error
            );
            $this->setError($error->getMessage());
            $this->MIMEHeader = $this->MIMEBody = $this->mailHeader = '';
            if ($exceptions) {
                throw $error;
            }
            return false;
        } finally {
            $this->AllowEmpty = $allowEmpty;
            $this->exceptions = $exceptions;
            $this->sign_key_file = $signKey;
            $this->sign_extracerts_file = $signChain;
        }
    }

    /** Validate only explicitly selected signing inputs; do not weaken upstream local-file or MIME policies. */
    private function validateSigning(bool $dkim, bool $smime): void
    {
        if ($dkim) {
            foreach (['DKIM_domain', 'DKIM_selector', 'DKIM_identity'] as $name) {
                if (!is_string($this->{$name}) || preg_match('/[\r\n\x00]/', $this->{$name})) {
                    throw new Exception(self::lang('signing') . 'Invalid DKIM header setting.', self::STOP_CRITICAL);
                }
            }
            if (trim($this->DKIM_domain) === '' || trim($this->DKIM_selector) === ''
                || (($this->DKIM_private_string ?? '') !== '' ? !is_string($this->DKIM_private_string)
                    : (!is_string($this->DKIM_private) || !parent::fileIsAccessible($this->DKIM_private)))) {
                throw new Exception(self::lang('signing') . 'DKIM requires a domain, selector and readable private key.', self::STOP_CRITICAL);
            }
        }
        if ($smime) {
            foreach (['sign_cert_file', 'sign_key_file', 'sign_extracerts_file'] as $name) {
                if ($name === 'sign_extracerts_file' && ($this->{$name} ?? '') === '') {
                    continue;
                }
                if (!is_string($this->{$name}) || !parent::fileIsAccessible($this->{$name})) {
                    throw new Exception(self::lang('signing') . 'S/MIME requires readable local certificate and key files.', self::STOP_CRITICAL);
                }
            }
        }
    }

    /** Use upstream RSA signing with the same validated key bytes; an EC signature cannot be labelled rsa-sha256. */
    public function DKIM_Sign($signHeader)
    {
        $inline = $this->DKIM_private_string;
        if (($inline ?? '') === '') {
            if (!is_string($this->DKIM_private) || !parent::fileIsAccessible($this->DKIM_private)) {
                throw new Exception(self::lang('signing') . 'DKIM private key is not readable.', self::STOP_CRITICAL);
            }
            $bytes = file_get_contents($this->DKIM_private);
        } else {
            $bytes = $inline;
        }
        $key = openssl_pkey_get_private($bytes, $this->DKIM_passphrase);
        if ($key === false || openssl_pkey_get_details($key)['type'] !== OPENSSL_KEYTYPE_RSA) {
            throw new Exception(self::lang('signing') . 'DKIM rsa-sha256 requires a valid RSA private key.', self::STOP_CRITICAL);
        }
        $this->DKIM_private_string = $bytes;
        try {
            $signature = parent::DKIM_Sign($signHeader);
            if ($signature === '') {
                throw new Exception(self::lang('signing') . 'DKIM did not produce a signature.', self::STOP_CRITICAL);
            }
            $this->dkimSigned = true;
            return $signature;
        } finally {
            $this->DKIM_private_string = $inline;
        }
    }

    /** Expose validation failure state before upstream preSend() resets its counter. */
    public function hasBuildError(): bool
    {
        return $this->error_count > 0;
    }

    /** Select upstream's EAI validator per address without relaxing a caller's explicit custom validator. */
    public static function validateAddress($address, $patternselect = null)
    {
        if ($patternselect === null && parent::$validator === 'php' && preg_match('/[\x80-\xFF]/', $address)) {
            $patternselect = 'eai';
        }
        return parent::validateAddress($address, $patternselect);
    }

    /** Upstream auto-selects EAI globally; contain that synchronous change to this validation call. */
    protected function addAnAddress($kind, $address, $name = '')
    {
        $validator = parent::$validator;
        try {
            return parent::addAnAddress($kind, $address, $name);
        } finally {
            parent::$validator = $validator;
        }
    }

    /** Restore pristine message fields, optionally retaining one helper's compatible SMTP connection. */
    public function resetFrom(self $template, bool $reuseConnection): void
    {
        $smtp = $reuseConnection ? $this->smtp : null;
        try {
            if (!$reuseConnection) {
                try {
                    $this->smtpClose();
                } finally {
                    $this->smtp?->close();
                }
            }
        } finally {
            foreach (get_object_vars($template) as $name => $value) {
                $this->{$name} = $value;
            }
            $this->smtp = $smtp;
        }
    }
}
