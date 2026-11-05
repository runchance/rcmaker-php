<?php

// Compatible Composer classes take precedence; the fallback is the unchanged stable upstream source.
if (!class_exists(\JasonGrimes\Paginator::class)) {
    require_once dirname(__DIR__, 2) . '/ThirdParty/php-paginator/src/JasonGrimes/Paginator.php';
}
