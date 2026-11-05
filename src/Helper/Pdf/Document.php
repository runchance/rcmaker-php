<?php

declare(strict_types=1);

namespace RC\Helper\Pdf;

use InvalidArgumentException;
use RuntimeException;

require_once __DIR__ . '/bootstrap.php';

/** TCPDF-compatible document whose I/D/FI/FD output returns an RC response instead of echoing. */
class Document extends \TCPDF
{
    use EpsImport, LegacyParameters, HtmlCalls, HtmlToc;

    public ?object $request = null;
    protected array $resourceOptions;
    protected string $outputName = 'doc.pdf';
    protected bool $localFilesAllowed = true;
    protected bool $renderFailed = false;
    private ?array $templateState = null;

    /** Estimate plain-text cell lines with native word wrapping; measurement never moves the document cursor. */
    public function getNumLines($txt, $w = 0, $reseth = false, $autopadding = true, $cellpadding = null, $border = 0)
    {
        if ($txt === null) { return 0; }
        if ($txt === '') { return 1; }
        $saved = $this->cellpadding;
        try {
            if (is_array($cellpadding)) { $this->cellpadding = array_replace($saved, $cellpadding); }
            $padding = $this->legacyBorderCellMetrics($border)['padding'];
            $width = (float) $w;
            if ($width <= 0) {
                $width = $this->rtlmode ? $this->posx - $this->lmargin : $this->getPageWidth() - $this->rmargin - $this->posx;
            }
            $width -= $padding['L'] + $padding['R'];
            if (!is_finite($width) || $width <= 0) { throw new InvalidArgumentException('Text measurement requires positive finite inner width.'); }
            $this->currentFontMetric();
            return $this->engine()->measureTextLineCount((string) $txt, $width, $this->forcedTextDir());
        } finally { $this->cellpadding = $saved; }
    }

    /** Return measured plain-text height in document units, including temporary vertical padding when requested. */
    public function getStringHeight($w, $txt, $reseth = false, $autopadding = true, $cellpadding = null, $border = 0)
    {
        $saved = $this->cellpadding;
        try {
            if (is_array($cellpadding)) { $this->cellpadding = array_replace($saved, $cellpadding); }
            $this->cellpadding = $this->legacyBorderCellMetrics($border)['padding'];
            $lines = $this->getNumLines($txt, $w, $reseth, $autopadding, $cellpadding, $border);
            return $this->getCellHeight($lines * $this->getFontSize(), $autopadding);
        } finally { $this->cellpadding = $saved; }
    }

    /** Render trusted per-level HTML templates; call between addTOCPage() and endTOCPage(). */
    public function addHTMLTOC($page = null, $toc_name = 'TOC', $templates = [], $correct_align = true, $style = '', $color = [0, 0, 0])
    {
        $this->writeLegacyHtmlToc($page, $toc_name, $templates, $correct_align, $style, $color);
    }

    /** Call setOpenCell(false) to close existing borders on both sides of a page break. */
    public function setOpenCell($isopen)
    {
        $this->engine()->setOpenCell((bool) $isopen);
    }

    /** Reuse native per-page framing without removing requested top/bottom edges in closed mode. */
    protected function drawHtmlCellSegments(
        float $posx, float $width, float $starty, float $bottom, int $startpage,
        int $contentstart, array $styles, bool $fill, ?int $endpage = null,
    ): void {
        if ($this->engine()->isOpenCell()) {
            parent::drawHtmlCellSegments($posx, $width, $starty, $bottom, $startpage, $contentstart, $styles, $fill, $endpage);
            return;
        }
        $endpage ??= $this->getPage();
        $breaky = $this->getPageHeight() - $this->bmargin;
        for ($page = $startpage; $page <= $endpage; ++$page) {
            $top = $page === $startpage ? $starty : $this->tmargin;
            parent::drawHtmlCellSegments(
                $posx, $width, $top, max($top, $page === $endpage ? $bottom : $breaky),
                $page, $page === $startpage ? $contentstart : 0, $styles, $fill, $page,
            );
        }
    }

    /** Set HTML-list indentation in document units; negative restores automatic width, retaining the legacy float return. */
    public function setListIndentWidth($width)
    {
        $width = (float) $width;
        $this->engine()->setListIndentWidth($width);
        return $width;
    }

    /** Start a legacy Form canvas; endTemplate() restores the caller's cursor, columns and graphic settings. */
    public function startTemplate($w = 0, $h = 0, $group = false)
    {
        if ($this->xobjtid !== '') {
            return false;
        }
        if ($this->docstate !== 2) {
            return parent::startTemplate($w, $h, $group);
        }
        if (!is_finite((float) $w) || !is_finite((float) $h)) {
            throw new InvalidArgumentException('Template dimensions must be finite and positive.');
        }
        $width = (float) $w > 0 ? (float) $w : $this->getPageWidth() - $this->lmargin - $this->rmargin;
        $height = (float) $h > 0 ? (float) $h : $this->getPageHeight() - $this->tmargin;
        if (!is_finite($width) || !is_finite($height) || $width <= 0 || $height <= 0) {
            throw new InvalidArgumentException('Template dimensions must be finite and positive.');
        }
        $transparency = null;
        if ($group !== false && $group !== null) {
            $options = is_array($group) ? $group : [];
            $colorSpace = (string) ($options['CS'] ?? 'DeviceRGB');
            $colorSpace = $colorSpace === '' ? 'DeviceRGB' : $colorSpace;
            if (preg_match('/\A[A-Za-z0-9_.-]+\z/D', $colorSpace) !== 1) {
                throw new InvalidArgumentException('Template group color space must be a PDF name without syntax.');
            }
            $transparency = ['CS' => $colorSpace, 'I' => ($options['I'] ?? false) === true, 'K' => ($options['K'] ?? false) === true];
        }
        $saved = [];
        foreach (['fontfamily', 'fontstyle', 'fontdecor', 'fontsizept', 'fontmetric', 'fontspacing', 'fontstretching',
            'cellpadding', 'cellmargin', 'linewidth', 'linestyle', 'textrendermode', 'alpha',
            'textcolorspec', 'drawcolorspec', 'fillcolorspec', 'textcolorlegacy', 'drawcolorlegacy', 'fillcolorlegacy',
            'cellheightratio', 'lasth', 'posx', 'posy', 'lmargin', 'rmargin', 'tmargin', 'bmargin', 'autopagebreak',
            'pagecolumns', 'pagecolumnwidth', 'pageregions'] as $property) {
            $saved[$property] = $this->$property;
        }
        $this->templateState = ['facade' => $saved, 'break_height' => $this->getPageHeight(),
            'page' => $this->engine()->page->beginTemplateLayout($width, $height)];
        $this->posx = $this->posy = 0.0;
        $this->lmargin = $this->rmargin = $this->tmargin = $this->bmargin = 0.0;
        $this->autopagebreak = false;
        $this->pagecolumns = 0;
        $this->pagecolumnwidth = 0.0;
        $this->pageregions = [];
        try {
            $this->xobjtid = $this->engine()->newXObjectTemplate($width, $height, $transparency);
            $this->xobjheight = $height;
            return $this->xobjtid;
        } catch (\Throwable $error) {
            if ($this->xobjtid !== '') { parent::endTemplate(); }
            $this->restoreTemplateState();
            throw $error;
        }
    }

    /** Finish the Form without leaking its canvas or font selection into subsequent page content. */
    public function endTemplate()
    {
        $id = parent::endTemplate();
        if ($id !== false && $this->templateState !== null) {
            $this->restoreTemplateState();
        }
        return $id;
    }

    /** Place an existing Form with legacy size, page alignment and T/M/B/N cursor semantics. */
    public function printTemplate($id, $x = null, $y = null, $w = 0, $h = 0, $align = '', $palign = '', $fitonpage = false)
    {
        if ($this->docstate !== 2) { return; }
        $id = (string) $id;
        if ($id === $this->xobjtid) { $this->endTemplate(); }
        $template = $this->engine()->getTemplateSize($id);
        if ($template === null) { throw new RuntimeException('Unknown PDF template identifier.'); }
        $x = $x === null || (string) $x === '' ? $this->posx : (float) $x;
        $y = $y === null || (string) $y === '' ? $this->posy : (float) $y;
        $width = (float) $w;
        $height = (float) $h;
        if (!array_all([$x, $y, $width, $height], static fn(float $value): bool => is_finite($value))) {
            throw new InvalidArgumentException('Template position and dimensions must be finite.');
        }
        if ($width <= 0 && $height <= 0) {
            $width = $template['w']; $height = $template['h'];
        } elseif ($width <= 0) {
            $width = $height * $template['w'] / $template['h'];
        } elseif ($height <= 0) {
            $height = $width * $template['h'] / $template['w'];
        }
        [$width, $height, $x, $y] = $this->fitLegacyGraphicBlock($width, $height, $x, $y, (bool) $fitonpage);
        $left = match (strtoupper((string) $palign)) {
            'L' => $this->lmargin,
            'C' => ($this->getPageWidth() + $this->lmargin - $this->rmargin - $width) / 2,
            'R' => $this->getPageWidth() - $this->rmargin - $width,
            default => $this->rtlmode ? $x - $width : $x,
        };
        if ($this->xobjtid !== '') { $this->engine()->addXObjectXObjectID($this->xobjtid, $id); }
        $this->emitToPage($this->engine()->placeTemplate($id, $left, $y, $width, $height));
        $this->advanceAfterBarcode((string) $align, '', $this->rtlmode ? $left + $width : $left, $y, $width, $height);
        if (strtoupper((string) $align) === 'N') {
            $this->posy = min($this->getPageHeight(), max(0.0, $this->posy));
        }
    }

    /** Restore saved values, not the whole engine, so newly imported Form resources stay available. */
    private function restoreTemplateState(): void
    {
        $saved = $this->templateState;
        $this->templateState = null;
        $this->engine()->page->endTemplateLayout($saved['page']);
        foreach ($saved['facade'] as $property => $value) {
            $this->$property = $value;
        }
        if ($this->fontmetric !== []) {
            $this->setFont($this->fontfamily, $this->getFontStyle(), $this->fontsizept);
        }
    }

    /** Use RC\Helper\Pdf or pdf($request, $config) to construct a configured document. */
    public function __construct(
        $orientation = 'P', $unit = 'mm', $format = 'A4', $unicode = true,
        $encoding = 'UTF-8', $diskcache = false, $pdfa = false, array $resources = [],
    ) {
        if (strtoupper((string) $encoding) !== 'UTF-8') {
            throw new InvalidArgumentException('PDF text must use UTF-8; convert legacy encodings before writing.');
        }
        $this->resourceOptions = $resources;
        $time = time();
        $this->doctimestamps = ['create' => $time, 'modify' => $time];
        parent::__construct($orientation, $unit, $format, $unicode, $encoding, $diskcache, $pdfa);
    }

    /** Always throw so an invalid document cannot terminate a resident application worker. */
    public function Error($msg)
    {
        throw new RuntimeException('TCPDF: ' . (string) $msg);
    }

    /** Construct once; later unit changes preserve the configured engine instead of replacing it. */
    protected function engineInit(string $unit): void
    {
        $unit = strtolower(trim($unit));
        $unit = $unit === '' ? 'mm' : $unit;
        if ($this->eng instanceof Engine) {
            $this->eng->changeUnit($unit);
        } else {
            $this->eng = new Engine($unit, $this->unicode, $this->fontsubsetting, true, $this->pdfamode, null, $this->fileOptions());
            $this->eng->pagecontexthook = $this->ambientPageContent(...);
            $this->eng->setDocCreationDate($this->doctimestamps['create']);
            $this->eng->setDocModificationDate($this->doctimestamps['modify']);
        }
        $this->docunit = $unit;
        $this->kratio = $this->eng->toPoints(1.0);
    }

    /** Keep existing configuration when protecting a document; explicit legacy RC4 modes retain upstream warnings. */
    public function setProtection(
        $permissions = ['print', 'modify', 'copy', 'annot-forms', 'fill-forms', 'extract', 'assemble', 'print-high'],
        #[\SensitiveParameter] $user_pass = '', #[\SensitiveParameter] $owner_pass = null, $mode = 2, $pubkeys = null,
    ) {
        if ($this->docstate >= 2 || $this->renderFailed) {
            throw new RuntimeException('setProtection() must be called before adding pages.');
        }
        $this->eng->protect(
            array_map(static fn(mixed $value): string => (string) $value, is_array($permissions) ? array_values($permissions) : []),
            (string) $user_pass, (string) ($owner_pass ?? ''), (int) $mode,
            is_array($pubkeys) && $pubkeys !== [] ? array_values($pubkeys) : null,
        );
    }

    /** Finished documents no longer need the facade-bound page callback. */
    public function Close()
    {
        if ($this->renderFailed) {
            throw new RuntimeException('PDF finalization previously failed; create a new document or roll back a layout transaction.');
        }
        try {
            parent::Close();
        } catch (\Throwable $error) {
            $this->renderFailed = true;
            if ($this->eng instanceof \TCPDF_ENGINE) {
                $this->eng->pagecontexthook = null;
            }
            throw $error;
        }
        $this->commitTransaction();
        if ($this->eng instanceof \TCPDF_ENGINE) {
            $this->eng->pagecontexthook = null;
        }
    }

    /** Limit markup assets to public resources unless the developer explicitly grants more roots. */
    protected function fileOptions(): array
    {
        $fontRoot = dirname(__DIR__, 2) . '/ThirdParty/tc-lib-pdf-font/fonts';
        static $engineAssets = null;
        $engineAssets ??= dirname((new \ReflectionClass(\Com\Tecnick\Pdf\Tcpdf::class))->getFileName()) . '/include';
        $project = defined('BASE_PATH') ? BASE_PATH : getcwd();
        $public = function_exists('public_path') ? public_path() : $project . '/public';
        $extra = defined('K_ALLOWED_PATHS') && is_array(K_ALLOWED_PATHS) ? K_ALLOWED_PATHS : [];
        return [
            'allowedPaths' => array_merge([$fontRoot, K_PATH_FONTS, $engineAssets], $this->localFilesAllowed
                ? array_merge([$project], $extra, $this->resourceOptions['allowed_paths'] ?? []) : []),
            'markupAllowedPaths' => $this->localFilesAllowed
                ? array_merge([$public], $extra, $this->resourceOptions['markup_allowed_paths'] ?? []) : [],
            'allowedHosts' => $this->resourceOptions['allowed_hosts'] ?? (defined('K_ALLOWED_HOSTS') ? K_ALLOWED_HOSTS : []),
            'maxRemoteSize' => $this->resourceOptions['max_remote_size'] ?? 8 * 1024 * 1024,
        ];
    }

    /** Disable new local asset reads without disabling the fonts required to produce a PDF. */
    public function setAllowLocalFiles($allowLocalFiles)
    {
        $this->localFilesAllowed = (bool) $allowLocalFiles;
        $options = $this->fileOptions();
        $this->engine()->file->setAllowedPaths($options['allowedPaths']);
        $this->engine()->markupFile->setAllowedPaths($options['markupAllowedPaths']);
    }

    /** Resolve remote Image() dimensions through the same policy/cache as its later native image import. */
    protected function imagePixelSize(string $file): array
    {
        $url = str_starts_with($file, '*') ? substr($file, 1) : $file;
        if (strncasecmp($url, 'http://', 7) === 0 || strncasecmp($url, 'https://', 8) === 0) {
            return $this->engine()->image->originalDimensions($file, $this->jpegquality);
        }
        return parent::imagePixelSize($file);
    }

    /** Render normally, returning native logical text rows rather than a padded/minimum-height box estimate. */
    public function MultiCell(
        $w, $h, $txt, $border = 0, $align = 'J', $fill = false, $ln = 1, $x = null, $y = null,
        $reseth = true, $stretch = 0, $ishtml = false, $autopadding = true, $maxh = 0, $valign = 'T', $fitcell = false,
    ) {
        if ($ishtml && $this->docstate === 2) {
            $this->writeHTMLCell($w, $h, $x, $y, $txt, $border, $ln, $fill, $reseth, $align, $autopadding);
            return 1;
        }
        if ($ishtml || $this->docstate !== 2) {
            return parent::MultiCell($w, $h, $txt, $border, $align, $fill, $ln, $x, $y, $reseth, $stretch, $ishtml, $autopadding, $maxh, $valign, $fitcell);
        }
        $text = (string) $txt;
        $engine = $this->engine();
        if (!$engine instanceof Engine) {
            return parent::MultiCell($w, $h, $text, $border, $align, $fill, $ln, $x, $y, $reseth, $stretch, false, $autopadding, $maxh, $valign, $fitcell);
        }
        $scope = $engine->beginMultiCellLineCapture($this->preserveBlankLines($text));
        try {
            $result = parent::MultiCell($w, $h, $text, $border, $align, $fill, $ln, $x, $y, $reseth, $stretch, false, $autopadding, $maxh, $valign, $fitcell);
        } finally { $lines = $engine->endMultiCellLineCapture($scope); }
        if ($lines === null) { return $result; }
        return max(1, $lines);
    }

    /** Keep the HTML callback switch authoritative for both legacy tags and the native renderer. */
    public function writeHTMLCell(
        $w, $h, $x, $y, $html = '', $border = 0, $ln = 0, $fill = false,
        $reseth = true, $align = '', $autopadding = true,
    ) {
        $html = (string) $html;
        if ($x !== null && (string) $x !== '' && ($this->rtlmode || (float) $x <= 0.0)) {
            $this->setX($x);
            $x = $this->posx;
        }
        if ($y !== null && (string) $y !== '' && (float) $y <= 0.0) {
            $this->setY($y, false);
            $y = $this->posy;
        }
        if (preg_match('~</?\s*tcpdf\b~i', $html) === 1) {
            return $this->writeHtmlCallbacks($w, $h, $x, $y, $html, $border, $ln, $fill, $reseth, $align, $autopadding);
        }
        return parent::writeHTMLCell($w, $h, $x, $y, $html, $border, $ln, $fill, $reseth, $align, $autopadding);
    }

    /** Call setCompression(false) before Output()/Close() to disable optional stream compression. */
    public function setCompression($compress = true)
    {
        $this->eng->changeCompression((bool) $compress);
    }

    /** Preserve built-in CJK aliases; the font stack owns JSON and literal-only legacy definition loading. */
    public function setFont($family, $style = '', $size = null, $fontfile = '', $subset = 'default', $out = true)
    {
        $family = $this->fontAlias((string) $family);
        return parent::setFont($family, $style, $size, $fontfile, $subset, $out);
    }

    /** Apply the same CJK aliases when registering a font without selecting it. */
    public function AddFont($family, $style = '', $fontfile = '', $subset = 'default')
    {
        return parent::AddFont($this->fontAlias((string) $family), $style, $fontfile, $subset);
    }

    /** Return bytes, a MIME attachment, an RC response, or save a local file according to dest. */
    public function Output($name = 'doc.pdf', $dest = 'I')
    {
        $dest = is_bool($dest) ? ($dest ? 'D' : 'F') : strtoupper((string) $dest);
        $dest = $dest === '' ? 'I' : $dest;
        if (!in_array($dest, ['I', 'D', 'F', 'FI', 'FD', 'S', 'E'], true)) {
            throw new InvalidArgumentException('Invalid PDF output destination: ' . $dest);
        }
        $response = in_array($dest, ['I', 'D', 'FI', 'FD'], true);
        if ($response && ($this->request === null || !is_callable([$this->request, 'response']))) {
            throw new RuntimeException('PDF response output requires a request; use S or F in standalone CLI code.');
        }
        $name = (string) $name;
        $name = $name === '' ? 'doc.pdf' : $name;
        if (preg_match('/[\x00-\x1f\x7f]/', $name) || preg_match('//u', $name) !== 1) {
            throw new InvalidArgumentException('PDF filename must be valid UTF-8 without control characters.');
        }
        $this->outputName = basename(str_replace('\\', '/', $name));
        if ($this->outputName === '' || in_array($this->outputName, ['.', '..'], true)) {
            throw new InvalidArgumentException('PDF filename is empty.');
        }
        $data = $this->getPDFData();
        if (str_starts_with($dest, 'F')) {
            $this->saveFile($name, $data);
        }
        if ($dest === 'S') {
            return $data;
        }
        $ascii = preg_replace('/[^A-Za-z0-9_.-]/', '_', $this->outputName);
        $ascii = trim($ascii, '.') === '' ? 'doc.pdf' : $ascii;
        $disposition = 'filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($this->outputName);
        if ($dest === 'E') {
            return "Content-Type: application/pdf\r\nContent-Transfer-Encoding: base64\r\n"
                . 'Content-Disposition: attachment; ' . $disposition . "\r\n\r\n"
                . chunk_split(base64_encode($data), 76, "\r\n");
        }
        if (!$response) {
            return '';
        }
        return $this->request->response($data, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string) strlen($data),
            'Content-Disposition' => (str_ends_with($dest, 'D') ? 'attachment; ' : 'inline; ') . $disposition,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Return the actual basename selected by Output(), including Unicode characters. */
    public function getPDFFilename()
    {
        return $this->outputName;
    }

    /** Final document numbering starts at this nonnegative value; totals follow legacy last-page numbering. */
    public function setStartingPageNumber($num = 1)
    {
        if ($this->docstate === 3 || $this->renderFailed) {
            throw new RuntimeException('Cannot change page numbering on a finalized or failed PDF.');
        }
        $this->startingpagenumber = max(0, (int) $num);
        $this->eng->setStartingPageNumber($this->startingpagenumber);
    }

    /** Return a deferred document-total alias usable in body text, headers and footers. */
    public function getAliasNbPages()
    {
        return '{nb}';
    }

    /** Return a deferred document-page alias; it follows the final page order after move/delete. */
    public function getAliasNumPage()
    {
        return '{pnb}';
    }

    /** Return a deferred total for the page's group, independent of the document numbering offset. */
    public function getPageGroupAlias()
    {
        return '{gnb}';
    }

    /** Return a deferred position within the page's group, usable before the group is complete. */
    public function getPageNumGroupAlias()
    {
        return '{gpnb}';
    }

    /** Set Info/XMP creation time; accepts Unix seconds, a date string or DateTimeInterface. */
    public function setDocCreationTimestamp($time)
    {
        $time = $this->normalizeTimestamp($time);
        $this->engine()->setDocCreationDate($time);
        $this->doctimestamps['create'] = $time;
    }

    /** Set Info/XMP modification time and the default modification time of subsequent pages. */
    public function setDocModificationTimestamp($time)
    {
        $time = $this->normalizeTimestamp($time);
        $this->engine()->setDocModificationDate($time);
        $this->doctimestamps['modify'] = $time;
    }

    /** Layout snapshots contain document state, never the live request or connection. */
    public function startTransaction()
    {
        if ($this->docstate === 3 || $this->renderFailed) {
            throw new RuntimeException('Cannot start a layout transaction on a finalized or failed PDF.');
        }
        $this->commitTransaction();
        $request = $this->request;
        $this->request = null;
        $flow = $this->eng instanceof Engine ? $this->eng->suspendHtmlFlow() : null;
        try {
            parent::startTransaction();
        } finally {
            if ($flow !== null) { $this->eng->restoreHtmlFlow($flow); }
            $this->request = $request;
        }
    }

    /** Discard a layout snapshot and its bound callback without waiting for cyclic GC. */
    public function commitTransaction()
    {
        if ($this->transactionsnapshot instanceof self && $this->transactionsnapshot->eng instanceof \TCPDF_ENGINE) {
            $this->transactionsnapshot->eng->pagecontexthook = null;
        }
        parent::commitTransaction();
    }

    /** Consume the snapshot once, transferring its engine and retaining the live transport. */
    public function rollbackTransaction($self = false)
    {
        $snapshot = $this->transactionsnapshot;
        if (!$snapshot instanceof self) {
            return $this;
        }
        $request = $this->request;
        $oldEngine = $this->eng;
        $document = parent::rollbackTransaction($self);
        $this->transactionsnapshot = null;
        if ($self) {
            // The upstream parent cannot restore private state declared by this RC subclass.
            $this->templateState = $snapshot->templateState;
            // Ownership moves to this facade; the discarded snapshot must not retain the engine.
            $snapshot->eng = null;
            if ($oldEngine instanceof \TCPDF_ENGINE && $oldEngine !== $this->eng) {
                $oldEngine->pagecontexthook = null;
            }
        }
        $document->request = $request;
        return $document;
    }

    /** Validate explicit metadata changes before touching either facade or engine state. */
    private function normalizeTimestamp(mixed $value): int
    {
        if ($this->docstate === 3 || $this->renderFailed) {
            throw new RuntimeException('Cannot change timestamps on a finalized or failed PDF.');
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            if (is_numeric($value)) {
                $time = filter_var($value, FILTER_VALIDATE_INT);
            } else {
                $parts = date_parse($value);
                $time = $parts['error_count'] === 0 && $parts['warning_count'] === 0 ? strtotime($value) : false;
            }
            if ($time !== false) {
                return $time;
            }
        }
        throw new InvalidArgumentException('PDF timestamps require Unix integer seconds, a valid date string or DateTimeInterface.');
    }

    /** Keep the names previously shipped by RC; the new engine owns their font parsing. */
    private function fontAlias(string $family): string
    {
        return match (strtolower($family)) {
            'stsongstdlight' => 'cid0cs', 'msungstdlight' => 'cid0ct',
            'kozminproregular' => 'cid0jp', 'hysmyeongjostdmedium' => 'cid0kr',
            default => $family,
        };
    }

    /** Stage beside the target and rename only after a complete write; never truncate the old file. */
    private function saveFile(string $name, string $data): void
    {
        if (str_contains($name, '://') || preg_match('/^(?![A-Za-z]:[\\\\\/])[A-Za-z][A-Za-z0-9+.-]*:/', $name)) {
            throw new InvalidArgumentException('PDF output requires a local filesystem path.');
        }
        $directory = realpath(dirname($name));
        if ($directory === false || !is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException('PDF output directory must already exist and be writable.');
        }
        $target = $directory . DIRECTORY_SEPARATOR . basename($name);
        if (is_link($target) || (file_exists($target) && !is_file($target))) {
            throw new RuntimeException('PDF output cannot replace a symlink or directory.');
        }
        $stage = $directory . DIRECTORY_SEPARATOR . '.rc-pdf-' . bin2hex(random_bytes(16));
        $handle = @fopen($stage, 'x+b');
        if ($handle === false) {
            throw new RuntimeException('Cannot create staged PDF output.');
        }
        $published = false;
        try {
            if (DIRECTORY_SEPARATOR !== '\\' && !chmod($stage, 0600)) {
                throw new RuntimeException('Cannot restrict staged PDF permissions.');
            }
            $length = strlen($data);
            for ($offset = 0; $offset < $length; $offset += $written) {
                $written = fwrite($handle, substr($data, $offset, 1048576));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Incomplete PDF write.');
                }
            }
            if (!fflush($handle)) {
                throw new RuntimeException('Cannot flush PDF output.');
            }
            $closed = fclose($handle);
            $handle = null;
            if (!$closed) {
                throw new RuntimeException('Cannot publish PDF output; the existing file was not removed.');
            }
            $published = @rename($stage, $target);
            if (!$published && PHP_OS_FAMILY === 'Windows') {
                for ($attempt = 0; $attempt < 3 && !$published; ++$attempt) {
                    clearstatcache(true, $target);
                    if (is_link($target) || is_dir($target) || (file_exists($target) && !is_writable($target))) {
                        break;
                    }
                    usleep(1000 << $attempt);
                    $published = @rename($stage, $target);
                }
            }
            if (!$published) {
                throw new RuntimeException('Cannot publish PDF output; the existing file was not removed.');
            }
        } catch (\Throwable $error) {
            try {
                if (is_resource($handle)) { fclose($handle); }
            } catch (\Throwable) { /* Preserve the write/publication failure and still attempt removal. */ }
            // Successful rename releases this name; a later file at it belongs to another owner.
            try {
                if (!$published && is_file($stage)) { unlink($stage); }
            } catch (\Throwable) { /* A failed cleanup must not replace the original exception. */ }
            throw $error;
        }
    }
}
