<?php

require_once __DIR__ . '/bootstrap.php';

if (!interface_exists('RC\\Helper\\Mailer\\OAuthTokenProvider', false)) {
    class_alias(\PHPMailer\PHPMailer\OAuthTokenProvider::class, 'RC\\Helper\\Mailer\\OAuthTokenProvider');
}
