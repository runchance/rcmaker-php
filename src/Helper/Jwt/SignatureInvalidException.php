<?php

namespace RC\Helper\Jwt;

require_once __DIR__ . '/bootstrap.php';

// Preserve the RC class name while upstream classes remain unmodified.
if (!class_exists(__NAMESPACE__ . '\\SignatureInvalidException', false)) {
    class_alias(\Firebase\JWT\SignatureInvalidException::class, __NAMESPACE__ . '\\SignatureInvalidException');
}
