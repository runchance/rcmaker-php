<?php

require_once __DIR__ . '/bootstrap.php';

if (!class_exists('RC\\Helper\\Mailer\\OAuth', false)) {
    class_alias(\PHPMailer\PHPMailer\OAuth::class, 'RC\\Helper\\Mailer\\OAuth');
}
