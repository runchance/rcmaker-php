<?php

require_once __DIR__ . '/bootstrap.php';

if (!class_exists('RC\\Helper\\Mailer\\SMTP', false)) {
    class_alias(\PHPMailer\PHPMailer\SMTP::class, 'RC\\Helper\\Mailer\\SMTP');
}
