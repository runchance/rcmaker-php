<?php

declare(strict_types=1);

namespace RC\Helper\Pdf;

use Com\Tecnick\Pdf\Cache\CacheInterface;
use Com\Tecnick\Pdf\Encrypt\Encrypt;
use Com\Tecnick\Unicode\TextDirection;
use Com\Tecnick\Pdf\TextFitMode;
use Com\Tecnick\Pdf\TextHAlign;
use Com\Tecnick\Pdf\TextVAlign;
use RuntimeException;

/** RC resource wiring and configuration lifecycle; PDF rendering stays in the pristine upstream engine. */
final class Engine extends \TCPDF_ENGINE
{
    use PageAliases, HtmlFlow;

    private float $legacyListIndentWidth = -1.0;
    private bool $legacyOpenCell = true;
    private ?int $htmlTocCellSpan = null;
    private array $htmlTocTextLinks = [];
    private array $multiCellLineCaptures = [];
    private array $nativeTextLineCalls = [];
    private int $multiCellLineCaptureId = 0;

    /** Begin a scoped plain-cell observation; matching completed native calls replace measurement previews. */
    public function beginMultiCellLineCapture(string $text): int
    {
        $id = ++$this->multiCellLineCaptureId;
        $this->multiCellLineCaptures[$id] = ['text' => $text, 'lines' => null];
        return $id;
    }

    /** Close in a finally block; nested cells and failed renders must not leak their counts into later calls. */
    public function endMultiCellLineCapture(int $id): ?int
    {
        $lines = $this->multiCellLineCaptures[$id]['lines'] ?? null;
        unset($this->multiCellLineCaptures[$id]);
        return $lines;
    }

    /** Isolate nested native calls so page-break callbacks cannot add their own text to the outer cell count. */
    private function beginNativeTextLineCall(): ?int
    {
        $scope = array_key_last($this->multiCellLineCaptures);
        if ($scope !== null) { $this->nativeTextLineCalls[] = 0; }
        return $scope;
    }

    /** Publish only completed calls for the requested body; frame-only minima and preview calls do not add rows. */
    private function endNativeTextLineCall(?int $scope, string $text, bool $complete): void
    {
        if ($scope === null) { return; }
        $lines = array_pop($this->nativeTextLineCalls);
        if ($complete && isset($this->multiCellLineCaptures[$scope]) && $this->multiCellLineCaptures[$scope]['text'] === $text) {
            $this->multiCellLineCaptures[$scope]['lines'] = $lines;
        }
    }

    /** Forward positional/named native options unchanged; observe each complete non-flowing text-cell render. */
    public function getTextCell(
        string $txt, float $posx = 0, float $posy = 0, float $width = 0, float $height = 0,
        float $offset = 0, float $linespace = 0, string|TextVAlign $valign = 'C', string|TextHAlign $halign = 'C',
        ?array $cell = null, array $styles = [], float $strokewidth = 0, float $wordspacing = 0,
        float $leading = 0, float $rise = 0, bool $jlast = true, bool $fill = true, bool $stroke = false,
        bool $underline = false, bool $linethrough = false, bool $overline = false, bool $clip = false,
        bool $drawcell = true, string|TextDirection $forcedir = '', ?array $shadow = null, string|TextFitMode $fit = '',
    ): string {
        $scope = $this->multiCellLineCaptures === [] ? null : $this->beginNativeTextLineCall(); $complete = false;
        try {
            $out = parent::getTextCell($txt, $posx, $posy, $width, $height, $offset, $linespace, $valign, $halign,
                $cell, $styles, $strokewidth, $wordspacing, $leading, $rise, $jlast, $fill, $stroke, $underline,
                $linethrough, $overline, $clip, $drawcell, $forcedir, $shadow, $fit);
            $complete = true;
            return $out;
        } finally { if ($scope !== null) { $this->endNativeTextLineCall($scope, $txt, $complete); } }
    }

    /** Forward native flow unchanged and total its real row lists across pages/regions, not its final bounding box. */
    public function addTextCell(
        string $txt, int $pid = -1, float $posx = 0, float $posy = 0, float $width = 0, float $height = 0,
        float $offset = 0, float $linespace = 0, string|TextVAlign $valign = 'T', string|TextHAlign $halign = '',
        ?array $cell = null, array $styles = [], float $strokewidth = 0, float $wordspacing = 0,
        float $leading = 0, float $rise = 0, bool $jlast = true, bool $fill = true, bool $stroke = false,
        bool $underline = false, bool $linethrough = false, bool $overline = false, bool $clip = false,
        bool $drawcell = true, string|TextDirection $forcedir = '', ?array $shadow = null, string|TextFitMode $fit = '',
    ): void {
        $scope = $this->multiCellLineCaptures === [] ? null : $this->beginNativeTextLineCall(); $complete = false;
        try {
            parent::addTextCell($txt, $pid, $posx, $posy, $width, $height, $offset, $linespace, $valign, $halign,
                $cell, $styles, $strokewidth, $wordspacing, $leading, $rise, $jlast, $fill, $stroke, $underline,
                $linethrough, $overline, $clip, $drawcell, $forcedir, $shadow, $fit);
            $complete = true;
        } finally { if ($scope !== null) { $this->endNativeTextLineCall($scope, $txt, $complete); } }
    }

    /** Count native logical rows once, before shadow/Bidi glyph emission can produce several drawing operations. */
    protected function outTextLines(
        array $ordarr, array $lines, float $posx, float $posy, float $width, float $offset, float $fontascent,
        float $linespace = 0, float $strokewidth = 0, float $wordspacing = 0, float $leading = 0, float $rise = 0,
        string $halign = '', bool $jlast = true, bool $fill = true, bool $stroke = false, bool $underline = false,
        bool $linethrough = false, bool $overline = false, bool $clip = false, ?array $shadow = null,
        bool $baseRtl = false, array $bidi = self::BIDI_NONE,
    ): string {
        $out = parent::outTextLines($ordarr, $lines, $posx, $posy, $width, $offset, $fontascent, $linespace,
            $strokewidth, $wordspacing, $leading, $rise, $halign, $jlast, $fill, $stroke, $underline, $linethrough,
            $overline, $clip, $shadow, $baseRtl, $bidi);
        $call = array_key_last($this->nativeTextLineCalls);
        if ($call !== null) { $this->nativeTextLineCalls[$call] += count($lines); }
        return $out;
    }

    /** Measure through the same native text preparation and word/character splitting used by cell rendering. */
    public function measureTextLineCount(string $text, float $width, string $direction = ''): int
    {
        $ordinals = [];
        $dimensions = self::DIM_DEFAULT;
        $this->prepareText($text, $ordinals, $dimensions, $direction);
        return max(1, count($this->splitLines($ordinals, $dimensions, $this->toPoints($width))));
    }

    /** Retain the native link context while rendering a TOC text fragment, including recursive page splits. */
    protected function parseHTMLText(
        array &$hrc,
        int $key,
        float &$tpx,
        float &$tpy,
        float &$tpw,
        float &$tph,
        ?callable $appendFragment = null,
    ): string {
        if (!$this->htmlTocActive || $this->getCurrentHTMLLink($hrc) === '') {
            return parent::parseHTMLText($hrc, $key, $tpx, $tpy, $tpw, $tph, $appendFragment);
        }
        $this->htmlTocTextLinks[] = ['linkstack' => $hrc['linkstack']];
        try {
            return parent::parseHTMLText($hrc, $key, $tpx, $tpy, $tpw, $tph, $appendFragment);
        } finally {
            array_pop($this->htmlTocTextLinks);
        }
    }

    /** Use each native glyph-line box, not the last line of an entire wrapped fragment, for TOC links. */
    protected function getJustifiedString(string $txt, array $ordarr, array $dim, float $width = 0): string
    {
        $out = parent::getJustifiedString($txt, $ordarr, $dim, $width);
        if ($this->htmlTocTextLinks !== []) {
            $context = $this->htmlTocTextLinks[array_key_last($this->htmlTocTextLinks)];
            $box = $this->getLastBBox();
            parent::addHTMLLinkAnnotation($context, $box['x'], $box['y'], $box['w'], $box['h']);
        }
        return $out;
    }

    /** Text links were emitted per glyph line above; retain native annotation behavior for images and other HTML. */
    protected function addHTMLLinkAnnotation(array &$hrc, float $posx, float $posy, float $width, float $height): void
    {
        if ($this->htmlTocTextLinks === []) {
            parent::addHTMLLinkAnnotation($hrc, $posx, $posy, $width, $height);
        }
    }

    /** Scope legacy RTL column placement to HTML TOC cells; leave ordinary native HTML untouched. */
    protected function parseHTMLTagOPENtd(
        array &$hrc,
        int $key,
        float &$tpx,
        float &$tpy,
        float &$tpw,
        float &$tph,
    ): string {
        $previous = $this->htmlTocCellSpan;
        $table = $hrc['tablestack'][array_key_last($hrc['tablestack'])] ?? null;
        $this->htmlTocCellSpan = null;
        if ($this->htmlTocActive && $table !== null && $table['dir'] === 'rtl') {
            $column = $this->getHTMLTableNextFreeColumn($table);
            $node = $this->getHTMLDOMNode($hrc['dom'], $key);
            $span = $node['attribute']['colspan'] ?? 1;
            $this->htmlTocCellSpan = max(1, min(max(1, $table['cols'] - $column), is_numeric($span) ? (int) $span : 1));
        }
        try {
            return parent::parseHTMLTagOPENtd($hrc, $key, $tpx, $tpy, $tpw, $tph);
        } finally {
            $this->htmlTocCellSpan = $previous;
        }
    }

    /** Mirror only the active TOC cell rectangle, retaining native width/span calculation and rendering. */
    protected function getHTMLTableColX(array $table, int $colindex): float
    {
        $x = parent::getHTMLTableColX($table, $colindex);
        if ($this->htmlTocCellSpan === null) {
            return $x;
        }
        return 2 * $table['originx'] + $table['width'] - $x
            - $this->getHTMLTableColSpanWidth($table, $colindex, $this->htmlTocCellSpan);
    }

    /** Reuse native HTML/CSS classification once per TOC level, excluding its synthetic body node. */
    public function htmlTocHasBlock(string $html): bool
    {
        foreach ($this->getHTMLDOM($html) as $node) {
            if (($node['tag'] ?? false) && ($node['value'] ?? '') !== 'body'
                && (($node['block'] ?? false) || ($node['value'] ?? '') === 'br') && ($node['display'] ?? '') !== 'none') { return true; }
        }
        return false;
    }

    /** Apply a TOC's final page permutation to native destinations, bookmarks and signature appearances. */
    public function remapTocPageTargets(array $map): void
    {
        $this->remapHtmlTocAliases($map);
        foreach (['links', 'dests', 'outlines'] as $property) {
            foreach ($this->$property as &$entry) {
                if (isset($map[$entry['p']])) { $entry['p'] = $map[$entry['p']]; }
            }
            unset($entry);
        }
        if (isset($this->signature['appearance']['page'], $map[$this->signature['appearance']['page']])) {
            $this->signature['appearance']['page'] = $map[$this->signature['appearance']['page']];
        }
        foreach ($this->signature['appearance']['empty'] ?? [] as $key => $appearance) {
            if (isset($map[$appearance['page']])) { $this->signature['appearance']['empty'][$key]['page'] = $map[$appearance['page']]; }
        }
    }

    /** Configure split HTML table borders for this document, not for other workers or documents. */
    public function setOpenCell(bool $isopen): void
    {
        $this->legacyOpenCell = $isopen;
    }

    /** Read the same transaction-restored state used by native HTML table framing. */
    public function isOpenCell(): bool
    {
        return $this->legacyOpenCell;
    }

    /** Keep requested edges when closed mode is selected; native HTML parsing remains unchanged. */
    protected function sliceHTMLCellBorderStyles(array $styles, bool $continued, bool $continues): array
    {
        return $this->legacyOpenCell ? parent::sliceHTMLCellBorderStyles($styles, $continued, $continues) : $styles;
    }

    /** Configure the old document-unit list gutter; a negative value restores native automatic measurement. */
    public function setListIndentWidth(float $width): void
    {
        if (!is_finite($width)) {
            throw new \InvalidArgumentException('HTML list indentation must be finite.');
        }
        $this->legacyListIndentWidth = $width;
    }

    /** Reuse native list rendering and CSS overrides, changing only its fallback gutter width. */
    protected function getHTMLListIndentWidth(): float
    {
        return $this->legacyListIndentWidth >= 0 ? $this->legacyListIndentWidth : parent::getHTMLListIndentWidth();
    }

    /** Read dimensions without exposing mutable native Form resources to the facade. */
    public function getTemplateSize(string $id): ?array
    {
        $template = $this->getXObjectByID($id);
        return $template === null ? null : ['w' => $template['w'], 'h' => $template['h']];
    }

    /** Place an existing Form at exact legacy coordinates, without ambient cell insets or losing its outer Form. */
    public function placeTemplate(string $id, float $x, float $y, float $width, float $height): string
    {
        $cell = $this->defcell;
        $outer = $this->xobjtid;
        $this->defcell = self::ZEROCELL;
        try {
            return $this->getXObjectTemplate($id, $x, $y, $width, $height, 'T', 'L');
        } finally {
            $this->defcell = $cell;
            $this->xobjtid = $outer;
        }
    }

    /** Install RC resource adapters once, retaining upstream conformance and cache policies. */
    public function initClassObjects(
        ?Encrypt $objEncrypt = null, ?array $fileOptions = null, ?CacheInterface $cache = null,
    ): void {
        parent::initClassObjects($objEncrypt, $fileOptions, $cache);
        $this->barcode = new Barcode();
        $options = $fileOptions ?? [];
        $options['allowedPaths'] ??= $this->defaultFileAllowedPaths();
        $markup = $options['markupAllowedPaths'] ?? $options['allowedPaths'];
        unset($options['markupAllowedPaths']);
        $this->file = new ResourceFile($options);
        $options['allowedPaths'] = $markup;
        $this->markupFile = new ResourceFile($options);
        $noTransparency = !$this->isTransparencyAllowed();
        $this->page = new Page($this->unit, $this->color, $this->encrypt, $noTransparency, $this->compress, $this->sigapp);
        if ($this->pdfx) {
            $this->page->omitPageBox('ArtBox');
        }
        $this->graph = new Graph($this->kunit, 0, 0, $this->color, $this->encrypt, $noTransparency, $this->compress);
        $this->font = new FontStack($this->kunit, $this->subsetfont, $this->isunicode, $this->requiresEmbeddedFonts(), $this->file);
        $this->image = new Image(
            $this->kunit, $this->encrypt, $this->file, $this->forbidsImageAlternates(), $this->compress,
            $this->imageCacheAdapter(), $noTransparency,
        );
        $this->page->setDefaultPageTime($this->docmodtime);
    }

    /** Change measurement units without throwing away metadata, signatures, fonts or document IDs. */
    public function changeUnit(string $unit): void
    {
        $this->assertConfigurable();
        $ratio = $this->page->getUnitRatio($unit);
        $this->page->configure($ratio, $this->encrypt);
        $this->graph->configure($ratio, $this->encrypt);
        $this->font->changeUnit($ratio);
        $this->image->configure($ratio, $this->encrypt);
        $this->unit = $unit;
        $this->kunit = $ratio;
        $this->svgminunitlen = $this->toUnit(0.01);
    }

    /** Update deferred stream writers in place; existing pages, resources and encryption remain intact. */
    public function changeCompression(bool $compress): void
    {
        $this->setCompressMode($compress);
        $this->page->setStreamCompression($compress);
        $this->graph->setStreamCompression($compress);
        $this->image->setStreamCompression($compress);
    }

    /** Build encryption through upstream, then bind it atomically to every stream writer. */
    public function protect(
        array $permissions, #[\SensitiveParameter] string $user, #[\SensitiveParameter] string $owner,
        int $mode, ?array $pubkeys,
    ): void {
        $this->assertConfigurable();
        if ($this->forbidsEncryption()) {
            throw new RuntimeException('PDF encryption is forbidden by the selected PDF/A or PDF/X mode.');
        }
        $encrypt = new Encrypt(true, $this->fileid, $mode, $permissions, $user, $owner, $pubkeys);
        $this->page->configure($this->kunit, $encrypt);
        $this->graph->configure($this->kunit, $encrypt);
        $this->image->configure($this->kunit, $encrypt);
        $this->encrypt = $encrypt;
        $this->setPDFVersion($this->pdfver);
    }

    /** A later version setter must not downgrade below the encryption algorithm's required version. */
    public function setPDFVersion(string $version = '1.7'): static
    {
        parent::setPDFVersion($version);
        $encryption = isset($this->encrypt) ? $this->encrypt->getEncryptionData() : null;
        if ($encryption !== null && $encryption['encrypted']) {
            $version = match (true) {
                $encryption['V'] >= 6 => '2.0',
                $encryption['V'] >= 5 => '1.7',
                $encryption['V'] >= 4 => '1.6',
                $encryption['V'] >= 2 => '1.4',
                default => '1.1',
            };
            if (version_compare($this->pdfver, $version, '<')) {
                parent::setPDFVersion($version);
            }
        }
        return $this;
    }

    /** Repeated heads already start at the table content edge; do not apply the facade's outer cell insets again. */
    protected function replayHTMLTableHead(
        array &$hrc, string $thead, float &$tpx, float &$tpy, float &$tpw, float &$tph, int $tablekey = -1,
    ): string {
        $cell = $this->defcell;
        $this->defcell['margin'] = self::ZEROCELL['margin'];
        $this->defcell['padding'] = self::ZEROCELL['padding'];
        try {
            return parent::replayHTMLTableHead($hrc, $thead, $tpx, $tpy, $tpw, $tph, $tablekey);
        } finally {
            $this->defcell = $cell;
        }
    }

    /** Unix timestamp zero is a real date, not a request to copy the creation timestamp. */
    protected function getOutDateTimeString(int $time, int $oid): string
    {
        return $this->encrypt->escapeDataString('D:' . $this->getFormattedDate($time), $oid);
    }

    /** Refuse late configuration changes even when called outside the compatibility facade. */
    private function assertConfigurable(): void
    {
        if ($this->page->getPages() !== []) {
            throw new RuntimeException('PDF units and encryption must be configured before adding pages.');
        }
    }
}
