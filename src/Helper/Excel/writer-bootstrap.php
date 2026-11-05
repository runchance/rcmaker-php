<?php

// Composer wins; bundled upstream code stays unmodified and also loads inside PHAR.
if (!class_exists(\XLSXWriter::class)) {
    require_once dirname(__DIR__, 2) . '/ThirdParty/php-xlsxwriter/xlsxwriter.class.php';
}

// Retain the historical internal type name used by existing writer subclasses.
if (!class_exists('RC\\Helper\\XLSXWriter_BuffererWriter', false)) {
    class_alias(\XLSXWriter_BuffererWriter::class, 'RC\\Helper\\XLSXWriter_BuffererWriter');
}
