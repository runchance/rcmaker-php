<?php

declare(strict_types=1);

namespace RC\Helper\Mailer;

use PHPMailer\PHPMailer\SMTP;

require_once __DIR__ . '/bootstrap.php';

/** Keep native SMTP I/O/parsing while adapting terminal OAuth results and the documented XCLIENT extension. */
final class SmtpClient extends SMTP
{
    private bool $authenticating = false;
    private ?bool $authEndResult = null;

    /** Xtext-encode native attributes and keep reset-sensitive identity fields in the last bounded command. */
    public function xclient(array $vars)
    {
        $encoded = [];
        foreach ($vars as $name => $value) {
            if (!in_array($name, parent::$xclient_allowed_attributes, true)) {
                continue;
            }
            if (!is_scalar($value) && !$value instanceof \Stringable) {
                $this->setError('SMTP XCLIENT attribute must be a string or scalar.');
                return false;
            }
            $value = (string) $value;
            if ($value === '') {
                $this->setError('SMTP XCLIENT attribute cannot be empty.');
                return false;
            }
            $encoded[$name] = preg_replace_callback('/[^\x21-\x2A\x2C-\x3C\x3E-\x7E]/',
                static fn(array $byte): string => '+' . strtoupper(bin2hex($byte[0])), $value);
            if (strlen($name) + strlen($encoded[$name]) + 9 > 510) {
                $this->setError('SMTP XCLIENT attribute exceeds the command size limit.');
                return false;
            }
        }
        if ($encoded === []) {
            return parent::xclient([]);
        }
        $tail = array_intersect_key($encoded, array_flip(['HELO', 'PROTO', 'LOGIN', 'NAME', 'ADDR']));
        $size = static fn(array $values): int => 7 + array_sum(array_map(
            static fn(string $name, string $value): int => strlen($name) + strlen($value) + 2, array_keys($values), array_values($values)));
        // ADDR can revoke permission; Postfix resets HELO/PROTO/LOGIN unless present in the last command.
        if ($size($tail) > 510 && isset($tail['NAME'])) {
            unset($tail['NAME']);
        }
        if ($size($tail) > 510) {
            $this->setError('SMTP XCLIENT identity cannot fit in a single reset-safe command.');
            return false;
        }
        $groups = []; $group = [];
        foreach (array_diff_key($encoded, $tail) as $name => $value) {
            if ($size($group + [$name => $value]) > 510) {
                $groups[] = $group;
                $group = [];
            }
            $group[$name] = $value;
        }
        if ($size($group + $tail) > 510) {
            $groups[] = $group;
            $group = [];
        }
        $groups[] = $group + $tail;
        foreach ($groups as $attributes) {
            if (!parent::xclient($attributes)) {
                return false;
            }
        }
        return true;
    }

    /** Resume the reset SMTP greeting through native hello parsing, retaining an explicit forwarded protocol. */
    public function helloAfterXclient(string $host, string $protocol = 'ESMTP'): bool
    {
        return strtoupper($protocol) === 'SMTP' ? $this->sendHello('HELO', $host) : parent::hello($host);
    }

    /** Use native authentication and its token provider once; trust the actual terminal AUTH reply. */
    public function authenticate($username, $password, $authtype = null, $OAuth = null)
    {
        $this->authenticating = true;
        $this->authEndResult = null;
        try {
            $authenticated = parent::authenticate($username, $password, $authtype, $OAuth);
            return $this->authEndResult ?? $authenticated;
        } finally {
            $this->authenticating = false;
            $this->authEndResult = null;
        }
    }

    /** Observe native AUTH End; modern XCLIENT accepts 220 and invalidates pre-reset capabilities. */
    protected function sendCommand($command, $commandstring, $expect)
    {
        if ($command === 'XCLIENT') {
            $expect = [220, 250];
        }
        $result = parent::sendCommand($command, $commandstring, $expect);
        if ($command === 'XCLIENT' && $result && substr($this->last_reply, 0, 3) === '220') {
            $this->server_caps = null;
        }
        if ($this->authenticating && $command === 'AUTH End') {
            $this->authEndResult = $result;
        }
        return $result;
    }
}
