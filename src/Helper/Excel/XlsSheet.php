<?php

namespace RC\Helper\Excel;

/** BIFF8 sheet with explicit generator lifetime and upstream record decoding. */
class XlsSheet extends \avadim\FastExcelReader\Xls\XlsSheet
{
    use SheetLifecycle {
        rawRows as private managedRows;
    }

    /** Keep native record parsing and lifecycle ownership; adapt only books with affected XF categories. */
    protected function rawRows(array $readArea, int $rowLimit = 0, bool $rowMode = false): ?\Generator
    {
        $rows = $this->managedRows($readArea, $rowLimit, $rowMode);
        if ($readArea['row_min'] > 1 || $readArea['col_min'] > 1 || $readArea['row_max'] < 65536 || $readArea['col_max'] < 256) {
            $rows = $this->excel->areaRows($rows, $readArea);
        }
        return $this->excel->needsBoundaryCellFixes() ? $this->correctNativeRows($rows) : $rows;
    }

    /** Correct the private decoder's falsey-epoch branch; BIFF ixfe zero is ignored, not applied as XF zero. */
    private function correctNativeRows(\Generator $rows): \Generator
    {
        $formatter = $this->excel->getDateFormatter();
        $rows->rewind();
        while ($rows->valid()) {
            $row = $rows->current();
            foreach ($row['cells'] as $column => $cell) {
                if ($cell['t'] === 'date' && $cell['s'] >= 15 && $cell['v'] === $cell['o']
                    && $formatter !== null && $formatter === $this->excel->getDateFormatter()) {
                    $timestamp = $this->excel->timestamp($cell['o']);
                    if ($timestamp === 0) {
                        $cell['v'] = $formatter === false ? $timestamp : $this->excel->formatDate($timestamp, null, $cell['s']);
                        $row['cells'][$column] = $cell;
                    }
                }
            }
            yield $rows->key() => $row;
            $formatter = $this->excel->getDateFormatter();
            $rows->next();
        }
    }

    /** The BIFF cursor belongs to the inner generator and is freed with it. */
    protected function releaseSheetResources(): void
    {
    }

    /** The pinned native parser keeps its shared-token cache private; access it only on terminal close. */
    protected function releaseRetiredSheetResources(): void
    {
        (new \ReflectionProperty(\avadim\FastExcelReader\Xls\XlsSheet::class, 'sharedFormulaTokens'))
            ->setValue($this, null);
    }
}
