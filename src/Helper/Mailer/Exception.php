<?php

require_once __DIR__ . '/bootstrap.php';

if (!class_exists('RC\\Helper\\Mailer\\Exception', false)) {
    class_alias(\PHPMailer\PHPMailer\Exception::class, 'RC\\Helper\\Mailer\\Exception');
}
