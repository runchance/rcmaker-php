<?php

require_once __DIR__ . '/bootstrap.php';

if (!class_exists('RC\\Helper\\Mailer\\PHPMailer', false)) {
    class_alias(\PHPMailer\PHPMailer\PHPMailer::class, 'RC\\Helper\\Mailer\\PHPMailer');
}
