<?php

require_once __DIR__ . '/autoload.php';

// Configuration is process-wide in TCPDF; request-specific access rules live in Document.
if (!defined('K_TCPDF_EXTERNAL_CONFIG')) {
    define('K_TCPDF_EXTERNAL_CONFIG', true);
}
if (!defined('K_TCPDF_THROW_EXCEPTION_ERROR')) {
    define('K_TCPDF_THROW_EXCEPTION_ERROR', true);
}
if (!defined('K_PATH_FONTS')) {
    define('K_PATH_FONTS', dirname(__DIR__, 2) . '/ThirdParty/tc-lib-pdf-font/fonts/');
}

// Upstream autoconfiguration rewrites these server keys even in a resident worker.
$rcPdfServer = [];
foreach (['DOCUMENT_ROOT', 'PHP_SELF', 'SCRIPT_FILENAME'] as $rcPdfKey) {
    $rcPdfServer[$rcPdfKey] = [array_key_exists($rcPdfKey, $_SERVER), $_SERVER[$rcPdfKey] ?? null];
}
$_SERVER['PHP_SELF'] ??= '';
$_SERVER['SCRIPT_FILENAME'] ??= __FILE__;
try {
    if (!class_exists('TCPDF')) {
        require_once dirname(__DIR__, 2) . '/ThirdParty/tcpdf/tcpdf.php';
    }
    if (!method_exists('TCPDF', 'engineInit')) {
        throw new RuntimeException('RC PDF requires tecnickcom/tcpdf ^7.0.21; remove the older preloaded TCPDF.');
    }
} finally {
    foreach ($rcPdfServer as $rcPdfKey => [$rcPdfExists, $rcPdfValue]) {
        if ($rcPdfExists) {
            $_SERVER[$rcPdfKey] = $rcPdfValue;
        } else {
            unset($_SERVER[$rcPdfKey]);
        }
    }
    unset($rcPdfServer, $rcPdfKey, $rcPdfExists, $rcPdfValue);
}
