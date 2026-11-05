<?php

namespace RC\Helper\Excel;

/** BIFF8/XLS adapter; unsupported older or encrypted workbooks still fail explicitly. */
class XlsBook extends \avadim\FastExcelReader\Xls\XlsBook
{
    use BookLifecycle;

    private ?array $pendingRowArea = null;

    /** Dispose partially decoded OLE state when a malformed workbook fails during construction. */
    public function __construct(string $file)
    {
        try {
            parent::__construct($file);
        } catch (\Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    /** Reuse upstream BOUNDSHEET decoding and replace only the resulting sheet implementation. */
    protected function addSheet(string $data, int $sheetId): void
    {
        parent::addSheet($data, $sheetId);
        if (isset($this->sheets[$sheetId])) {
            $sheet = $this->sheets[$sheetId];
            $this->sheets[$sheetId] = (new XlsSheet($sheet->name(), $sheet->id(), (int)$sheet->path(), $this))
                ->setState($sheet->state());
        }
    }

    /** Reject fresh metadata reads after close instead of using a stale OLE stream. */
    public function newBiffReader(): \avadim\FastExcelReader\Xls\BiffReader
    {
        $this->assertOpen();
        $area = $this->pendingRowArea;
        $this->pendingRowArea = null;
        return $area === null ? parent::newBiffReader() : new BiffAreaReader($this->ole->openStream('Workbook'), $area);
    }

    /** @internal Scope the area's guard to this iterator's first native reader; metadata/nested readers stay native. */
    public function areaRows(\Generator $rows, array $area): \Generator
    {
        $previous = $this->pendingRowArea;
        $this->pendingRowArea = $area;
        try {
            $rows->rewind();
        } finally {
            $this->pendingRowArea = $previous;
        }
        if ($rows->valid()) {
            yield from $rows;
        }
    }

    /** Reuse the native FORMAT-record index without adding a duplicate BIFF style cache. */
    protected function hasDeclaredNumberFormat(int $formatId): bool
    {
        return array_key_exists($formatId, $this->numberFormats);
    }

    /** @internal Inspect already parsed XF categories once per iterator; numeric-only books need no row correction. */
    public function needsBoundaryCellFixes(): bool
    {
        $this->assertOpen();
        foreach ($this->styles['cellXfs'] ?? [] as $index => $style) {
            $type = $style['formatType'] ?? null;
            if ($index >= 15 && ($type === 'd' || $type === 'date')) {
                return true;
            }
        }
        return false;
    }

    /** Row generators are already disposed; dropping the final OLE owner closes its handle. */
    protected function releaseReaderResources(): void
    {
        $this->pendingRowArea = null;
        $this->numberFormats = $this->xfFormatIndex = $this->xfRecords = [];
        $this->fontRecords = $this->palette = $this->blips = [];
        unset($this->ole);
    }
}
