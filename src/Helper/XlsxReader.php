<?php

namespace RC\Helper;

use avadim\FastExcelReader\AbstractBook;
use avadim\FastExcelReader\AbstractSheet;
use RC\Helper\Excel\ReaderFiles;

require_once __DIR__ . '/Excel/reader-bootstrap.php';

/**
 * RC-compatible spreadsheet reader backed by pristine FastExcelReader.
 * Example: xlsx('reader')->open($file)->setReadArea('A1:D100', true)->readRows().
 * For large imports use nextRow(), then close() in finally to release owned temporary files.
 */
class XlsxReader
{
    public const EXCEL_2007_MAX_ROW = 1048576;
    public const EXCEL_2007_MAX_COL = 16384;
    public const KEYS_ORIGINAL = 0;
    public const KEYS_FIRST_ROW = 1;
    public const KEYS_ROW_ZERO_BASED = 2;
    public const KEYS_COL_ZERO_BASED = 4;
    public const KEYS_ZERO_BASED = 6;
    public const KEYS_ROW_ONE_BASED = 8;
    public const KEYS_COL_ONE_BASED = 16;
    public const KEYS_ONE_BASED = 24;
    public const KEYS_RELATIVE = 32;
    public const KEYS_SWAP = 64;
    public const RESULT_MODE_ROW = 1024;
    public const TRIM_STRINGS = 2048;
    public const TREAT_EMPTY_STRING_AS_EMPTY_CELL = 4096;

    private ?AbstractBook $book = null;
    private ?AbstractBook $pendingClose = null;
    private bool $closing = false;
    private ?string $ownedFile = null;
    private ?int $sheetId = null;

    /** Construct an unopened factory, or open a local/PHAR workbook immediately. */
    public function __construct(?string $file = null, array $options = [])
    {
        if ($file !== null) {
            $this->load($file, ReaderFiles::options($options));
        }
    }

    /** Match the historical factory API: open() returns a new reader, not this factory. */
    public function open(string $file, array $options = []): self
    {
        return new self($file, $options);
    }

    /** Import bytes using an owned temporary file; it is removed by close(), not process shutdown. */
    public function openString(string $content, array $options = []): self
    {
        $options = ReaderFiles::options($options);
        if (strlen($content) > $options['max_file_bytes']) {
            throw new \LengthException('Spreadsheet exceeds the import size limit.');
        }
        $file = ReaderFiles::temporary($options['temp_dir'] ?? null);
        try {
            if (file_put_contents($file, $content) !== strlen($content)) {
                throw new \RuntimeException('Cannot write temporary spreadsheet.');
            }
            $reader = new self($file, $options);
            $reader->ownedFile = $file;
            return $reader;
        } catch (\Throwable $error) {
            @unlink($file);
            throw $error;
        }
    }

    /** Read from the caller's current stream position; do not rewind or close their stream. */
    public function openStream($stream, array $options = []): self
    {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new \InvalidArgumentException('A readable stream is required.');
        }
        $options = ReaderFiles::options($options);
        $file = ReaderFiles::temporary($options['temp_dir'] ?? null);
        $target = null;
        try {
            $target = fopen($file, 'wb');
            $limit = $options['max_file_bytes'];
            $written = stream_copy_to_stream($stream, $target, $limit === PHP_INT_MAX ? $limit : $limit + 1);
            if ($written === false) {
                throw new \RuntimeException('Cannot copy spreadsheet stream.');
            }
            // Keep the one-byte overflow probe without overflowing PHP's signed integer length.
            $extra = $written === PHP_INT_MAX ? fread($stream, 1) : '';
            if ($extra === false) {
                throw new \RuntimeException('Cannot copy spreadsheet stream.');
            }
            if ($written > $limit || $extra !== '') {
                throw new \LengthException('Spreadsheet exceeds the import size limit.');
            }
            fclose($target);
            $target = null;
            $reader = new self($file, $options);
            $reader->ownedFile = $file;
            return $reader;
        } catch (\Throwable $error) {
            if (is_resource($target)) {
                fclose($target);
            }
            @unlink($file);
            throw $error;
        }
    }

    /** Dispatch formats locally; remote fetching belongs to the framework's curl() component. */
    private function load(string $file, array $options): void
    {
        if (str_contains($file, "\0") || (str_contains($file, '://') && !str_starts_with($file, 'phar://'))) {
            throw new \InvalidArgumentException('Use a local file, PHAR path or an explicit readable stream.');
        }
        if (!is_file($file) || !is_readable($file)) {
            throw new \RuntimeException('Spreadsheet file does not exist or cannot be read.');
        }
        if (filesize($file) > $options['max_file_bytes']) {
            throw new \LengthException('Spreadsheet exceeds the import size limit.');
        }
        if (str_starts_with($file, 'phar://')) {
            $stream = fopen($file, 'rb');
            try {
                $import = $this->openStream($stream, $options);
                $this->book = $import->book;
                $this->ownedFile = $import->ownedFile;
                $this->sheetId = $import->sheetId;
                $import->book = null;
                $import->ownedFile = null;
                return;
            } finally {
                fclose($stream);
            }
        }
        $file = realpath($file);
        $magic = file_get_contents($file, false, null, 0, 8);
        $format = $options['format'] ?? (str_starts_with($magic, 'PK') ? 'xlsx'
            : ($magic === "\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1" ? 'xls' : 'csv'));
        if ($format === 'xlsx') {
            ReaderFiles::checkZip($file, $options);
            $this->book = new Excel\XlsxBook($file, $options['temp_dir'] ?? null);
        } elseif ($format === 'xls') {
            $this->book = new Excel\XlsBook($file);
        } elseif ($format === 'csv') {
            $this->book = new Excel\CsvBook($file, $options);
        } else {
            throw new \InvalidArgumentException('Supported spreadsheet formats: xlsx, xls (BIFF8), csv.');
        }
        $this->selectFirstSheet();
    }

    /** Explicit lifetime is recommended in long-running workers, especially with stream imports. */
    public function close(): void
    {
        if ($this->closing) {
            return;
        }
        $this->closing = true;
        $error = null;
        try {
            // Detach the public reader immediately; failed cleanup remains retryable, not readable.
            if ($this->book !== null) {
                $this->pendingClose = $this->book;
                $this->book = null;
            }
            if ($this->pendingClose !== null) {
                try {
                    $this->pendingClose->close();
                    $this->pendingClose = null;
                } catch (\Throwable $failure) {
                    $error = $failure;
                }
            }
            if ($this->ownedFile !== null) {
                try {
                    if (!is_file($this->ownedFile) || @unlink($this->ownedFile)) {
                        $this->ownedFile = null;
                    } else {
                        throw new \RuntimeException('Cannot remove temporary spreadsheet.');
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

    /** Destruction is a best-effort fallback; explicit close reports cleanup failures. */
    public function __destruct()
    {
        try {
            $this->close();
        } catch (\Throwable) {
        }
    }

    /** Guard all public operations against an unopened or closed reader. */
    private function workbook(): AbstractBook
    {
        if ($this->book === null) {
            throw new \LogicException('Open a workbook before reading; a closed reader cannot be reused.');
        }
        return $this->book;
    }

    /** Return a lifetime-aware sheet handle for upstream styles, images and metadata APIs. */
    public function sheet(int|string|null $id = null): Excel\SheetView
    {
        if (is_string($id)) {
            foreach ($this->workbook()->sheets() as $sheetId => $sheet) {
                if ($sheet->isName($id)) {
                    return new Excel\SheetView($this, $sheet);
                }
            }
            throw new \InvalidArgumentException('Sheet name does not exist: ' . $id);
        }
        return new Excel\SheetView($this, $this->nativeSheet($id));
    }

    /** Get a named sheet without selecting it; the valid name "0" must not mean current sheet. */
    public function getSheet(?string $name = null, ?string $areaRange = null, ?bool $firstRowKeys = false): Excel\SheetView
    {
        $sheet = $this->sheet($name);
        return $areaRange === null ? $sheet : $sheet->setReadArea(self::normalizeRange($areaRange), $firstRowKeys);
    }

    /** Get a sheet by numeric ID without changing the current selection. */
    public function getSheetById(int $id, ?string $areaRange = null, ?bool $firstRowKeys = false): Excel\SheetView
    {
        $sheet = $this->sheet($id);
        return $areaRange === null ? $sheet : $sheet->setReadArea(self::normalizeRange($areaRange), $firstRowKeys);
    }

    /** Return the first physical sheet as a handle rather than changing reader selection. */
    public function getFirstSheet(?string $areaRange = null, ?bool $firstRowKeys = false): Excel\SheetView
    {
        return $this->getSheetById((int)array_key_first($this->workbook()->sheets()), $areaRange, $firstRowKeys);
    }

    /** Internal sheet lookup avoids handle allocation in the row-reading path. */
    private function nativeSheet(?int $id = null): AbstractSheet
    {
        $sheets = $this->workbook()->sheets();
        $id ??= $this->sheetId;
        if (!isset($sheets[$id])) {
            throw new \InvalidArgumentException('Sheet ID does not exist: ' . $id);
        }
        return $sheets[$id];
    }

    /** Preserve original sheet IDs rather than renumbering the workbook. */
    public function getSheetNames(): array
    {
        $names = [];
        foreach ($this->workbook()->sheets() as $id => $sheet) {
            $names[$id] = $sheet->name();
        }
        return $names;
    }

    /** Select a sheet case-insensitively and keep the legacy fluent reader return value. */
    public function selectSheet(string $name, ?string $areaRange = null, ?bool $firstRowKeys = false): self
    {
        foreach ($this->workbook()->sheets() as $id => $sheet) {
            if ($sheet->isName($name)) {
                $this->selectSheetById($id);
                return $areaRange === null ? $this : $this->setReadArea($areaRange, $firstRowKeys);
            }
        }
        throw new \InvalidArgumentException('Sheet name does not exist: ' . $name);
    }

    /** Change selection without changing the other sheets' read areas. */
    public function selectSheetById(int $sheetId, ?string $areaRange = null): self
    {
        $this->nativeSheet($sheetId);
        $this->workbook()->selectSheetById($sheetId);
        $this->sheetId = $sheetId;
        return $areaRange === null ? $this : $this->setReadArea($areaRange);
    }

    /** Select the first physical worksheet, not necessarily sheet ID 1. */
    public function selectFirstSheet(): self
    {
        $first = array_key_first($this->workbook()->sheets());
        if ($first === null) {
            throw new \RuntimeException('Workbook contains no readable worksheets.');
        }
        return $this->selectSheetById($first);
    }

    /** Set a PHP date format; null resets to integer UTC timestamps. */
    public function setDateFormat(?string $dateFormat): self
    {
        $this->workbook()->resetDateFormat($dateFormat);
        return $this;
    }

    /** Accept A1 (top-left onward), A1:D20, A:D and A1: with strict Excel bounds. */
    public function setReadArea(string $areaRange, ?bool $firstRowKeys = false): self
    {
        $this->nativeSheet()->setReadArea(self::normalizeRange($areaRange), $firstRowKeys);
        return $this;
    }

    /** Change only column bounds, preserving the selected row interval. */
    public function setReadAreaColumns(string $columnsRange, ?bool $firstRowKeys = false): self
    {
        $range = strtoupper(trim($columnsRange));
        if (!preg_match('/^[A-Z]+(?::[A-Z]*)?$/D', $range)) {
            throw new \InvalidArgumentException('Expected a column range such as B:D.');
        }
        self::normalizeRange($range);
        $this->nativeSheet()->setReadAreaColumns($range, $firstRowKeys);
        return $this;
    }

    /** Normalize an area before upstream's permissive parser can silently clamp invalid input. */
    private static function normalizeRange(string $range): string
    {
        $range = strtoupper(trim($range));
        if (!preg_match('/^([A-Z]{1,3})([1-9][0-9]{0,6})?(?::([A-Z]{1,3})?([1-9][0-9]{0,6})?)?$/D', $range, $parts)) {
            throw new \InvalidArgumentException('Invalid spreadsheet read area.');
        }
        $left = self::colNum($parts[1]);
        $top = isset($parts[2]) && $parts[2] !== '' ? (int)$parts[2] : 1;
        $right = !empty($parts[3]) ? self::colNum($parts[3]) : self::EXCEL_2007_MAX_COL;
        $bottom = !empty($parts[4]) ? (int)$parts[4] : self::EXCEL_2007_MAX_ROW;
        if ($top > self::EXCEL_2007_MAX_ROW || $bottom > self::EXCEL_2007_MAX_ROW || $left > $right || $top > $bottom) {
            throw new \InvalidArgumentException('Spreadsheet area is reversed or outside Excel bounds.');
        }
        if ((!empty($parts[4]) && empty($parts[3])) || (!empty($parts[3]) && empty($parts[2]) !== empty($parts[4]))) {
            throw new \InvalidArgumentException('Use either two cell addresses or two column letters.');
        }
        return rtrim($range, ':');
    }

    /** Convert bounded column letters without an unbounded process-global cache. */
    public static function colNum(string $colLetter): int
    {
        $colLetter = strtoupper($colLetter);
        if (!preg_match('/^[A-Z]{1,3}$/D', $colLetter)) {
            throw new \InvalidArgumentException('Invalid Excel column.');
        }
        $number = 0;
        for ($index = 0, $length = strlen($colLetter); $index < $length; $index++) {
            $number = $number * 26 + ord($colLetter[$index]) - 64;
        }
        if ($number > self::EXCEL_2007_MAX_COL) {
            throw new \InvalidArgumentException('Excel column exceeds XFD.');
        }
        return $number;
    }

    /** Convert a 1-based column number to Excel letters. */
    public static function colLetter(int $number): string
    {
        if ($number < 1 || $number > self::EXCEL_2007_MAX_COL) {
            throw new \InvalidArgumentException('Excel column must be between 1 and 16384.');
        }
        return AbstractBook::colLetter($number);
    }

    /** Stream rows with RC key semantics; optional styles include cached values and formula metadata. */
    public function nextRow($columnKeys = null, ?int $resultMode = null, ?bool $styleIdxInclude = null, ?int $rowLimit = 0): \Generator
    {
        return $this->rows($this->nativeSheet(), $columnKeys, $resultMode ?? 0, (bool)$styleIdxInclude, $rowLimit ?? 0);
    }

    /** Translate indexing and headers once per row while delegating XML/CSV/BIFF parsing upstream. */
    private function rows(AbstractSheet $sheet, mixed $keys, int $mode, bool $styles = false, int $limit = 0, ?bool $headerOverride = null): \Generator
    {
        if (is_int($keys)) {
            $mode |= $keys;
            $keys = null;
        }
        if ($keys !== null && !is_bool($keys) && !is_array($keys)) {
            throw new \InvalidArgumentException('Column keys must be a map, boolean or result mode.');
        }
        $area = $sheet->readArea();
        $headers = $headerOverride ?? (is_bool($keys) ? $keys : (($mode & self::KEYS_FIRST_ROW) || $area['first_row_keys']));
        $aliases = is_array($keys) ? array_change_key_case($keys, CASE_UPPER) : [];
        $first = true;
        $seen = 0;
        $nativeMode = $mode & (self::RESULT_MODE_ROW | self::TRIM_STRINGS | self::TREAT_EMPTY_STRING_AS_EMPTY_CELL);
        foreach ($sheet->nextRow(false, $nativeMode, $styles) as $rowNumber => $data) {
            $this->workbook();
            $cells = ($mode & self::RESULT_MODE_ROW) ? $data['__cells'] : $data;
            if ($first && $headers) {
                $headerMap = [];
                foreach ($cells as $column => $value) {
                    $value = $styles ? ($value['v'] ?? null) : $value;
                    $headerMap[$column] = $value === null || $value === '' ? $column : $value;
                }
                $aliases += $headerMap;
                $first = false;
                continue;
            }
            $first = false;
            $row = [];
            foreach ($cells as $column => $value) {
                $key = $aliases[$column] ?? $column;
                if (!isset($aliases[$column]) && ($mode & (self::KEYS_COL_ZERO_BASED | self::KEYS_COL_ONE_BASED))) {
                    $key = self::colNum((string)$column) - (($mode & self::KEYS_RELATIVE) ? $area['col_min'] - 1 : 0)
                        - (($mode & self::KEYS_COL_ZERO_BASED) ? 1 : 0);
                }
                if (!is_string($key) && !is_int($key)) {
                    throw new \UnexpectedValueException('Column header aliases must be strings or integers.');
                }
                if (array_key_exists($key, $row)) {
                    throw new \UnexpectedValueException('Duplicate column header or alias: ' . $key);
                }
                $row[$key] = $value;
            }
            $rowKey = $rowNumber;
            if ($mode & (self::KEYS_ROW_ZERO_BASED | self::KEYS_ROW_ONE_BASED)) {
                $rowKey -= (($mode & self::KEYS_RELATIVE) ? $area['row_min'] - 1 + (int)$headers : 0)
                    + (($mode & self::KEYS_ROW_ZERO_BASED) ? 1 : 0);
            }
            yield $rowKey => ($mode & self::RESULT_MODE_ROW) ? ['__cells' => $row, '__row' => $data['__row']] : $row;
            $this->workbook();
            if ($limit > 0 && ++$seen >= $limit) {
                break;
            }
        }
    }

    /** Read a selected or explicitly addressed sheet without changing the current selection. */
    public function readSheetRows(?int $sheetId = null, array $columnKeys = [], ?bool $firstRowKeys = null, ?int $indexStyle = null): array
    {
        $sheet = $this->nativeSheet($sheetId);
        $mode = $indexStyle ?? 0;
        if ($firstRowKeys !== null) {
            $mode = $firstRowKeys ? $mode | self::KEYS_FIRST_ROW : $mode & ~self::KEYS_FIRST_ROW;
            if (!$columnKeys) {
                return $this->collect($this->rows($sheet, $firstRowKeys, $mode), $mode);
            }
        }
        return $this->collect($this->rows($sheet, $columnKeys, $mode, false, 0, $firstRowKeys), $mode);
    }

    /** Read the current area; null inherits its header setting, false explicitly disables headers. */
    public function readRows($columnKeys = null, ?int $indexStyle = null, ?bool $styleIdxInclude = null): array
    {
        $mode = ($indexStyle ?? 0) | (is_int($columnKeys) ? $columnKeys : 0);
        return $this->collect($this->nextRow($columnKeys, $mode, $styleIdxInclude), $mode);
    }

    /** Collect or transpose sparsely populated rows without dropping cells or misaligning indices. */
    private function collect(\Generator $rows, int $mode): array
    {
        $data = iterator_to_array($rows);
        if (!($mode & self::KEYS_SWAP)) {
            return $data;
        }
        if ($mode & self::RESULT_MODE_ROW) {
            throw new \InvalidArgumentException('Row metadata and column transposition cannot be combined.');
        }
        $columns = [];
        foreach ($data as $row) {
            foreach ($row as $key => $_) {
                $columns[$key] ??= array_fill_keys(array_keys($data), null);
            }
        }
        foreach ($data as $index => $row) {
            foreach ($row as $key => $value) {
                $columns[$key][$index] = $value;
            }
        }
        return $columns;
    }

    /** Return column => row => value; missing cells are represented by null. */
    public function readColumns($columnKeys = null, ?int $indexStyle = null): array
    {
        return $this->readRows($columnKeys, ($indexStyle ?? 0) | self::KEYS_SWAP);
    }

    /** Read row descriptors while retaining the same RC header and indexing rules. */
    public function readRowsWithStyles($columnKeys = null, ?int $indexStyle = null): array
    {
        return $this->readRows($columnKeys, $indexStyle, true);
    }

    /** Transpose styled cells with the same sparse-cell handling as ordinary columns. */
    public function readColumnsWithStyles($columnKeys = null, ?int $indexStyle = null): array
    {
        return $this->readRows($columnKeys, ($indexStyle ?? 0) | self::KEYS_SWAP, true);
    }

    /** Preserve the legacy per-cell callback signature: callback($row, $column, $value). */
    public function readSheetCallback(callable $callback, ?int $sheetId = null, ?int $indexStyle = null): array
    {
        foreach ($this->rows($this->nativeSheet($sheetId), false, ($indexStyle ?? 0) & ~self::RESULT_MODE_ROW, true) as $row => $cells) {
            foreach ($cells as $column => $value) {
                // A null template slot is not an actual stored cell; retain the legacy callback contract.
                if ($value !== null && $callback($row, $column, $value['v'])) {
                    return [];
                }
            }
        }
        return [];
    }

    /** Read cell addresses and values, always ignoring header aliases. */
    public function readSheetCells(?int $sheetId = null): array
    {
        $cells = [];
        foreach ($this->rows($this->nativeSheet($sheetId), false, 0, true) as $row => $values) {
            foreach ($values as $column => $value) {
                if ($value !== null) {
                    $cells[$column . $row] = $value['v'];
                }
            }
        }
        return $cells;
    }

    /** Shortcut for the selected sheet's cell-address map. */
    public function readCells(): array
    {
        return $this->readSheetCells();
    }

    /** Expose additive upstream APIs without replacing the historical RC method contracts. */
    public function __call(string $name, array $arguments): mixed
    {
        $book = $this->workbook();
        if (is_callable([$book, $name]) && !(new \ReflectionMethod($book, $name))->isStatic()) {
            $result = $book->$name(...$arguments);
            if ($result instanceof AbstractSheet) {
                return new Excel\SheetView($this, $result);
            }
            if (is_array($result)) {
                foreach ($result as &$value) {
                    if ($value instanceof AbstractSheet) {
                        $value = new Excel\SheetView($this, $value);
                    }
                }
                unset($value);
            }
            return $result === $book ? $this : $result;
        }
        $sheet = $this->nativeSheet();
        if (is_callable([$sheet, $name]) && !(new \ReflectionMethod($sheet, $name))->isStatic()) {
            return $this->sheet()->$name(...$arguments);
        }
        throw new \BadMethodCallException('Unknown spreadsheet reader method: ' . $name);
    }
}
