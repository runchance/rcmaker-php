<?php

// Composer wins; bundled PHPMailer is loaded only when a mail component is first used.
if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'PHPMailer\\PHPMailer\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $name = substr($class, strlen($prefix));
        if (in_array($name, ['PHPMailer', 'SMTP', 'POP3', 'Exception', 'OAuth', 'OAuthTokenProvider', 'DSNConfigurator'], true)) {
            require_once dirname(__DIR__, 2) . '/ThirdParty/phpmailer/src/' . $name . '.php';
        }
    });
}

// Catch clauses do not autoload aliases; install the historical exception name before throwing.
if (!class_exists('RC\\Helper\\Mailer\\Exception', false)) {
    class_alias(\PHPMailer\PHPMailer\Exception::class, 'RC\\Helper\\Mailer\\Exception');
}
