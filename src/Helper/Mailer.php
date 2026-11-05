<?php

declare(strict_types=1);

namespace RC\Helper;

use InvalidArgumentException;
use LogicException;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\OAuthTokenProvider;
use PHPMailer\PHPMailer\PHPMailer;
use RC\Helper\Mailer\Transport;

require_once __DIR__ . '/Mailer/bootstrap.php';

/** Build and send independent messages; retain this helper explicitly when sending a batch. */
class Mailer
{
    public static $_version = '7.1.1';
    protected array $config = [];
    protected Transport $PHPMailer;
    private Transport $template;
    protected string $lastError = '';
    private bool $started = false;
    private bool $sending = false;
    private bool $nativeAccessed = false;

    /** Configuration accepts public upstream options plus Mailer, Exception, Language and OAuth. */
    public function __construct(#[\SensitiveParameter] array $config = [])
    {
        $this->initMailer($config);
    }

    /** Validate a new configuration completely before replacing a usable mailer. */
    public function initMailer(#[\SensitiveParameter] array $config): void
    {
        $this->assertIdle();
        $transport = strtolower((string) ($config['Mailer'] ?? 'mail'));
        if (!in_array($transport, ['mail', 'smtp', 'sendmail', 'qmail'], true)) {
            throw new InvalidArgumentException('Unknown mail transport: ' . $transport);
        }
        static $properties = null;
        if ($properties === null) {
            $properties = [];
            foreach ((new \ReflectionClass(PHPMailer::class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                if (!$property->isStatic()) {
                    $properties[$property->getName()] = true;
                }
            }
        }
        foreach ($config as $name => $value) {
            if (!in_array($name, ['Mailer', 'Exception', 'Language', 'OAuth'], true)
                && (!isset($properties[$name]) || in_array($name, ['ErrorInfo', 'UseSMTPUTF8'], true))) {
                throw new InvalidArgumentException('Unknown or non-configurable mail option: ' . $name);
            }
        }
        $template = new Transport((bool) ($config['Exception'] ?? false));
        $template->Mailer = $transport;
        $template->isHTML(true);
        $template->CharSet = 'utf-8';
        $template->Debugoutput = 'error_log';
        $template->configureLanguage((string) ($config['Language'] ?? 'zh_cn'));
        foreach ($config as $name => $value) {
            if ($name === 'OAuth') {
                if (!$value instanceof OAuthTokenProvider) {
                    throw new InvalidArgumentException('OAuth must implement PHPMailer OAuthTokenProvider.');
                }
                $template->setOAuth($value);
            } elseif (!in_array($name, ['Mailer', 'Exception', 'Language'], true)) {
                $template->{$name} = $value;
            }
        }
        if (isset($this->PHPMailer)) {
            $this->PHPMailer->smtpClose();
        }
        $this->config = $config;
        static::$_version = PHPMailer::VERSION;
        $this->template = $template;
        $this->PHPMailer = clone $template;
        $this->lastError = '';
        $this->started = $this->nativeAccessed = false;
    }

    /** Attach one path, a list of paths, or a name => path map. */
    public function addAttachment($path, $attachmentName = ''): static
    {
        $this->beginMessage();
        foreach (is_array($path) ? $path : [$path] as $name => $file) {
            $this->apply('addAttachment', [$file, is_int($name) ? $attachmentName : $name]);
        }
        return $this;
    }

    /** Alias of addAttachment(). */
    public function a($path, $attachmentName = ''): static { return $this->addAttachment($path, $attachmentName); }

    /** Add one recipient, a list, or a display-name => address map. */
    public function to($email, $toName = ''): static
    {
        $this->beginMessage();
        foreach (is_array($email) ? $email : [$email] as $name => $address) {
            $this->apply('addAddress', [$address, is_int($name) ? $toName : $name]);
        }
        return $this;
    }

    /** Alias of to(). */
    public function t($email, $toName = ''): static { return $this->to($email, $toName); }

    /** Set the message sender; invalid addresses invalidate this message. */
    public function from($email, $fromName = ''): static
    {
        $this->beginMessage();
        $this->apply('setFrom', [$email, $fromName]);
        return $this;
    }

    /** Alias of from(). */
    public function f($email, $fromName = ''): static { return $this->from($email, $fromName); }

    /** Set the subject, including the valid literal value "0". */
    public function subject($subject): static
    {
        $this->beginMessage();
        $this->PHPMailer->Subject = (string) $subject;
        return $this;
    }

    /** Alias of subject(). */
    public function sb($subject): static { return $this->subject($subject); }

    /** With an argument set the subject; without arguments send the message. */
    public function s($subject = null): static|bool
    {
        return func_num_args() > 0 ? $this->subject($subject) : $this->send();
    }

    /** Set literal body content without parsing HTML or reading embedded files. */
    public function body($body): static
    {
        $this->beginMessage();
        $this->PHPMailer->Body = (string) $body;
        return $this;
    }

    /** Alias of body(). */
    public function b($body): static { return $this->body($body); }

    /** Select HTML or plain text for this message only. */
    public function isHtml($bool = true): static
    {
        $this->beginMessage();
        $this->PHPMailer->isHTML((bool) $bool);
        return $this;
    }

    /** Alias of isHtml(). */
    public function ih($bool = true): static { return $this->isHtml($bool); }

    /** Add a visible copy recipient and optional display name. */
    public function cc($email, $name = ''): static
    {
        $this->beginMessage();
        $this->apply('addCC', [$email, $name]);
        return $this;
    }

    /** Add a hidden copy recipient and optional display name. */
    public function bcc($email, $name = ''): static
    {
        $this->beginMessage();
        $this->apply('addBCC', [$email, $name]);
        return $this;
    }

    /** Use upstream HTML conversion; basedir must be a trusted application asset directory. */
    public function msgHTML($message, $basedir = ''): static
    {
        $this->beginMessage();
        $this->apply('msgHTML', [$message, $basedir]);
        return $this;
    }

    /** Alias of msgHTML(). */
    public function mh($message, $basedir = ''): static { return $this->msgHTML($message, $basedir); }

    /** Access upstream reply-to, custom headers, string attachments, signing and other advanced APIs. */
    public function getMailer(): PHPMailer
    {
        $this->beginMessage();
        // Native code may change hosts/authentication; never carry that connection into a later message.
        $this->nativeAccessed = true;
        return $this->PHPMailer;
    }

    /** Last construction/send error; capture this from the same helper used to send. */
    public function getError(): string
    {
        return $this->PHPMailer->ErrorInfo !== '' ? $this->PHPMailer->ErrorInfo : $this->lastError;
    }

    /** Alias of getError(). */
    public function e(): string { return $this->getError(); }

    /** Return false on delivery failure, preserving the error while clearing message state in all cases. */
    public function send(): bool
    {
        $this->assertIdle();
        $this->sending = true;
        $sent = false;
        try {
            if (!$this->PHPMailer->hasBuildError()) {
                $sent = (bool) $this->PHPMailer->send();
            }
        } catch (\Throwable $error) {
            $this->lastError = $this->PHPMailer->ErrorInfo ?: $error->getMessage();
        } finally {
            if ($this->PHPMailer->ErrorInfo !== '') {
                $this->lastError = $this->PHPMailer->ErrorInfo;
            } elseif ($sent) {
                $this->lastError = '';
            }
            try {
                $this->resetMailer($sent && !$this->nativeAccessed && $this->template->SMTPKeepAlive);
            } catch (\Throwable $error) {
                $sent = false;
                $this->lastError = $this->lastError ?: $error->getMessage();
            } finally {
                $this->sending = false;
            }
        }
        return $sent;
    }

    /** Discard a draft and explicitly close a retained connection before releasing a batch helper. */
    public function close(): void
    {
        $this->assertIdle();
        $this->resetMailer();
        $this->lastError = '';
    }

    /** Create a fresh helper from the current connection configuration, never from a pending draft. */
    public function instance(): static { return new static($this->config); }

    /** Cloning creates an independent configured draft, with no SMTP connection or recipients. */
    public function __clone()
    {
        $this->assertIdle();
        $this->template = clone $this->template;
        $this->PHPMailer = clone $this->template;
        $this->lastError = '';
        $this->started = $this->nativeAccessed = false;
    }

    /** Restore all native message fields without reparsing configuration or translation files. */
    protected function resetMailer(bool $reuseConnection = false): void
    {
        $this->PHPMailer->resetFrom($this->template, $reuseConnection);
        $this->started = $this->nativeAccessed = false;
    }

    /** Start a new draft after a completed send, but preserve errors within the same draft. */
    private function beginMessage(): void
    {
        $this->assertIdle();
        if (!$this->started) {
            $this->lastError = '';
            $this->started = true;
        }
    }

    /** Remember localized validation errors even when the configured native exception is rethrown. */
    private function apply(string $method, array $arguments): void
    {
        try {
            $this->PHPMailer->{$method}(...$arguments);
        } catch (Exception $error) {
            $this->lastError = $this->PHPMailer->ErrorInfo ?: $error->getMessage();
            throw new Exception($this->lastError, (int) $error->getCode(), $error);
        }
    }

    /** Reject reentrant mutation while a callback or coroutine is already sending this object. */
    private function assertIdle(): void
    {
        if ($this->sending) {
            throw new LogicException('This mailer is sending; use another mailer() instance for concurrent work.');
        }
    }
}
