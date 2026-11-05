<?php

namespace RC\Helper\Excel;

/**
 * Per-workbook adaptation of FastExcelReader 4.5.0 complete-style resolution.
 * Copyright (c) 2020 Vadim Shemarov, MIT; permission notice in ../../ThirdParty/fast-excel-reader/LICENSE.
 * Example: $reader->getCompleteStyleByIdx(0, true) returns flattened metadata for this import only.
 */
trait CompleteStyles
{
    private array $completeStyleCache = [];
    private array $flatStyleCache = [];

    /** Publish the native lazy style tree only after a complete successful parse; allow a failed read to retry. */
    public function readStyles(): array
    {
        $this->assertOpen();
        if (isset($this->styles['_'])) {
            return $this->styles['_'];
        }
        try {
            return parent::readStyles();
        } catch (\Throwable $error) {
            unset($this->styles['_']);
            $this->completeStyleCache = [];
            $this->flatStyleCache = [];
            throw $error;
        }
    }

    /** Preserve native inheritance/flattening while keeping memoized styles out of process-static storage. */
    public function getCompleteStyleByIdx(int $styleIdx, ?bool $flat = false): array
    {
        $this->assertOpen();
        if ($flat && isset($this->flatStyleCache[$styleIdx])) {
            return $this->flatStyleCache[$styleIdx];
        }
        if (!isset($this->completeStyleCache[$styleIdx])) {
            $result = $styleIdx !== 0 ? $this->getCompleteStyleByIdx(0) : [];
            $styles = $this->readStyles();
            if (isset($styles['cellXfs'][$styleIdx])) {
                $baseStyleId = $styles['cellXfs'][$styleIdx]['xfId'] ?? -1;
                if ($baseStyleId >= 0 && isset($styles['cellStyleXfs'][$baseStyleId])) {
                    $baseStyle = $styles['cellStyleXfs'][$baseStyleId];
                } else {
                    $baseStyle = [];
                }
                $selectedStyle = array_replace_recursive($baseStyle, $styles['cellXfs'][$styleIdx]);
                // References select whole records, not additions to a previously resolved default record.
                foreach (['fontId' => 'font', 'fillId' => 'fill', 'borderId' => 'border'] as $reference => $part) {
                    if (array_key_exists($reference, $selectedStyle)) {
                        if (isset($result[$part], $styles[$part . 's'][$selectedStyle[$reference]])) {
                            $result[$part] = [];
                        } else {
                            unset($result[$part]);
                        }
                    }
                }
                $result = array_replace_recursive($result, $selectedStyle);
                if (isset($result['xfId'])) {
                    unset($result['xfId']);
                }
            }
            if (isset($result['numFmtId']) && isset($styles['numFmts'][$result['numFmtId']])) {
                if (isset($result['format'])) {
                    $result['format'] = array_replace_recursive($result['format'], $styles['numFmts'][$result['numFmtId']]);
                } else {
                    $result['format'] = $styles['numFmts'][$result['numFmtId']];
                }
                unset($result['numFmtId']);
            }
            if (isset($result['fontId']) && isset($styles['fonts'][$result['fontId']])) {
                if (isset($result['font'])) {
                    $result['font'] = array_replace_recursive($result['font'], $styles['fonts'][$result['fontId']]);
                } else {
                    $result['font'] = $styles['fonts'][$result['fontId']];
                }
                unset($result['fontId']);
            }
            if (isset($result['fillId']) && isset($styles['fills'][$result['fillId']])) {
                if (isset($result['fill'])) {
                    $result['fill'] = array_replace_recursive($result['fill'], $styles['fills'][$result['fillId']]);
                } else {
                    $result['fill'] = $styles['fills'][$result['fillId']];
                }
                unset($result['fillId']);
            }
            if (isset($result['borderId']) && isset($styles['borders'][$result['borderId']])) {
                if (isset($result['border'])) {
                    $result['border'] = array_replace_recursive($result['border'], $styles['borders'][$result['borderId']]);
                } else {
                    $result['border'] = $styles['borders'][$result['borderId']];
                }
                unset($result['borderId']);
            }
            // Unknown indices retain the native fallback result, but cannot grow an arbitrary memo table.
            if (isset($styles['cellXfs'][$styleIdx])) {
                $this->completeStyleCache[$styleIdx] = $result;
            }
        } else {
            $result = $this->completeStyleCache[$styleIdx];
        }
        if ($flat && $result) {
            $parts = [];
            foreach ($result as $key => $part) {
                // Unknown native format/font IDs remain useful metadata, not invalid array_merge arguments.
                $parts[] = is_array($part) ? $part : [$key => $part];
            }
            $result = array_merge(...$parts);
            if (isset($this->completeStyleCache[$styleIdx])) {
                $this->flatStyleCache[$styleIdx] = $result;
            }
        }
        return $result;
    }
}
