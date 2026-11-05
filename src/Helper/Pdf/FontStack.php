<?php

declare(strict_types=1);

namespace RC\Helper\Pdf;

use Com\Tecnick\Pdf\Font\Stack;

/** Resolve bundled font definitions directly; upstream still parses, validates and owns every document font. */
final class FontStack extends Stack
{
    /** Preserve registered fonts and subsets, invalidating only unit-dependent derived metrics. */
    public function changeUnit(float $ratio): void
    {
        if ($ratio <= 0 || !is_finite($ratio)) {
            throw new \InvalidArgumentException('PDF font unit ratio must be positive.');
        }
        if ($this->kunit !== $ratio) {
            $this->kunit = $ratio;
            $this->metric = [];
        }
    }

    /** Keep native JSON priority, adding literal/protected PHP data without changing upstream caches or styles. */
    public function addFont(int &$objnum, string $font, string $style = '', string $ifile = '', ?bool $subset = null): array
    {
        if ($ifile !== '' && strtolower(pathinfo(trim($ifile), PATHINFO_EXTENSION)) === 'php') {
            return $this->addLegacyFont($objnum, $font, $style, trim($ifile), $subset);
        }
        $directory = dirname(__DIR__, 2) . '/ThirdParty/tc-lib-pdf-font/fonts';
        if ($ifile !== '' || isset($this->fontKeyCache[$font][$style])) {
            return parent::addFont($objnum, $font, $style, $ifile, $subset);
        }

        // Let upstream normalize core aliases, styles and PDF/A family names before selecting a file.
        $definition = new LegacyFontLocator($font, $style, subset: $subset ?? $this->subset,
            unicode: $this->unicode, pdfa: $this->pdfa, fileHelper: $this->fileHelper);
        $key = strtolower($definition->getFontkey());
        $path = $directory . '/' . $key . '.json';
        $nativeRoot = rtrim(str_replace('\\', '/', K_PATH_FONTS), '/') === str_replace('\\', '/', $directory);
        if (!$nativeRoot || preg_match('/\A[a-z0-9_-]+\z/D', $key) !== 1 || !is_file($path)) {
            $located = $definition->locate($directory);
            if ($located !== null) {
                $result = $located['legacy'] || $located['fallback']
                    ? $this->addLegacyFont($objnum, $font, $style, $located['file'], $subset,
                        $located['fallback'], !$located['legacy'])
                    : parent::addFont($objnum, $font, $style, $located['file'], $subset);
                $this->fontKeyCache[$font][$style] = $result['key'];
                return $result;
            }
            return parent::addFont($objnum, $font, $style, $ifile, $subset);
        }

        $result = parent::addFont($objnum, $font, $style, $path, $subset);
        $this->fontKeyCache[$font][$style] = $result['key'];
        return $result;
    }

    /** Temporarily project one legacy definition into native JSON, restoring the live reader even on failure. */
    private function addLegacyFont(
        int &$objnum, string $font, string $style, string $ifile, ?bool $subset,
        bool $fakeStyle = false, bool $nativeJson = false,
    ): array {
        if ($this->fileHelper === null) { return parent::addFont($objnum, $font, $style, $ifile, $subset); }
        $reader = $this->fileHelper;
        $view = new LegacyFontFile($reader, $ifile, $fakeStyle, $nativeJson);
        $this->fileHelper = $view;
        try {
            $result = parent::addFont($objnum, $font, $style, $ifile, $subset);
            // Native Core naming assumes a standard family key; legacy explicit files may use an alias.
            if ($view->coreName() !== null) { $this->font[$result['key']]['name'] = $view->coreName(); }
            return $result;
        }
        finally { $this->fileHelper = $reader; }
    }
}
