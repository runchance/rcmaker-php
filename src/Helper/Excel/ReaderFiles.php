<?php

namespace RC\Helper\Excel;

/** Local import policy. Pass overrides to XlsxReader::open($path, $options). */
final class ReaderFiles
{
    /** XML roles consumed by the bundled parser/adapters even when the target has no XML extension. */
    private const XML_RELATIONS = [
        'officedocument', 'styles', 'sharedstrings', 'theme', 'sheetmetadata', 'richvaluerel',
        'rdrichvalue', 'richvalue', 'rdrichvaluestructure', 'richvaluestructure', 'drawing', 'vmldrawing',
        'comments', 'table', 'calcchain', 'connections', 'pivotcachedefinition', 'pivotcacherecords',
        'pivottable', 'externallink', 'customxml', 'customxmlprops',
        'core-properties', 'extended-properties', 'custom-properties',
    ];

    public const DEFAULTS = [
        'max_file_bytes' => 268435456,
        'max_uncompressed_bytes' => 1073741824,
        'max_metadata_bytes' => 67108864,
        'max_entries' => 10000,
    ];

    /** Validate per-import limits without changing process-wide settings. */
    public static function options(array $options): array
    {
        $options += self::DEFAULTS;
        foreach (self::DEFAULTS as $key => $default) {
            if (!is_int($options[$key]) || $options[$key] < 1) {
                throw new \InvalidArgumentException($key . ' must be a positive integer.');
            }
        }
        return $options;
    }

    /** Create a private local temporary file; never fall back to the application directory. */
    public static function temporary(?string $directory = null): string
    {
        $directory ??= sys_get_temp_dir();
        if (str_contains($directory, '://') || !($directory = realpath($directory)) || !is_dir($directory) || !is_writable($directory)) {
            throw new \RuntimeException('Reader temporary directory must be a writable local directory.');
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            // Native creation is private from the first open, without changing the process-wide umask.
            $file = @tempnam($directory, 'rc_reader_');
            if ($file === false) {
                throw new \RuntimeException('Cannot allocate a reader temporary file.');
            }
            try {
                if (realpath(dirname($file)) !== $directory) {
                    throw new \RuntimeException('Reader temporary allocation escaped the requested directory.');
                }
                if (!@chmod($file, 0600)) {
                    throw new \RuntimeException('Cannot restrict temporary spreadsheet permissions.');
                }
            } catch (\Throwable $error) {
                @unlink($file);
                throw $error;
            }
            return $file;
        }
        $file = $directory . DIRECTORY_SEPARATOR . 'rc_reader_' . bin2hex(random_bytes(16));
        $handle = @fopen($file, 'x+b');
        if ($handle === false) {
            throw new \RuntimeException('Cannot allocate a reader temporary file.');
        }
        fclose($handle);
        return $file;
    }

    /** Check archive metadata before XML parsing; no files are extracted to application paths. */
    public static function checkZip(string $file, array $options): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($file, \ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException('Cannot open XLSX archive.');
        }
        try {
            if ($zip->numFiles > $options['max_entries']) {
                throw new \LengthException('XLSX archive has too many entries.');
            }
            $size = 0;
            $names = [];
            $large = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if ($stat === false) {
                    throw new \RuntimeException('Cannot inspect XLSX archive entry.');
                }
                $name = $stat['name'];
                $key = strtolower($name);
                if (isset($names[$key]) || str_contains($name, '\\') || str_starts_with($name, '/')
                    || preg_match('~(^|/)\.\.(/|$)|[\x00-\x1f:]~', $name)) {
                    throw new \RuntimeException('Ambiguous or unsafe XLSX archive entry.');
                }
                $names[$key] = $index;
                $size += $stat['size'];
                if ($size > $options['max_uncompressed_bytes']) {
                    throw new \LengthException('XLSX uncompressed content exceeds the import limit.');
                }
                if ($stat['size'] > $options['max_metadata_bytes']) {
                    // Native getProperties() reads these fixed paths even without a metadata relationship.
                    if (str_ends_with($key, '.rels') || in_array($key, ['[content_types].xml', 'xl/workbook.xml', 'docprops/core.xml', 'docprops/app.xml'], true)) {
                        throw new \LengthException('XLSX metadata exceeds the import limit.');
                    }
                    $large[$key] = $name;
                }
            }
            if ($large !== []) {
                self::checkLargeParts($zip, $names, $large);
            }
        } finally {
            $zip->close();
        }
    }

    /** Resolve oversized parts by their actual relationships/types, never by a worksheet directory prefix. */
    private static function checkLargeParts(\ZipArchive $zip, array $names, array $large): void
    {
        $sheets = $metadata = $defaults = $overrides = [];
        if (isset($names['[content_types].xml'])) {
            self::inspectXml($zip, $zip->getNameIndex($names['[content_types].xml']), static function (\XMLReader $reader) use (&$defaults, &$overrides): void {
                if ($reader->nodeType !== \XMLReader::ELEMENT) { return; }
                if ($reader->localName === 'Default') {
                    $defaults[strtolower((string)$reader->getAttribute('Extension'))] = strtolower((string)$reader->getAttribute('ContentType'));
                } elseif ($reader->localName === 'Override') {
                    $part = \avadim\FastExcelReader\Internal\PackagePath::resolve('_package.xml', (string)$reader->getAttribute('PartName'));
                    $overrides[strtolower($part)] = strtolower((string)$reader->getAttribute('ContentType'));
                }
            });
        }
        foreach ($names as $key => $index) {
            if (!str_ends_with($key, '.rels') || basename(dirname($key)) !== '_rels') { continue; }
            $part = $zip->getNameIndex($index);
            $source = $key === '_rels/.rels' ? '_package.xml'
                : \avadim\FastExcelReader\Internal\PackagePath::resolve('_package.xml', dirname(dirname($part)) . '/' . substr(basename($part), 0, -5));
            self::inspectXml($zip, $part, static function (\XMLReader $reader) use ($source, $large, &$sheets, &$metadata): void {
                if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'Relationship'
                    || strcasecmp((string)$reader->getAttribute('TargetMode'), 'External') === 0) { return; }
                $type = strtolower(basename((string)$reader->getAttribute('Type')));
                if (($type !== 'worksheet' || strcasecmp($source, 'xl/workbook.xml') !== 0) && !in_array($type, self::XML_RELATIONS, true)) { return; }
                $target = strtolower(\avadim\FastExcelReader\Internal\PackagePath::resolve($source, (string)$reader->getAttribute('Target')));
                if (!isset($large[$target])) { return; }
                if ($type === 'worksheet') { $sheets[$target] = true; }
                else { $metadata[$target] = true; }
            });
        }
        foreach ($large as $key => $name) {
            $type = $overrides[$key] ?? $defaults[strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? '';
            $xmlType = !str_starts_with($type, 'image/') && (str_ends_with($type, '+xml') || $type === 'application/xml' || $type === 'text/xml');
            $specificMetadata = $xmlType && !in_array($type, ['application/xml', 'text/xml', 'application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml'], true);
            if (isset($metadata[$key]) || $specificMetadata || (!isset($sheets[$key]) && ($xmlType || str_ends_with($key, '.xml')))) {
                throw new \LengthException('XLSX metadata exceeds the import limit.');
            }
        }
    }

    /** Inspect bounded package XML through a shared ZIP handle; never materialize it or enable DTD/entity loading. */
    private static function inspectXml(\ZipArchive $zip, string $part, callable $visit): void
    {
        $stream = $zip->getStream($part);
        if ($stream === false) { throw new \RuntimeException('Cannot read XLSX package metadata.'); }
        $previous = libxml_use_internal_errors(true);
        $before = $previous ? count(libxml_get_errors()) : 0;
        $reader = null;
        $failure = null;
        try {
            $flags = LIBXML_NONET | LIBXML_COMPACT;
            if (defined('LIBXML_NO_XXE')) { $flags |= LIBXML_NO_XXE; }
            $reader = \XMLReader::fromStream($stream, null, $flags);
            foreach ([\XMLReader::LOADDTD, \XMLReader::VALIDATE, \XMLReader::SUBST_ENTITIES] as $property) {
                if (!$reader->setParserProperty($property, false)) { throw new \RuntimeException('Cannot restrict XLSX metadata parsing.'); }
            }
            while ($reader->read()) {
                if (in_array($reader->nodeType, [\XMLReader::DOC_TYPE, \XMLReader::ENTITY, \XMLReader::ENTITY_REF], true)) {
                    throw new \RuntimeException('DTD and entity declarations are not allowed in XLSX files.');
                }
                $visit($reader);
            }
            $errors = libxml_get_errors();
            for ($index = $before, $count = count($errors); $index < $count; $index++) {
                if ($errors[$index]->level >= LIBXML_ERR_ERROR) { throw new \RuntimeException('Invalid XLSX package metadata XML.'); }
            }
        } catch (\Throwable $error) { $failure = $error; }
        finally {
            try { $reader?->close(); } catch (\Throwable $error) { $failure ??= $error; }
            try { if (is_resource($stream)) { fclose($stream); } } catch (\Throwable $error) { $failure ??= $error; }
            libxml_use_internal_errors($previous);
        }
        if ($failure !== null) { throw $failure; }
    }
}
