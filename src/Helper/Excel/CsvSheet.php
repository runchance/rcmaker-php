<?php

namespace RC\Helper\Excel;

/** CSV row ownership adapter; callers should not share one sheet iterator concurrently. */
class CsvSheet extends \avadim\FastExcelReader\Csv\CsvSheet
{
    use SheetLifecycle;

    /** Counting must not rewind the tokenizer under a suspended row iterator. */
    protected function computeExtent(): void
    {
        $this->assertReadable();
        if (!$this->extentScanned && $this->rowsSuspended) {
            throw new \LogicException('Finish the CSV iterator before scanning its dimensions.');
        }
        parent::computeExtent();
    }

    /** The upstream tokenizer reopens on rewind, so closing here does not prevent later reads. */
    protected function releaseSheetResources(): void
    {
        if (isset($this->reader)) {
            $this->reader->close();
        }
    }

    /** The workbook closes the shared tokenizer; a retained sheet no longer needs to own it. */
    protected function releaseRetiredSheetResources(): void
    {
        unset($this->reader);
    }
}
