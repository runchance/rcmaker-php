<?php

namespace RC\Helper\Excel;

/** Shared lifetime and date fixes for the upstream workbook implementations. */
trait BookLifecycle
{
    use CompleteStyles;
    use DateFormats;

    private bool $closed = false;
    private bool $closing = false;
    private bool $closeComplete = false;
    public ?string $readerTempDirectory = null;

    /** Fail explicitly when a retained sheet is read after its owner has closed. */
    public function assertOpen(): void
    {
        if ($this->closed) {
            throw new \LogicException('This workbook has been closed.');
        }
    }

    /** Release sheets, parser buffers and circular references before deleting owned input files. */
    public function close(): void
    {
        if ($this->closing || $this->closeComplete) {
            return;
        }
        $this->closed = true;
        $this->closing = true;
        $error = null;
        try {
            foreach ($this->sheets as $id => $sheet) {
                try {
                    $sheet->retire();
                    unset($this->sheets[$id]);
                } catch (\Throwable $failure) {
                    $error ??= $failure;
                }
            }
            try {
                $this->sharedStrings = $this->styles = [];
                $this->names = [];
                $this->localeFormats = null;
                $this->completeStyleCache = $this->flatStyleCache = [];
                $this->datePatternCache = [];
                $this->localeDateFormatters = [];
                $this->automaticDateFormatting = $this->hasSerialDateFormats = $this->serialDateFormatting = false;
            } catch (\Throwable $failure) {
                $error ??= $failure;
            }
            try {
                $this->dateFormatter = null;
            } catch (\Throwable $failure) {
                $error ??= $failure;
            }
            try {
                $this->releaseReaderResources();
            } catch (\Throwable $failure) {
                $error ??= $failure;
            }
            $this->closeComplete = $error === null;
        } finally {
            $this->closing = false;
        }
        if ($error !== null) {
            throw $error;
        }
    }

    /** Legacy API allows resetting the date format to timestamp output with null. */
    public function resetDateFormat(?string $format): void
    {
        $this->dateFormat = $format;
    }

    /** Convert Excel serials and ISO dates without mutating PHP's process-wide timezone. */
    public function timestamp($value): int
    {
        if (is_numeric($value)) {
            return parent::timestamp((string)$value);
        }
        try {
            return (new \DateTimeImmutable((string)$value, new \DateTimeZone('UTC')))->getTimestamp();
        } catch (\Exception $error) {
            throw new \UnexpectedValueException('Invalid spreadsheet date.', 0, $error);
        }
    }
}
