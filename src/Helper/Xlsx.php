<?php

namespace RC\Helper;

use RC\Helper\Excel\WriterBuffer;

require_once __DIR__ . '/Excel/writer-bootstrap.php';

/**
 * Streaming XLSX exports backed by pristine PHP_XLSXWriter 0.39.
 * Use xlsx()->writeSheetRow('Data', $row), then writeToFile($absolutePath).
 * Use explicit string columns or setAutoFormula(false) for untrusted text.
 */
class Xlsx extends \XLSXWriter
{
    private bool $autoFormula = true;
    private bool $closed = false;
    private bool $closing = false;
    private bool $failed = false;
    private array $sheetNames = [];
    private array $mergeBounds = [];

    /** Check optional extensions only when the Excel writer is actually requested. */
    public function __construct()
    {
        foreach (['zip', 'dom', 'mbstring'] as $extension) {
            if (!extension_loaded($extension)) {
                throw new \RuntimeException('XLSX exports require the PHP ' . $extension . ' extension.');
            }
        }
        parent::__construct();
    }

    /** Disable automatic leading-equals formulas when exporting user-supplied data. */
    public function setAutoFormula(bool $enabled): self
    {
        $this->autoFormula = $enabled;
        return $this;
    }

    /** Select an existing writable filesystem directory, never a PHAR member. */
    public function setTempDir($tempdir = '')
    {
        $this->assertOpen();
        $directory = $tempdir === '' ? sys_get_temp_dir() : $tempdir;
        if (!is_string($directory) || str_contains($directory, "\0") || str_contains($directory, '://')) {
            throw new \InvalidArgumentException('The XLSX temporary directory must be a local filesystem path.');
        }
        $resolved = realpath($directory);
        if ($resolved === false || !is_dir($resolved) || !is_writable($resolved)) {
            throw new \RuntimeException('The XLSX temporary directory does not exist or is not writable.');
        }
        $this->tempdir = $resolved;
    }

    /** Allocate private temporary files outside the package, including PHAR execution. */
    protected function tempFilename()
    {
        $directory = $this->tempdir ?: sys_get_temp_dir();
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new \RuntimeException('The XLSX temporary directory is not writable.');
        }
        $file = @tempnam($directory, 'xlsx_writer_');
        if ($file === false) {
            throw new \RuntimeException('Unable to create an XLSX temporary file.');
        }
        if (realpath(dirname($file)) !== realpath($directory)) {
            @unlink($file);
            throw new \RuntimeException('The XLSX temporary directory became unavailable.');
        }
        $this->temp_files[] = $file;
        return $file;
    }

    /** Preserve the RC header API, including boolean suppress_row and duplicate no-op. */
    public function writeSheetHeader($sheet_name, array $header_types, $col_options = null)
    {
        $key = $this->writableSheetKey($sheet_name);
        if (isset($this->sheets[$key]) || $header_types === []) {
            return;
        }
        if (count($header_types) > self::EXCEL_2007_MAX_COL) {
            throw new \InvalidArgumentException('An XLSX sheet cannot exceed 16384 columns.');
        }
        foreach ($header_types as $label => $format) {
            $this->validateValue((string) $label, 'n_string');
            if (!is_string($format) || $format === '' || !mb_check_encoding($format, 'UTF-8')) {
                throw new \InvalidArgumentException('XLSX column formats must be nonempty UTF-8 strings.');
            }
        }
        $options = is_bool($col_options) ? ['suppress_row' => $col_options] : ($col_options ?? []);
        $this->validateOptions($options, count($header_types));
        try {
            $this->initializeSheet($key, $options['widths'] ?? [], $options['auto_filter'] ?? false,
                $options['freeze_rows'] ?? false, $options['freeze_columns'] ?? false);
            parent::writeSheetHeader($key, $header_types, $options);
        } catch (\Throwable $error) {
            $this->failed = true;
            throw $error;
        }
    }

    /** Validate before appending, then retain the upstream streaming row implementation. */
    public function writeSheetRow($sheet_name, array $row, $row_options = null)
    {
        $key = $this->writableSheetKey($sheet_name);
        if (count($row) > self::EXCEL_2007_MAX_COL) {
            throw new \InvalidArgumentException('An XLSX sheet cannot exceed 16384 columns.');
        }
        if ($row_options !== null && $row_options !== []) {
            $this->validateOptions($row_options, count($row));
        }
        $columns = $this->sheets[$key]->columns ?? [];
        $column = 0;
        foreach ($row as $value) {
            $this->validateValue($value, $columns[$column++]['number_format_type'] ?? 'n_auto');
        }
        if (($this->sheets[$key]->row_count ?? 0) >= self::EXCEL_2007_MAX_ROW) {
            throw new \InvalidArgumentException('An XLSX sheet cannot exceed 1048576 rows.');
        }
        $width = max(count($columns), count($row));
        try {
            parent::writeSheetRow($key, $row, $row_options);
            // Upstream appends a full row of column definitions when only the delta is missing.
            if (count($this->sheets[$key]->columns) > $width) {
                $this->sheets[$key]->columns = array_slice($this->sheets[$key]->columns, 0, $width);
            }
        } catch (\Throwable $error) {
            $this->failed = true;
            throw $error;
        }
    }

    /** Expose actual data rows, never rows added only to calculate merged dimensions. */
    public function countSheetRows($sheet_name = '')
    {
        $key = $sheet_name === '' ? $this->current_sheet : self::sheetKey($sheet_name);
        return $key === null ? 0 : ($this->sheets[$key]->row_count ?? 0);
    }

    /** Write a complete sheet using the legacy convenience API, including the name "0". */
    public function writeSheet(array $data, $sheet_name = '', array $header_types = [])
    {
        $name = $sheet_name === '' ? 'Sheet1' : $sheet_name;
        if ($header_types !== []) {
            $this->writeSheetHeader($name, $header_types);
        }
        foreach ($data === [] ? [['']] : $data as $row) {
            $this->writeSheetRow($name, $row);
        }
        try {
            $this->finalizeSheet($name);
        } catch (\Throwable $error) {
            $this->failed = true;
            throw $error;
        }
    }

    /** Create the upstream sheet prefix once, then use checked streaming writes for rows. */
    protected function initializeSheet($sheet_name, $col_widths = [], $auto_filter = false, $freeze_rows = false, $freeze_columns = false)
    {
        if (isset($this->sheets[$sheet_name])) {
            return;
        }
        parent::initializeSheet($sheet_name, $col_widths, $auto_filter, $freeze_rows, $freeze_columns);
        $sheet = $this->sheets[$sheet_name];
        $sheet->sheetname = $sheet_name === "\0RC_ZERO" ? '0' : $sheet_name;
        $sheet->file_writer->close();
        $prefix = file_get_contents($sheet->filename);
        if ($prefix === false) {
            throw new \RuntimeException('Unable to read the XLSX worksheet prefix.');
        }
        $document = $this->parseXml($prefix . '</sheetData></worksheet>');
        $columnDefinitions = $document->getElementsByTagName('col');
        $defaultColumn = $columnDefinitions->item($columnDefinitions->length - 1);
        if ((int) $defaultColumn->getAttribute('min') > self::EXCEL_2007_MAX_COL) {
            $defaultColumn->parentNode->removeChild($defaultColumn);
        } else {
            $defaultColumn->setAttribute('max', (string) self::EXCEL_2007_MAX_COL);
        }
        $marker = '<!--RCMAKER_ROWS-->';
        $document->getElementsByTagName('sheetData')->item(0)->appendChild($document->createComment('RCMAKER_ROWS'));
        $serialized = $document->saveXML();
        $prefix = substr($serialized, 0, strpos($serialized, $marker));
        $sheet->max_cell_tag_start = strpos($prefix, '<dimension ');
        $sheet->max_cell_tag_end = strpos($prefix, '/>', $sheet->max_cell_tag_start) + 2;
        $sheet->file_writer = new WriterBuffer($sheet->filename, 'w+b');
        $sheet->file_writer->write($prefix);
        $this->sheetNames[mb_strtolower($sheet->sheetname, 'UTF-8')] = $sheet->sheetname;
    }

    /** Add a zero-based merge range after checking Excel bounds and overlap. */
    public function markMergedCell($sheet_name, $start_cell_row, $start_cell_column, $end_cell_row, $end_cell_column)
    {
        $key = $this->writableSheetKey($sheet_name);
        foreach ([$start_cell_row, $end_cell_row] as $row) {
            if (!is_int($row) || $row < 0 || $row >= self::EXCEL_2007_MAX_ROW) {
                throw new \InvalidArgumentException('Merged row coordinates must be zero-based Excel row numbers.');
            }
        }
        foreach ([$start_cell_column, $end_cell_column] as $column) {
            if (!is_int($column) || $column < 0 || $column >= self::EXCEL_2007_MAX_COL) {
                throw new \InvalidArgumentException('Merged column coordinates must be zero-based Excel column numbers.');
            }
        }
        if ($start_cell_row > $end_cell_row || $start_cell_column > $end_cell_column) {
            throw new \InvalidArgumentException('A merged range must start before it ends.');
        }
        foreach ($this->mergeBounds[$key] ?? [] as [$top, $left, $bottom, $right]) {
            if ($start_cell_row <= $bottom && $end_cell_row >= $top && $start_cell_column <= $right && $end_cell_column >= $left) {
                throw new \InvalidArgumentException('Merged XLSX ranges cannot overlap.');
            }
        }
        try {
            $this->initializeSheet($key);
        } catch (\Throwable $error) {
            $this->failed = true;
            throw $error;
        }
        parent::markMergedCell($key, $start_cell_row, $start_cell_column, $end_cell_row, $end_cell_column);
        $this->mergeBounds[$key][] = [$start_cell_row, $start_cell_column, $end_cell_row, $end_cell_column];
    }

    /** Finalize with valid empty dimensions, then extend only the dimension for merges. */
    protected function finalizeSheet($sheet_name)
    {
        $key = self::sheetKey($sheet_name);
        $sheet = $this->sheets[$key];
        if ($sheet->finalized) {
            return;
        }
        $rows = $sheet->row_count;
        $columns = $sheet->columns;
        try {
            $sheet->row_count = max(1, $rows);
            if ($columns === []) {
                $sheet->columns = [null];
            }
            parent::finalizeSheet($key);
        } finally {
            $sheet->row_count = $rows;
            $sheet->columns = $columns;
        }
        $maxRow = max(0, $rows - 1);
        $maxColumn = max(0, count($columns) - 1);
        foreach ($this->mergeBounds[$key] ?? [] as [, , $bottom, $right]) {
            $maxRow = max($maxRow, $bottom);
            $maxColumn = max($maxColumn, $right);
        }
        if ($this->mergeBounds[$key] ?? []) {
            $tag = '<dimension ref="A1:' . self::xlsCell($maxRow, $maxColumn) . '"/>';
            $buffer = new WriterBuffer($sheet->filename);
            $buffer->fseek($sheet->max_cell_tag_start);
            $buffer->write(str_pad($tag, $sheet->max_cell_tag_end - $sheet->max_cell_tag_start));
            $buffer->close();
        }
    }

    /** Keep string columns literal, booleans typed, and formulas standards-compliant. */
    protected function writeCell(\XLSXWriter_BuffererWriter &$file, $row_number, $column_number, $value, $num_format_type, $cell_style_idx)
    {
        $attributes = ' r="' . self::xlsCell($row_number, $column_number) . '" s="' . $cell_style_idx . '"';
        if ($value === null || $value === '') {
            $file->write('<c' . $attributes . '/>');
        } elseif ($num_format_type === 'n_string' || (!$this->autoFormula && is_string($value) && str_starts_with($value, '='))) {
            $value = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            $file->write('<c' . $attributes . ' t="inlineStr"><is><t xml:space="preserve">' . self::xmlspecialchars($value) . '</t></is></c>');
        } elseif (is_bool($value)) {
            $file->write('<c' . $attributes . ' t="b"><v>' . ($value ? '1' : '0') . '</v></c>');
        } elseif (is_string($value) && str_starts_with($value, '=')) {
            $file->write('<c' . $attributes . '><f>' . self::xmlspecialchars(substr($value, 1)) . '</f></c>');
        } elseif ($num_format_type === 'n_date' || $num_format_type === 'n_datetime') {
            if (preg_match('/^(\d+):(\d{2}):(\d{2}(?:\.\d+)?)$/D', (string) $value, $time)) {
                $serial = ((int) $time[1] * 3600 + (int) $time[2] * 60 + (float) $time[3]) / 86400;
            } else {
                $serial = self::convert_date_time($value);
            }
            $file->write('<c' . $attributes . ' t="n"><v>' . ($num_format_type === 'n_date' ? (int) $serial : $serial) . '</v></c>');
        } elseif ($num_format_type === 'n_numeric' || !is_string($value) || $value === '0'
            || ($value[0] !== '0' && ctype_digit($value)) || preg_match('/^\-?(0|[1-9][0-9]*)(\.[0-9]+)?$/D', $value)) {
            $file->write('<c' . $attributes . ' t="n"><v>' . self::xmlspecialchars($value) . '</v></c>');
        } else {
            $file->write('<c' . $attributes . ' t="inlineStr"><is><t xml:space="preserve">' . self::xmlspecialchars($value) . '</t></is></c>');
        }
    }

    /** Build into a sibling staging file and replace the destination only after ZIP close. */
    public function writeToFile($filename)
    {
        $this->assertOpen();
        if ($this->sheets === []) {
            throw new \LogicException('Cannot export an XLSX workbook without worksheets.');
        }
        if (!is_string($filename) || $filename === '' || str_contains($filename, "\0") || str_contains($filename, '://')) {
            throw new \InvalidArgumentException('XLSX output must be a local filesystem path.');
        }
        $directory = realpath(dirname($filename));
        if ($directory === false || !is_writable($directory) || is_dir($filename) || is_link($filename)
            || (file_exists($filename) && (!is_file($filename) || !is_writable($filename)))) {
            throw new \RuntimeException('The XLSX destination directory is missing or the destination is not writable.');
        }
        $target = $directory . DIRECTORY_SEPARATOR . basename($filename);
        $stage = @tempnam($directory, '.rc-xlsx-');
        if ($stage === false) {
            throw new \RuntimeException('Unable to create an XLSX staging file.');
        }
        if (realpath(dirname($stage)) !== $directory) {
            @unlink($stage);
            throw new \RuntimeException('The XLSX destination directory became unavailable.');
        }
        $this->temp_files[] = $stage;
        $zip = new \ZipArchive();
        $opened = false;
        $styles = null;
        try {
            foreach ($this->sheets as $key => $sheet) {
                try {
                    $this->finalizeSheet($key);
                } catch (\Throwable $error) {
                    $this->failed = true;
                    throw $error;
                }
            }
            $styles = $this->writeStylesXML();
            $result = $zip->open($stage, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
            if ($result !== true) {
                throw new \RuntimeException('Unable to create XLSX ZIP archive (error ' . $result . ').');
            }
            $opened = true;
            foreach ([
                'docProps/app.xml' => $this->buildAppXML(),
                'docProps/core.xml' => $this->buildCoreXML(),
                '_rels/.rels' => $this->buildRelationshipsXML(),
                'xl/workbook.xml' => $this->buildWorkbookXML(),
                '[Content_Types].xml' => $this->buildContentTypesXML(),
                'xl/_rels/workbook.xml.rels' => $this->buildWorkbookRelsXML(),
            ] as $member => $contents) {
                if (!$zip->addFromString($member, $contents)) {
                    throw new \RuntimeException('Unable to add XLSX metadata: ' . $member);
                }
            }
            foreach ($this->sheets as $sheet) {
                if (!$zip->addFile($sheet->filename, 'xl/worksheets/' . $sheet->xmlname)) {
                    throw new \RuntimeException('Unable to add an XLSX worksheet.');
                }
            }
            if (!$zip->addFile($styles, 'xl/styles.xml') || !@$zip->close()) {
                throw new \RuntimeException('Unable to finish the XLSX ZIP archive.');
            }
            $opened = false;
            if (!@rename($stage, $target)) {
                throw new \RuntimeException('Unable to replace the XLSX destination; the previous file has not been removed.');
            }
            $released = $stage;
            $stage = null;
            $this->forgetTemporary($released);
        } finally {
            if ($opened) {
                try {
                    @$zip->close();
                } catch (\Throwable) {
                    // Keep the original export error when libzip already released its handle.
                }
            }
            if ($stage !== null) {
                $this->removeTemporary($stage);
            }
            if ($styles !== null) {
                $this->removeTemporary($styles);
            }
        }
    }

    /** Return ZIP bytes using the same checked export path as writeToFile(). */
    public function writeToString()
    {
        $file = $this->tempFilename();
        try {
            $this->writeToFile($file);
            $contents = file_get_contents($file);
            if ($contents === false) {
                throw new \RuntimeException('Unable to read the XLSX export.');
            }
            return $contents;
        } finally {
            $this->removeTemporary($file);
        }
    }

    /** Stream ZIP bytes without allocating the whole export in PHP memory. */
    public function writeToStdOut()
    {
        $file = $this->tempFilename();
        try {
            $this->writeToFile($file);
            if (readfile($file) === false) {
                throw new \RuntimeException('Unable to send the XLSX export.');
            }
        } finally {
            $this->removeTemporary($file);
        }
    }

    /** Fix filter ownership without altering the upstream workbook generator. */
    protected function buildWorkbookXML()
    {
        $document = $this->parseXml(parent::buildWorkbookXML());
        $filters = $document->getElementsByTagName('definedName');
        $filter = 0;
        $index = 0;
        foreach ($this->sheets as $sheet) {
            $document->getElementsByTagName('sheet')->item($index)->setAttribute('name', $sheet->sheetname);
            if ($sheet->auto_filter) {
                $node = $filters->item($filter++);
                $node->setAttribute('localSheetId', (string) $index);
                $node->textContent = "'" . str_replace("'", "''", $sheet->sheetname) . "'!\$A\$1:"
                    . self::xlsCell(max(0, $sheet->row_count - 1), max(0, count($sheet->columns) - 1), true);
            }
            $index++;
        }
        return $document->saveXML();
    }

    /** The XML timestamp says UTC, so do not emit the process's local clock as UTC. */
    protected function buildCoreXML()
    {
        $document = $this->parseXml(parent::buildCoreXML());
        $document->getElementsByTagNameNS('http://purl.org/dc/terms/', 'created')->item(0)->textContent = gmdate('Y-m-d\TH:i:s\Z');
        return $document->saveXML();
    }

    /** Repair style border state leakage and fractional font sizes in upstream output. */
    protected function writeStylesXML()
    {
        $file = parent::writeStylesXML();
        $document = $this->parseXml(file_get_contents($file));
        $namespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $borders = $document->getElementsByTagName('borders')->item(0);
        while ($borders->firstChild) {
            $borders->removeChild($borders->firstChild);
        }
        $xfs = $document->getElementsByTagName('cellXfs')->item(0)->childNodes;
        $fonts = $document->getElementsByTagName('fonts')->item(0)->getElementsByTagName('font');
        $definitions = [];
        foreach ($this->cell_styles as $index => $definition) {
            $style = json_decode(substr($definition, strpos($definition, ';') + 1), true) ?: [];
            $sides = isset($style['border']) ? array_intersect(['left', 'right', 'top', 'bottom'], explode(',', $style['border'])) : [];
            $line = $style['border-style'] ?? 'hair';
            $color = isset($style['border-color']) ? self::colorArgb($style['border-color']) : '';
            $signature = $sides === [] ? '' : implode(',', $sides) . ':' . $line . ':' . $color;
            if (!isset($definitions[$signature])) {
                $definitions[$signature] = count($definitions);
                $border = $document->createElementNS($namespace, 'border');
                foreach (['left', 'right', 'top', 'bottom', 'diagonal'] as $side) {
                    $element = $document->createElementNS($namespace, $side);
                    if (in_array($side, $sides, true)) {
                        $element->setAttribute('style', $line);
                        if ($color !== '') {
                            $rgb = $document->createElementNS($namespace, 'color');
                            $rgb->setAttribute('rgb', $color);
                            $element->appendChild($rgb);
                        }
                    }
                    $border->appendChild($element);
                }
                $borders->appendChild($border);
            }
            $xf = $xfs->item($index);
            $xf->setAttribute('borderId', (string) $definitions[$signature]);
            if (isset($style['font-size'])) {
                $font = $fonts->item((int) $xf->getAttribute('fontId'));
                $font->getElementsByTagName('sz')->item(0)->setAttribute('val', (string) (float) $style['font-size']);
            }
        }
        $borders->setAttribute('count', (string) count($definitions));
        $buffer = new WriterBuffer($file, 'w+b');
        $buffer->write($document->saveXML());
        $buffer->close();
        return $file;
    }

    /** Stop writes immediately, attempt all cleanup, and retain failed records for a later close() retry. */
    public function close(): void
    {
        if ($this->closing || ($this->closed && $this->sheets === [] && $this->temp_files === [])) {
            return;
        }
        $this->closed = true;
        $this->closing = true;
        $error = null;
        try {
            foreach ($this->sheets as $name => $sheet) {
                try {
                    $sheet->file_writer->close();
                    unset($this->sheets[$name]);
                } catch (\Throwable $failure) {
                    $error ??= $failure;
                }
            }
            $this->sheetNames = $this->mergeBounds = [];
            foreach ($this->temp_files as $file) {
                try {
                    if (!$this->removeTemporary($file)) {
                        $error ??= new \RuntimeException('Unable to remove an XLSX temporary file; restore directory permissions and call close() again.');
                    }
                } catch (\Throwable $failure) {
                    $error ??= $failure;
                }
            }
        } finally {
            $this->closing = false;
        }
        if ($error !== null) {
            throw $error;
        }
    }

    /** Never leave Windows file handles open while trying to remove temporary worksheets. */
    public function __destruct()
    {
        try {
            $this->close();
        } catch (\Throwable) {
        }
    }

    /** Reject mutation of failed or explicitly released writers. */
    private function assertOpen(): void
    {
        if ($this->closed || $this->failed) {
            throw new \LogicException('This XLSX writer is closed or a previous write failed; create a new writer.');
        }
    }

    /** Keep the valid sheet name "0" out of upstream empty() guards. */
    private static function sheetKey(string $name): string
    {
        return $name === '0' ? "\0RC_ZERO" : $name;
    }

    /** Validate names once per sheet; ordinary row appends use a dictionary lookup. */
    private function writableSheetKey($name): string
    {
        $this->assertOpen();
        if (!is_string($name)) {
            throw new \InvalidArgumentException('XLSX worksheet names must be strings.');
        }
        $key = self::sheetKey($name);
        if (isset($this->sheets[$key])) {
            if ($this->sheets[$key]->finalized) {
                throw new \LogicException('Cannot modify an XLSX worksheet after export or writeSheet().');
            }
            return $key;
        }
        if ($name === '' || !mb_check_encoding($name, 'UTF-8') || mb_strlen($name, 'UTF-8') > 31
            || preg_match('/[\x00-\x1f\x7f\\\\\/\[\]:?*]/u', $name)
            || str_starts_with($name, "'") || str_ends_with($name, "'") || trim($name) !== $name) {
            throw new \InvalidArgumentException('Invalid XLSX worksheet name (1-31 characters, no reserved characters).');
        }
        $folded = mb_strtolower($name, 'UTF-8');
        if (isset($this->sheetNames[$folded]) && $this->sheetNames[$folded] !== $name) {
            throw new \InvalidArgumentException('XLSX worksheet names must be unique ignoring case.');
        }
        return $key;
    }

    /** Validate the full row before writing any XML so input errors remain recoverable. */
    private function validateValue($value, string $type): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if (!is_scalar($value) || (is_float($value) && !is_finite($value))) {
            throw new \InvalidArgumentException('XLSX cells require scalar values or null; numbers must be finite.');
        }
        if (is_string($value)) {
            if (!mb_check_encoding($value, 'UTF-8') || (strlen($value) > 32767 && mb_strlen($value, 'UTF-8') > 32767)) {
                throw new \InvalidArgumentException('XLSX text must be valid UTF-8 and at most 32767 characters.');
            }
            if (str_starts_with($value, '=') && $type !== 'n_string') {
                if ($this->autoFormula && strlen($value) === 1) {
                    throw new \InvalidArgumentException('XLSX formulas cannot be empty.');
                }
                return;
            }
        }
        if ($type === 'n_numeric' && !is_bool($value) && !is_numeric($value)) {
            throw new \InvalidArgumentException('A numeric XLSX column cannot contain nonnumeric text.');
        }
        if ($type === 'n_date' || $type === 'n_datetime') {
            if (!is_string($value)) {
                throw new \InvalidArgumentException('XLSX dates require YYYY-MM-DD or YYYY-MM-DD HH:MM:SS text.');
            }
            if ($type === 'n_datetime' && preg_match('/^\d{1,6}:[0-5]\d:[0-5]\d(?:\.\d+)?$/D', $value)) {
                return;
            }
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T]([01]\d|2[0-3]):([0-5]\d):([0-5]\d))?$/D', $value, $parts)
                || (!in_array(substr($value, 0, 10), ['1899-12-31', '1900-01-00', '1900-02-29'], true)
                    && ((int) $parts[1] < 1900 || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])))) {
                throw new \InvalidArgumentException('Invalid XLSX date; use YYYY-MM-DD or YYYY-MM-DD HH:MM:SS.');
            }
        }
    }

    /** Validate known layout/style values; arbitrary XML must never enter raw attributes. */
    private function validateOptions(array $options, int $columns): void
    {
        if ($options === []) {
            return;
        }
        if (array_is_list($options)) {
            if (count($options) !== $columns) {
                throw new \InvalidArgumentException('Per-cell styles must match the number of columns.');
            }
            foreach ($options as $style) {
                if (!is_array($style)) {
                    throw new \InvalidArgumentException('Each XLSX cell style must be an array.');
                }
                $this->validateOptions($style, 0);
            }
            return;
        }
        foreach (['color', 'fill', 'border-color'] as $key) {
            if (isset($options[$key]) && (!is_string($options[$key]) || !preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/iD', $options[$key]))) {
                throw new \InvalidArgumentException('XLSX colors must use #RGB or #RRGGBB.');
            }
        }
        foreach (['height', 'font-size'] as $key) {
            if (isset($options[$key]) && (!is_numeric($options[$key]) || !is_finite((float) $options[$key]) || $options[$key] <= 0)) {
                throw new \InvalidArgumentException('XLSX heights and font sizes must be positive finite numbers.');
            }
        }
        foreach (['freeze_rows' => self::EXCEL_2007_MAX_ROW, 'freeze_columns' => self::EXCEL_2007_MAX_COL] as $key => $limit) {
            if (isset($options[$key]) && ((!is_int($options[$key]) && !is_bool($options[$key])) || $options[$key] < 0 || $options[$key] >= $limit)) {
                throw new \InvalidArgumentException('Frozen pane counts must be nonnegative integers within Excel bounds.');
            }
        }
        if (isset($options['widths'])) {
            if (!is_array($options['widths']) || count($options['widths']) > self::EXCEL_2007_MAX_COL) {
                throw new \InvalidArgumentException('XLSX column widths must be an array within Excel column bounds.');
            }
            foreach ($options['widths'] as $width) {
                if (!is_numeric($width) || !is_finite((float) $width) || $width <= 0 || $width > 255) {
                    throw new \InvalidArgumentException('XLSX column widths must be between 0 and 255.');
                }
            }
        }
        if (isset($options['border-style']) && !in_array($options['border-style'], ['thin', 'medium', 'thick', 'dashDot', 'dashDotDot', 'dashed', 'dotted', 'double', 'hair', 'mediumDashDot', 'mediumDashDotDot', 'mediumDashed', 'slantDashDot'], true)) {
            throw new \InvalidArgumentException('Unsupported XLSX border style.');
        }
        foreach (['border', 'font', 'font-style', 'halign', 'valign'] as $key) {
            if (isset($options[$key]) && (!is_string($options[$key]) || !mb_check_encoding($options[$key], 'UTF-8') || preg_match('/[\x00-\x1f]/', $options[$key]))) {
                throw new \InvalidArgumentException('XLSX style names must be UTF-8 text without control characters.');
            }
        }
    }

    /** Expand a validated RGB color to the ARGB notation used by XLSX. */
    private static function colorArgb(string $color): string
    {
        $rgb = substr($color, 1);
        if (strlen($rgb) === 3) {
            $rgb = $rgb[0] . $rgb[0] . $rgb[1] . $rgb[1] . $rgb[2] . $rgb[2];
        }
        return 'FF' . strtoupper($rgb);
    }

    /** Parse generated metadata, retaining the caller's libxml error mode. */
    private function parseXml(string $xml): \DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            if (!$document->loadXML($xml, LIBXML_NONET)) {
                throw new \RuntimeException('Generated XLSX metadata is incomplete or malformed.');
            }
            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** Retain failed one-shot cleanup for close(); successful or already absent files release their records. */
    private function removeTemporary(string $file): bool
    {
        if (!@unlink($file)) {
            clearstatcache(true, $file);
            if (file_exists($file) || is_link($file)) {
                return false;
            }
        }
        $this->forgetTemporary($file);
        return true;
    }

    /** A successful move releases ownership without deleting a possibly reused source pathname. */
    private function forgetTemporary(string $file): void
    {
        $index = array_search($file, $this->temp_files, true);
        if ($index !== false) {
            unset($this->temp_files[$index]);
        }
    }
}
