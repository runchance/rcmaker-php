<?php

namespace RC\Helper\Excel;

use avadim\FastExcelReader\Interfaces\InterfaceXmlReader;

/** XLSX sheet lifecycle adapter; cell and style parsing remains upstream. */
class XlsxSheet extends \avadim\FastExcelReader\Sheet
{
    use SheetLifecycle;
    use XlsxImages;

    /** Style index zero is valid; an epoch timestamp of zero is a valid date as well. */
    protected function _makeCellValue(string $dataType, int $styleIdx, ?string $cellValue, ?string $formula, ?string $textContent, ?array &$additionalData = [])
    {
        if ($styleIdx === 0 && ($dataType === '' || $dataType === 'n')) {
            $style = $this->excel->styleByIdx(0);
            $type = $style['formatType'] ?? null;
            if ($type === 'd' || $type === 'date') {
                $dataType = 'd';
            } elseif ($type === 's' || $type === 'string') {
                $dataType = 'string';
            }
        }
        $value = parent::_makeCellValue($dataType, $styleIdx, $cellValue, $formula, $textContent, $additionalData);
        if ($additionalData['t'] === 'date' && $cellValue !== null && $cellValue !== ''
            && $value === $cellValue && $this->excel->getDateFormatter()
            && $this->excel->timestamp($cellValue) === 0) {
            $value = $additionalData['v'] = $this->excel->formatDate(0, null, $styleIdx);
        }
        return $value;
    }

    /** The upstream sheet factory hard-codes Excel::createReader, so override this hook. */
    protected function getReader(?string $file = null): InterfaceXmlReader
    {
        $this->assertReadable();
        if ($this->rowsSuspended) {
            throw new \LogicException('Finish the sheet iterator before reading additional XML metadata.');
        }
        if (!isset($this->xmlReader)) {
            $this->xmlReader = (new XmlReader($file ?? $this->zipFilename))
                ->temporaryDirectory($this->excel->readerTempDirectory);
        }
        return $this->xmlReader;
    }

    /** Close cursors on normal completion, exceptions, abandoned iterators and workbook close. */
    protected function releaseSheetResources(): void
    {
        if (isset($this->xmlReader)) {
            $this->xmlReader->close();
        }
    }

    /** Retained public handles must not keep parsed metadata, image indexes or callback captures alive. */
    protected function releaseRetiredSheetResources(): void
    {
        $this->preReadFunc = $this->postReadFunc = null;
        $this->readNodeFunc = $this->sharedFormulas = $this->actualRows = $this->actualCols = [];
        $this->cellStat = $this->validations = $this->conditionals = $this->hyperlinks = null;
        $this->rowHeights = $this->colWidths = $this->tabProperties = null;
        $this->relatedDrawing = $this->drawingPartIndex = $this->indexedImageRows = null;
        unset($this->xmlReader);
    }
}
