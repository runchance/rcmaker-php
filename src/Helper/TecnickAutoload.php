<?php

// Composer takes precedence; barcode and PDF adapters share the same lazy pristine-source fallback.
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Com\\Tecnick\\')) {
        return;
    }
    $relative = substr($class, 12);
    $prefixes = [
        'Pdf\\Encrypt\\' => 'tc-lib-pdf-encrypt',
        'Pdf\\Filter\\' => 'tc-lib-pdf-filter',
        'Pdf\\Font\\' => 'tc-lib-pdf-font',
        'Pdf\\Graph\\' => 'tc-lib-pdf-graph',
        'Pdf\\Image\\' => 'tc-lib-pdf-image',
        'Pdf\\Page\\' => 'tc-lib-pdf-page',
        'Pdf\\Parser\\' => 'tc-lib-pdf-parser',
        'Pdf\\Sign\\' => 'tc-lib-pdf-sign',
        'Unicode\\Data\\' => 'tc-lib-unicode-data',
        'Unicode\\' => 'tc-lib-unicode',
        'Barcode\\' => 'tc-lib-barcode',
        'Color\\' => 'tc-lib-color',
        'File\\' => 'tc-lib-file',
        'Pdf\\' => 'tc-lib-pdf',
    ];
    foreach ($prefixes as $prefix => $package) {
        if (!str_starts_with($relative, $prefix)) {
            continue;
        }
        $file = dirname(__DIR__) . '/ThirdParty/' . $package . '/src/'
            . str_replace('\\', '/', substr($relative, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
});
