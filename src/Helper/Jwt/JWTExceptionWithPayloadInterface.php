<?php

namespace RC\Helper\Jwt;

require_once __DIR__ . '/bootstrap.php';

class_alias(\Firebase\JWT\JWTExceptionWithPayloadInterface::class, __NAMESPACE__ . '\\JWTExceptionWithPayloadInterface');
