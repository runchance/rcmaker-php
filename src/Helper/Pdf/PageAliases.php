<?php

declare(strict_types=1);

/**
 * Alias writer/Bidi adaptations from tc-lib-pdf 8.82.2 Text.php.
 * Copyright 2002-2026 Nicola Asuni - Tecnick.com LTD.
 * SPDX-License-Identifier: LGPL-3.0-or-later
 * Original license: ../../ThirdParty/tc-lib-pdf/LICENSE
 * RC changes: legacy alias lengths, global/group numbering and scoped output context.
 */

namespace RC\Helper\Pdf;

/** Bridge legacy facade aliases to upstream glyph, alignment and tagged-text substitution. */
trait PageAliases
{
    private const RC_PAGE_ALIASES = [
        'PN' => '~#PN', 'PT' => '~#PT',
        'DP' => '{pnb}', 'DT' => '{nb}', 'GP' => '{gpnb}', 'GT' => '{gnb}',
    ];

    private const RC_ALIAS_CODES = [
        126 => ['PN' => [126, 35, 80, 78], 'PT' => [126, 35, 80, 84]],
        123 => ['DP' => [123, 112, 110, 98, 125], 'DT' => [123, 110, 98, 125],
            'GP' => [123, 103, 112, 110, 98, 125], 'GT' => [123, 103, 110, 98, 125]],
    ];

    private int $firstDocumentPageNumber = 1;
    private int $htmlTocAliasSequence = 0;
    private array $htmlTocTokens = [];
    private array $htmlTocAliases = [];
    private bool $htmlTocActive = false;

    /** Open one TOC render scope; native font writers will encode its deferred target numbers. */
    public function beginHtmlTocAliases(array $pages, bool $correctAlign, bool $rtl): array
    {
        if ($this->htmlTocActive) { throw new \RuntimeException('Nested HTML TOC rendering is not supported.'); }
        $this->htmlTocActive = true;
        $scope = ++$this->htmlTocAliasSequence;
        $tokens = [];
        foreach ($pages as $page) {
            $token = '{#'.$page.'}'; $kind = 'TC'.$scope.':'.$page;
            $tokens[$page] = $token; $this->htmlTocTokens[$token] = $kind;
            $this->htmlTocAliases[$kind] = ['token' => $token, 'number' => $page + $this->firstDocumentPageNumber - 1,
                'target' => $page - 1, 'offset' => $this->firstDocumentPageNumber, 'align' => $correctAlign, 'rtl' => $rtl];
        }
        return $tokens;
    }

    /** Freeze actual target numbers after pagination; older TOC marks retain their own scope values. */
    public function endHtmlTocAliases(array $numbers = []): void
    {
        foreach ($this->htmlTocTokens as $token => $kind) {
            $page = (int) substr($token, 2, -1);
            if (isset($numbers[$page])) { $this->htmlTocAliases[$kind]['number'] = $numbers[$page]; }
        }
        $this->htmlTocTokens = [];
        $this->htmlTocActive = false;
    }

    /** A later TOC insertion moves earlier TOC targets too, without confusing reused token text. */
    protected function remapHtmlTocAliases(array $map): void
    {
        foreach ($this->htmlTocAliases as &$alias) {
            $alias['target'] = $map[$alias['target']] ?? $alias['target'];
            $alias['number'] = $alias['target'] + $alias['offset'];
        }
        unset($alias);
    }

    /** Resolve only registered ASCII TOC tokens while rendering that TOC, not arbitrary user brace text. */
    private function htmlTocAliasAt(array $codes, int $position): ?string
    {
        if ($this->htmlTocTokens === [] || ($codes[$position + 1] ?? null) !== 35) { return null; }
        $token = '{#';
        for ($index = $position + 2; $index <= $position + 22; ++$index) {
            $code = $codes[$index] ?? null;
            if ($code === 125) { return $this->htmlTocTokens[$token.'}'] ?? null; }
            if ($code === null || $code < 48 || $code > 57) { return null; }
            $token .= chr($code);
        }
        return null;
    }

    /** Static legacy tokens and scoped TOC tokens share native bidi/font placeholder handling. */
    private function rcAliasLength(string $kind): int
    {
        return strlen(self::RC_PAGE_ALIASES[$kind] ?? $this->htmlTocAliases[$kind]['token']);
    }

    /** Applied at final output so renumbering and page deletion cannot freeze an earlier total. */
    public function setStartingPageNumber(int $number): void
    {
        $this->firstDocumentPageNumber = max(0, $number);
    }

    /** Inspect only brace/tilde candidates; ordinary text avoids per-alias scans and codepoint conversion. */
    protected function getPageAliasPositions(array $ordarr): array
    {
        $found = [];
        $positions = array_merge(array_keys($ordarr, 123, true), array_keys($ordarr, 126, true));
        foreach ($positions as $position) {
            foreach (self::RC_ALIAS_CODES[$ordarr[$position]] as $kind => $codes) {
                if (array_slice($ordarr, $position, count($codes)) === $codes) {
                    $found[$position] = $kind;
                    break;
                }
            }
            if (!isset($found[$position]) && $ordarr[$position] === 123) {
                $kind = $this->htmlTocAliasAt($ordarr, $position);
                if ($kind !== null) { $found[$position] = $kind; }
            }
        }
        ksort($found);
        return $found;
    }

    /** Recognize aliases in ActualText too; surrounding user text is not interpreted as PDF operators. */
    protected function hasPageAlias(string $txt): bool
    {
        return str_replace(self::RC_PAGE_ALIASES, '', $txt) !== $txt
            || ($this->htmlTocTokens !== [] && str_replace(array_keys($this->htmlTocTokens), '', $txt) !== $txt);
    }

    /** Keep each variable-length alias in one LTR bidi run, using upstream's embedding-level rule. */
    protected function getPageAliasBidiLevels(array $ordarr, array $bidi): array
    {
        if ($bidi['level'] === []) {
            return $bidi;
        }
        foreach ($this->getPageAliasPositions($ordarr) as $position => $kind) {
            $length = $this->rcAliasLength($kind);
            $level = max(0, ...array_slice($bidi['level'], $position, $length));
            for ($index = $position; $index < $position + $length; ++$index) {
                $bidi['level'][$index] = $level;
            }
        }
        return $bidi;
    }

    /** Use upstream's font-aware placeholder writer, with the actual length of each legacy token. */
    protected function getOutAliasEscapedStr(array $ordarr, array &$aliases): string
    {
        $ordarr = array_values($ordarr);
        $positions = $this->getPageAliasPositions($ordarr);
        if ($positions === []) {
            return $this->getOutEscapedStr($ordarr);
        }
        $out = '';
        $start = 0;
        foreach ($positions as $position => $kind) {
            $length = $this->rcAliasLength($kind);
            $out .= $this->getOutEscapedStr(array_slice($ordarr, $start, $position - $start));
            $mark = $this->addPageAliasMark($kind, array_slice($ordarr, $position, $length));
            $aliases[] = $mark;
            $out .= ')' . $this->getPageAliasMarkStr($mark) . '(';
            $start = $position + $length;
        }
        return $out . $this->getOutEscapedStr(array_slice($ordarr, $start));
    }

    /** Resolve semantic ActualText with the same values as visible glyphs, including mixed global/group aliases. */
    protected function getPageAliasMarkValue(int $tid, string $mark, int $pagenum, int $pagetotal): string
    {
        $data = $this->pageAliasMarks[$tid] ?? null;
        if ($data !== null && $data['kind'] === 'TEXT') {
            $text = $data['literal'];
            if ($pagenum >= 0) {
                $replacements = [];
                foreach (self::RC_PAGE_ALIASES as $kind => $alias) {
                    $replacements[$alias] = $this->getPageAliasNumber($kind, $pagenum, $pagetotal);
                }
                foreach ($data['toc_aliases'] ?? [] as $alias => $kind) {
                    $replacements[$alias] = $this->getPageAliasNumber($kind, $pagenum, $pagetotal);
                }
                $text = strtr($text, $replacements);
            }
            return $this->formatPdfUaActualText($text);
        }
        $out = parent::getPageAliasMarkValue($tid, $mark, $pagenum, $pagetotal);
        $toc = $this->htmlTocAliases[$data['kind'] ?? ''] ?? null;
        if ($toc === null || !$toc['align'] || $pagenum < 0) { return $out; }
        // Preserve the token's reserved width like legacy padding, without editing PDF strings or font bytes.
        $slack = parent::getPageAliasSlack($data, $pagenum, $pagetotal);
        $padding = abs($slack) < 0.000001 ? '' : sprintf('%F ', -$slack);
        return $toc['rtl'] ? $out.$padding : $padding.$out;
    }

    /** Bind ActualText to this TOC scope before its tokens are cleared or reused by a later TOC. */
    protected function getPageAliasActualTextMark(string $txt): string
    {
        $mark = parent::getPageAliasActualTextMark($txt);
        if ($this->htmlTocTokens !== [] && preg_match_all('/\{#[0-9]+\}/', $txt, $matches)) {
            $aliases = array_intersect_key($this->htmlTocTokens, array_flip($matches[0]));
            $this->pageAliasMarks[array_key_last($this->pageAliasMarks)]['toc_aliases'] = $aliases;
        }
        return $mark;
    }

    /** TOC correction is local to its token; native line/global-alias corrections must not apply it twice. */
    protected function getPageAliasSlack(array $data, int $pagenum, int $pagetotal): float
    {
        return isset($this->htmlTocAliases[$data['kind']]) ? 0.0 : parent::getPageAliasSlack($data, $pagenum, $pagetotal);
    }

    /** Native aliases remain group-relative; facade document aliases use final physical page order. */
    protected function getPageAliasNumber(string $kind, int $pagenum, int $pagetotal): string
    {
        if (isset($this->htmlTocAliases[$kind])) { return (string) $this->htmlTocAliases[$kind]['number']; }
        return match ($kind) {
            'DP' => (string) (($this->page->getOutputPageNumber() ?? $pagenum) + $this->firstDocumentPageNumber - 1),
            'DT' => (string) (count($this->page->getPages()) + $this->firstDocumentPageNumber - 1),
            'GP' => (string) $pagenum,
            'GT' => (string) $pagetotal,
            default => parent::getPageAliasNumber($kind, $pagenum, $pagetotal),
        };
    }
}
