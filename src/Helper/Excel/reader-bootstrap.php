<?php

// Let the application's Composer loader take precedence over bundled upstream files.
spl_autoload_register(static function (string $class): void {
    foreach (['FastExcelReader' => 'fast-excel-reader', 'FastExcelHelper' => 'fast-excel-helper'] as $namespace => $package) {
        $prefix = 'avadim\\' . $namespace . '\\';
        if (str_starts_with($class, $prefix)) {
            $file = dirname(__DIR__, 2) . '/ThirdParty/' . $package . '/src/' . $namespace . '/'
                . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
            return;
        }
    }
});
