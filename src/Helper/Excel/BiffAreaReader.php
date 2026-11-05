<?php

namespace RC\Helper\Excel;

use avadim\FastExcelReader\Xls\BiffReader;
use avadim\FastExcelReader\Xls\BiffRecord;
use avadim\FastExcelReader\Xls\OleStream;

/** Internal bounded-area adapter; native OLE, CONTINUE and formula decoding remain unchanged. */
final class BiffAreaReader extends BiffReader
{
    /** Used only by XlsBook's first row-reader acquisition, never ordinary metadata readers. */
    public function __construct(OleStream $stream, private array $area)
    {
        parent::__construct($stream);
    }

    /** Prevent excluded formulas from leaving a deferred STRING for the native row accumulator. */
    public function nextRecord(): ?array
    {
        $record = parent::nextRecord();
        if ($record === null || $record['type'] !== BiffRecord::FORMULA || strlen($record['data']) < 14
            || $record['data'][6] !== "\0" || substr($record['data'], 12, 2) !== "\xff\xff") {
            return $record;
        }
        $cell = unpack('vrow/vcolumn', $record['data']);
        $row = $cell['row'] + 1; $column = $cell['column'] + 1;
        if ($row >= $this->area['row_min'] && $row <= $this->area['row_max']
            && $column >= $this->area['col_min'] && $column <= $this->area['col_max']) {
            return $record;
        }
        // An internal empty-cache marker preserves native row boundaries/filtering without changing the input file.
        $record['data'][6] = "\x03";
        if (isset($record['parts'][0][6])) { $record['parts'][0][6] = "\x03"; }
        return $record;
    }
}
