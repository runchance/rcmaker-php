<?php

namespace RC\Helper\Excel;

/** CSV import through the same RC workbook API, using upstream CSV parsing. */
class CsvBook extends \avadim\FastExcelReader\Csv\CsvBook
{
    use BookLifecycle;

    /** Ensure partially initialized imports do not retain open files after an exception. */
    public function __construct(string $file, array $options = [])
    {
        try {
            parent::__construct($file, $options);
        } catch (\Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    /** Install only a lifecycle-aware sheet; preserve the upstream tokenizer and options. */
    protected function _prepare(string $file): void
    {
        $this->reader = new \avadim\FastExcelReader\Csv\CsvReader($file, $this->csvOptions);
        $this->sheets = [1 => new CsvSheet('CSV', '1', $this->reader, $file, $this)];
        $this->defaultSheetId = 1;
    }

    /** Release the shared CSV handle before removing a stream import's temporary file. */
    protected function releaseReaderResources(): void
    {
        $error = null;
        if (isset($this->reader)) {
            try { $this->reader->close(); }
            catch (\Throwable $failure) { $error ??= $failure; }
            try { $this->reader->onError(null); }
            catch (\Throwable $failure) { $error ??= $failure; }
            try { unset($this->reader); }
            catch (\Throwable $failure) { $error ??= $failure; }
        }
        try { $this->csvOptions = []; }
        catch (\Throwable $failure) { $error ??= $failure; }
        if ($error !== null) {
            throw $error;
        }
    }
}
