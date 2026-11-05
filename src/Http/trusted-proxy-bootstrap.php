<?php

declare(strict_types=1);

// Composer wins; the bundled fallback contains only the unchanged subnet utility and its MIT license.
if (!class_exists(\Symfony\Component\HttpFoundation\IpUtils::class)) {
    require_once dirname(__DIR__) . '/ThirdParty/symfony-http-foundation/IpUtils.php';
}
