<?php

declare(strict_types=1);

namespace RC\Helper\Pdf;

use Closure;
use RuntimeException;

/**
 * Insert prepared RC callbacks into native HTML flow without splitting tables or styled containers.
 * Flow rebasing derives from tc-lib-pdf HTML, Nicola Asuni / Tecnick.com LTD.
 * SPDX-License-Identifier: LGPL-3.0-or-later
 * See ../../ThirdParty/tc-lib-pdf/LICENSE.
 */
trait HtmlFlow
{
    private array $htmlCallScopes = [];
    private array $htmlFragmentAppenders = [];
    private int $htmlCallMeasurement = 0;

    /** Open one short-lived callback scope; tokens are internal markers, never public method names. */
    public function openHtmlCalls(array $entries, Closure $render): string
    {
        $id = bin2hex(random_bytes(12));
        $this->htmlCallScopes[] = ['id' => $id, 'entries' => $entries, 'render' => $render];
        return $id;
    }

    /** Release closures immediately on successful or failed rendering. */
    public function closeHtmlCalls(string $id): void
    {
        $scope = array_pop($this->htmlCallScopes);
        if (($scope['id'] ?? null) !== $id) {
            throw new RuntimeException('PDF HTML callback scopes are out of order.');
        }
    }

    /** Layout snapshots must not serialize transient native rendering closures. */
    public function suspendHtmlFlow(): array
    {
        $state = [$this->htmlCallScopes, $this->htmlFragmentAppenders, $this->htmlCallMeasurement];
        $this->htmlCallScopes = $this->htmlFragmentAppenders = [];
        $this->htmlCallMeasurement = 0;
        return $state;
    }

    /** Restore the active renderer immediately after taking its detached layout snapshot. */
    public function restoreHtmlFlow(array $state): void
    {
        [$this->htmlCallScopes, $this->htmlFragmentAppenders, $this->htmlCallMeasurement] = $state;
    }

    /** Restore the selected native font even when a callback aborts an HTML cell. */
    public function addHTMLCell(
        string $html, float $posx = 0, float $posy = 0, float $width = 0, float $height = 0,
        ?array $cell = null, array $styles = [],
    ): void {
        $font = $this->captureHTMLCallerFontState();
        try {
            parent::addHTMLCell($html, $posx, $posy, $width, $height, $cell, $styles);
        } finally {
            $this->restoreHTMLCallerFontState($font);
        }
    }

    /** Remember which replay is measuring rather than emitting a header. */
    protected function measureHTMLCellRenderedHeight(
        string $html, float $posx = 0, float $posy = 0, float $width = 0, float $height = 0,
        ?array $cell = null, array $styles = [],
    ): float {
        ++$this->htmlCallMeasurement;
        try {
            return parent::measureHTMLCellRenderedHeight($html, $posx, $posy, $width, $height, $cell, $styles);
        } finally {
            --$this->htmlCallMeasurement;
        }
    }

    /** Keep the native per-page fragment sink available only while prepared callbacks are active. */
    protected function renderHTMLCellFragments(
        array &$hrc, float &$tpx, float &$tpy, float &$tpw, float &$tph, callable $appendFragment,
    ): void {
        if ($this->htmlCallScopes === []) {
            parent::renderHTMLCellFragments($hrc, $tpx, $tpy, $tpw, $tph, $appendFragment);
            return;
        }
        $this->htmlFragmentAppenders[] = $appendFragment;
        try {
            parent::renderHTMLCellFragments($hrc, $tpx, $tpy, $tpw, $tph, $appendFragment);
        } finally {
            array_pop($this->htmlFragmentAppenders);
        }
    }

    /** Let native row segmentation retain each cell's content if a prepared callback creates a page. */
    protected function parseHTMLTagOPENtr(
        array &$hrc, int $key, float &$tpx, float &$tpy, float &$tpw, float &$tph,
    ): string {
        $out = parent::parseHTMLTagOPENtr($hrc, $key, $tpx, $tpy, $tpw, $tph);
        $tableIndex = array_key_last($hrc['tablestack']);
        if ($this->htmlCallScopes === [] || $tableIndex === null
            || ($hrc['tablestack'][$tableIndex]['frag'] ?? null) !== null
            || !$this->canFragmentHTMLTableRow($hrc, $key)) {
            return $out;
        }
        $scope = $this->htmlCallScopes[array_key_last($this->htmlCallScopes)];
        for ($index = $key + 1, $count = count($hrc['dom']); $index < $count; ++$index) {
            $node = $this->getHTMLDOMNode($hrc['dom'], $index);
            if ($node === null) { continue; }
            if ($node['tag'] && $node['value'] === 'tr' && !$node['opening'] && $node['parent'] === $key) {
                break;
            }
            $token = $node['attribute']['data-rc-tcpdf'] ?? '';
            if (is_string($token) && str_starts_with($token, $scope['id'] . ':')) {
                $heads = [];
                $tableKey = $hrc['tablestack'][$tableIndex]['domkey'];
                foreach (array_keys($hrc['dom']) as $candidateKey) {
                    $candidate = $this->getHTMLDOMNode($hrc['dom'], $candidateKey);
                    if ($candidate === null) { continue; }
                    if ($candidate['tag'] && $candidate['value'] === 'table' && !$candidate['opening']
                        && $candidate['parent'] === $tableKey && $candidate['thead'] !== '') {
                        $heads[$tableKey] = $candidate['thead'];
                        break;
                    }
                }
                $this->startHTMLRowFragment($hrc, $this->getHTMLTableRowReplayHead($hrc, $key, $heads));
                break;
            }
        }
        return $out;
    }

    /** Keep native span styles and call trusted drawing code exactly at this node's layout cursor. */
    protected function parseHTMLTagOPENspan(
        array &$hrc, int $key, float &$tpx, float &$tpy, float &$tpw, float &$tph,
    ): string {
        $out = parent::parseHTMLTagOPENspan($hrc, $key, $tpx, $tpy, $tpw, $tph);
        if ($this->htmlCallScopes === []) { return $out; }
        $node = $this->getHTMLDOMNode($hrc['dom'], $key);
        $token = $node['attribute']['data-rc-tcpdf'] ?? '';
        $scopeIndex = array_key_last($this->htmlCallScopes);
        $scope = $this->htmlCallScopes[$scopeIndex];
        if (!is_string($token) || !str_starts_with($token, $scope['id'] . ':')) { return $out; }
        $index = substr($token, strlen($scope['id']) + 1);
        if (!ctype_digit($index) || !isset($scope['entries'][(int) $index])) { return $out; }
        $entry = $scope['entries'][(int) $index];
        if (strcasecmp($entry['method'], 'AddPage') === 0 && $entry['parameters'] === []) {
            return $out;
        }
        $oldX = $tpx;
        $oldY = $tpy;
        $memo = hash('sha256', serialize([$token, $this->page->getPageId(), $tpx, $tpy, $hrc['cellctx']['maxwidth'],
            $node['fontname'], $node['fontstyle'], $node['fontsize'], $node['fgcolor']]));
        if (isset($this->htmlCallScopes[$scopeIndex]['measured'][$memo])) {
            $measured = $this->htmlCallScopes[$scopeIndex]['measured'][$memo];
            [$result, $tpx, $tpy] = $measured;
            if ($this->htmlCallMeasurement === 0) { unset($this->htmlCallScopes[$scopeIndex]['measured'][$memo]); }
        } else {
            $font = $this->captureHTMLCallerFontState();
            $base = $this->stripHTMLFontKeyStyleSuffix($node['fontname']);
            if ($base !== '') { $node['fontname'] = $base; }
            try {
                $result = ($scope['render'])($entry, $tpx, $tpy, $node, $hrc['cellctx']);
            } finally {
                $restore = $this->restoreHTMLCallerFontState($font);
            }
            $result['pages'][$result['after']] = ($result['pages'][$result['after']] ?? '') . $restore;
            if ($this->htmlCallMeasurement > 0) {
                $this->htmlCallScopes[$scopeIndex]['measured'][$memo] = [$result, $tpx, $tpy];
            }
        }
        if ($result['before'] === $result['after']) {
            $out .= $result['pages'][$result['after']] ?? '';
        } elseif ($this->isHTMLFragmentingCell($hrc)) {
            $newX = $tpx;
            $newY = $tpy;
            $this->setCurrentPage($result['before']);
            $this->captureHTMLFragment($hrc, $out);
            $out = '';
            $tpx = $oldX;
            $tpy = max($oldY + 2 * self::WIDTH_TOLERANCE, $hrc['cellctx']['linebottom']);
            for ($pid = $result['before']; $pid <= $result['after']; ++$pid) {
                $this->captureHTMLFragment($hrc, $result['pages'][$pid] ?? '');
                $tpy += 2 * self::WIDTH_TOLERANCE;
                if ($pid < $result['after']
                    && (!$this->advanceHTMLTableCellSegment($hrc, $tpx, $tpy, $tpw, $tph)
                        || $this->page->getPageId() !== $pid + 1)) {
                    throw new RuntimeException('Cannot continue the PDF HTML callback table row on its target page.');
                }
            }
            $tpx = $newX;
            $tpy = $newY;
        } else {
            $append = $this->htmlFragmentAppenders[array_key_last($this->htmlFragmentAppenders)];
            $this->page->setCurrentPage($result['before']);
            $flush = $this->flushOpenBlockBuffers($hrc, $oldY);
            if ($flush !== '') { $append($flush); }
            if ($hrc['tablestack'] !== []) {
                $append($this->renderHTMLOpenTableFramesBeforeBreak($hrc, $oldY));
            }
            foreach ($result['pages'] as $pid => $content) {
                $this->page->setCurrentPage($pid);
                $append($content);
            }
            $this->page->setCurrentPage($result['after']);
            $region = $this->page->getRegion();
            $oldRegion = $result['region'];
            $hrc['cellctx']['originy'] = $region['RY'];
            $hrc['cellctx']['originx'] += $region['RX'] - $oldRegion['RX'];
            $hrc['cellctx']['regionoffset'] = ($hrc['cellctx']['regionoffset'] ?? 0.0) + $region['RX'] - $oldRegion['RX'];
            if ($hrc['cellctx']['maxwidth'] > 0.0) {
                $hrc['cellctx']['maxwidth'] = max(0.0, $hrc['cellctx']['maxwidth'] + $region['RW'] - $oldRegion['RW']);
            }
            $newX = $tpx;
            $newY = $tpy;
            $this->resetHTMLCursorAfterPageBreak($hrc, $tpx, $tpy, $tpw);
            if ($hrc['blockbuf'] !== []) { $this->rebaseHTMLBlockBuffers($hrc, $region['RY']); }
            if ($hrc['tablestack'] !== []) { $this->resetHTMLTableStackOnPageBreak($hrc, $region['RY']); }
            $tpx = $newX;
            $tpy = $newY;
        }
        $hrc['cellctx']['lineoriginx'] = $hrc['cellctx']['originx'];
        $hrc['cellctx']['lineadvance'] = max(0.0, $tpx - $hrc['cellctx']['originx']);
        if ($tpy !== $oldY) {
            $hrc['cellctx']['lineascent'] = 0.0;
            $hrc['cellctx']['linewrapped'] = false;
        }
        $box = $this->getLastCellBBox();
        $hrc['cellctx']['linebottom'] = max($hrc['cellctx']['linebottom'], $tpy, $box['y'] + $box['h']);
        $tpw = max(0.0, $hrc['cellctx']['originx'] + $hrc['cellctx']['maxwidth'] - $tpx);
        return $out;
    }
}
