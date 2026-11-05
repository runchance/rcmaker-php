<?php

namespace RC\Helper\Excel;

/**
 * Workbook-local conversion of Excel calendar/time tokens to PHP date masks.
 * Example: getDateFormatPattern($style) supplies a gmdate() mask when the format is representable.
 */
trait DateFormats
{
    private array $datePatternCache = [];
    private array $localeDateFormatters = [];
    private bool $automaticDateFormatting = false;
    private bool $hasSerialDateFormats = false;

    /** @internal Read-only row hook; configured only through dateFormatter(true), never by application assignment. */
    public private(set) bool $serialDateFormatting = false;

    /** Format-specific adapters identify definitions actually supplied by the file, not guesses from its pattern. */
    protected function hasDeclaredNumberFormat(int $formatId): bool
    {
        return false;
    }

    /** Opt in through the native API without allowing closed workbooks to recreate ICU resources. */
    public function useLocaleFormats(?string $locale = null): \avadim\FastExcelReader\AbstractBook
    {
        $this->assertOpen();
        return parent::useLocaleFormats($locale);
    }

    /** Use ICU itself for locale-selected short dates/times instead of treating ICU tokens as Excel masks. */
    protected function _buildLocaleFormats(?string $locale): array
    {
        $formats = parent::_buildLocaleFormats($locale);
        $renderers = [];
        foreach ([14 => [\IntlDateFormatter::SHORT, \IntlDateFormatter::NONE],
            20 => [\IntlDateFormatter::NONE, \IntlDateFormatter::SHORT],
            21 => [\IntlDateFormatter::NONE, \IntlDateFormatter::MEDIUM]] as $id => [$date, $time]) {
            $renderers[$id] = new \IntlDateFormatter($locale, $date, $time, 'UTC', \IntlDateFormatter::GREGORIAN);
        }
        $this->localeDateFormatters = $renderers;
        return $formats;
    }

    /** A locale renderer need not have a faithful PHP mask; keep those getters explicitly nullable. */
    public function getDateFormatPattern(int $styleIdx): ?string
    {
        if ($this->localeDateFormatters !== []) {
            $style = $this->getCompleteStyleByIdx($styleIdx);
            $id = $style['format']['format-num-id'] ?? null;
            if ($id !== null && $this->hasDeclaredNumberFormat($id)) {
                return $this->_convertDateFormatPattern($style['format']['format-pattern'] ?? '');
            }
            if ($id === 22 || ($id !== null && isset($this->localeDateFormatters[$id]))) { return null; }
        }
        return parent::getDateFormatPattern($styleIdx);
    }

    /** Recognize date fields outside quoted/escaped text; retain native built-in ID handling. */
    protected function _isDatePattern(?int $numFmtId, string $pattern): bool
    {
        if ($pattern === '') {
            if ($numFmtId === 46 || $numFmtId === 47) {
                $this->hasSerialDateFormats = true;
                $this->serialDateFormatting = $this->automaticDateFormatting;
            }
            return parent::_isDatePattern($numFmtId, $pattern);
        }
        $plan = $this->datePattern($pattern);
        if ($plan['serial']) {
            $this->hasSerialDateFormats = true;
            $this->serialDateFormatting = $this->automaticDateFormatting;
        }
        return $plan['nativeDate'] ?? $plan['date'];
    }

    /** Return a faithful PHP mask, or null when raw serial/value-dependent rendering is required. */
    public function _convertDateFormatPattern($pattern): ?string
    {
        $this->assertOpen();
        return $this->datePattern((string)$pattern)['php'];
    }

    /** Format saved calendar/time fields, including tokens with no exact single PHP-mask equivalent. */
    public function dateFormatter($formatter): \avadim\FastExcelReader\AbstractBook
    {
        $this->assertOpen();
        $this->automaticDateFormatting = $formatter === true;
        $this->serialDateFormatting = $this->automaticDateFormatting && $this->hasSerialDateFormats;
        if ($formatter !== true) {
            return parent::dateFormatter($formatter);
        }
        $this->dateFormatter = function ($value, $format = null, $styleIdx = null) {
            if ($styleIdx !== null) {
                $style = $this->getCompleteStyleByIdx($styleIdx);
                $pattern = $style['format']['format-pattern'] ?? '';
                $numberFormatId = $style['format']['format-num-id'] ?? null;
                if ($this->localeFormats !== null && $numberFormatId !== null && isset($this->localeFormats[$numberFormatId])
                    && !$this->hasDeclaredNumberFormat($numberFormatId)) {
                    if ($numberFormatId === 22 || isset($this->localeDateFormatters[$numberFormatId])) {
                        if ($numberFormatId === 22) {
                            $date = $this->localeDateFormatters[14]->format($value);
                            $time = $this->localeDateFormatters[20]->format($value);
                            if ($date === false || $time === false) { throw new \UnexpectedValueException('Cannot render locale date/time.'); }
                            return $date . ' ' . $time;
                        }
                        $rendered = $this->localeDateFormatters[$numberFormatId]->format($value);
                        if ($rendered === false) { throw new \UnexpectedValueException('Cannot render locale date/time.'); }
                        return $rendered;
                    }
                    $pattern = $this->localeFormats[$numberFormatId];
                }
                $plan = $this->datePattern($pattern);
                if ($plan['parts'] !== null) {
                    if ($plan['php'] !== null) { return gmdate($plan['php'], $value); }
                    $result = '';
                    foreach ($plan['parts'] as [$type, $mask]) {
                        $result .= match ($type) {
                            'mask' => gmdate($mask, $value),
                            'minute' => (string)(int)gmdate('i', $value),
                            'second' => (string)(int)gmdate('s', $value),
                            'month-initial' => gmdate('M', $value)[0],
                            'short-upper' => gmdate('A', $value)[0],
                            'short-lower' => gmdate('a', $value)[0],
                        };
                    }
                    return $result;
                }
            }
            if ($format || $this->dateFormat) { return gmdate($format ?: $this->dateFormat, $value); }
            return $value;
        };
        return $this;
    }

    /** @internal Render raw-dependent cells in a streamed row, without replaying custom date callbacks. */
    public function formatSerialDateCells(array $cells): array
    {
        $this->assertOpen();
        if (!$this->serialDateFormatting) { return $cells; }
        foreach ($cells as &$cell) {
            if ($cell['t'] !== 'date' && $cell['t'] !== 'number') { continue; }
            $numeric = is_numeric($cell['o']);
            if (!$numeric && (!is_string($cell['o']) || !preg_match('/\A\d{4}-\d{2}-\d{2}(?:[T ]|\z)/D', $cell['o']))) { continue; }
            $style = $this->getCompleteStyleByIdx($cell['s']); $id = $style['format']['format-num-id'] ?? null;
            $pattern = $style['format']['format-pattern'] ?? '';
            if ($this->localeFormats !== null && $id !== null && isset($this->localeFormats[$id]) && !$this->hasDeclaredNumberFormat($id)) {
                if ($id === 22 || isset($this->localeDateFormatters[$id])) { continue; }
                $pattern = $this->localeFormats[$id];
            }
            $plan = $this->datePattern($pattern);
            if (!$plan['serial']) { continue; }
            $serial = $numeric ? (float)$cell['o'] : DateFormatPlan::isoSerial($cell['o'], $this->date1904);
            $rendered = DateFormatPlan::renderSerial($plan, $serial, $this->date1904);
            if ($rendered !== null) {
                if ($rendered['t'] === 'number' && floor($rendered['v']) === $rendered['v'] && abs($rendered['v']) < PHP_INT_MAX) { $rendered['v'] = (int)$rendered['v']; }
                $cell['v'] = $rendered['v']; $cell['t'] = $rendered['t'];
            }
        }
        unset($cell); return $cells;
    }

    /** Bound public arbitrary-pattern calls while sharing classification/conversion work within this import. */
    private function datePattern(string $pattern): array
    {
        if (isset($this->datePatternCache[$pattern])) {
            return $this->datePatternCache[$pattern];
        }
        $length = strlen($pattern);
        $result = DateFormatPlan::compile($pattern);
        if ($length <= 4096 && count($this->datePatternCache) < 256) { $this->datePatternCache[$pattern] = $result; }
        return $result;
    }

}
