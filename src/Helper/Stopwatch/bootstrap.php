<?php

declare(strict_types=1);

namespace RC\Helper\Stopwatch;

// Only ResetInterface is needed by this component; other Service Contracts remain Composer-only.
if (!interface_exists(\Symfony\Contracts\Service\ResetInterface::class)) {
    require_once dirname(__DIR__, 2) . '/ThirdParty/symfony-service-contracts/ResetInterface.php';
}
if (!class_exists(\Symfony\Component\Stopwatch\Stopwatch::class)) {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Symfony\\Component\\Stopwatch\\';
        if (str_starts_with($class, $prefix)) {
            $name = substr($class, strlen($prefix));
            if (in_array($name, ['Stopwatch', 'StopwatchEvent', 'StopwatchPeriod', 'Section'], true)) {
                require_once dirname(__DIR__, 2) . '/ThirdParty/symfony-stopwatch/' . $name . '.php';
            }
        }
    });
}
if (!method_exists(\Symfony\Component\Stopwatch\Stopwatch::class, 'getRootSectionEvents')) {
    throw new \RuntimeException('RCMAKER requires compatible stable symfony/stopwatch 8.1.x.');
}
foreach (['Stopwatch', 'StopwatchEvent', 'StopwatchPeriod', 'Section'] as $name) {
    $legacy = __NAMESPACE__ . '\\' . $name;
    if (!class_exists($legacy, false)) {
        class_alias('Symfony\\Component\\Stopwatch\\' . $name, $legacy);
    }
}
