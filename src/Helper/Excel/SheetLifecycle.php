<?php

namespace RC\Helper\Excel;

/** Close abandoned row iterators and prevent two iterators sharing a parser cursor. */
trait SheetLifecycle
{
    private bool $retired = false;
    private bool $retiring = false;
    private bool $readingRows = false;
    protected bool $rowsSuspended = false;
    private ?\Generator $sourceRows = null;

    /** Expose area bounds for the RC-compatible absolute/relative indexing adapter. */
    public function readArea(): array
    {
        return $this->area;
    }

    /** Unlike upstream, changing columns must not reset an already selected row interval. */
    public function setReadAreaColumns(string $columnsRange, ?bool $firstRowKeys = false): \avadim\FastExcelReader\AbstractSheet
    {
        $top = $this->area['row_min'];
        $bottom = $this->area['row_max'];
        parent::setReadAreaColumns(rtrim($columnsRange, ':'), $firstRowKeys);
        $this->area['row_min'] = $top;
        $this->area['row_max'] = $bottom;
        return $this;
    }

    /** Dispose the inner generator immediately, even when a caller retains the outer iterator. */
    public function retire(): void
    {
        if ($this->retiring) {
            return;
        }
        $this->retired = true;
        $this->retiring = true;
        $error = null;
        try {
            // Disposing a user-formatted row can run destructors before parser cleanup starts.
            try { $this->generator = null; }
            catch (\Throwable $failure) { $error ??= $failure; }
            try { $this->sourceRows = null; }
            catch (\Throwable $failure) { $error ??= $failure; }
            try { $this->releaseSheetResources(); }
            catch (\Throwable $failure) { $error ??= $failure; }
            try {
                $this->dimension = $this->cols = $this->mergedCells = null;
                $this->area = $this->props = $this->images = [];
            } catch (\Throwable $failure) {
                $error ??= $failure;
            }
            try { $this->releaseRetiredSheetResources(); }
            catch (\Throwable $failure) { $error ??= $failure; }
        } finally {
            $this->retiring = false;
        }
        if ($error !== null) {
            throw $error;
        }
    }

    /** Drop format-specific payloads only on terminal close, never after an ordinary completed read. */
    protected function releaseRetiredSheetResources(): void
    {
    }

    /** Check lifetime before parser access or resuming a suspended generator. */
    protected function assertReadable(): void
    {
        if ($this->retired) {
            throw new \LogicException('This worksheet has been closed.');
        }
        $this->excel->assertOpen();
    }

    /** Delegate row decoding to upstream; own cursors and raw-serial display at row boundaries. */
    protected function rawRows(array $readArea, int $rowLimit = 0, bool $rowMode = false): ?\Generator
    {
        $this->assertReadable();
        if ($this->readingRows) {
            throw new \LogicException('Finish or discard the current sheet iterator before reading it again.');
        }
        $this->readingRows = true;
        try {
            $this->sourceRows = parent::rawRows($readArea, $rowLimit, $rowMode);
            $serialFormatter = !empty($this->excel->serialDateFormatting) ? $this->excel->getDateFormatter() : null;
            $this->sourceRows->rewind();
            while ($this->sourceRows->valid()) {
                $this->rowsSuspended = true;
                try {
                    $row = $this->sourceRows->current();
                    if ($serialFormatter !== null && $serialFormatter === $this->excel->getDateFormatter()) {
                        $row['cells'] = $this->excel->formatSerialDateCells($row['cells']);
                    }
                    yield $this->sourceRows->key() => $row;
                } finally {
                    $this->rowsSuspended = false;
                }
                $this->assertReadable();
                $serialFormatter = !empty($this->excel->serialDateFormatting) ? $this->excel->getDateFormatter() : null;
                $this->sourceRows->next();
            }
        } finally {
            $this->sourceRows = null;
            $this->readingRows = false;
            $this->releaseSheetResources();
        }
    }
}
