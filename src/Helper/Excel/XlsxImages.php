<?php

namespace RC\Helper\Excel;

use avadim\FastExcelReader\Internal\PackagePath;

/** Lazy, namespace-aware drawing integration; ordinary cell parsing remains entirely upstream. */
trait XlsxImages
{
    private bool $drawingLocated = false;
    private ?string $relatedDrawing = null;
    private ?array $drawingPartIndex = null;
    private ?array $indexedImageRows = null;

    /** Follow the worksheet's actual drawing relationship rather than guessing drawingN.xml. */
    protected function drawingFilename(): ?string
    {
        $this->assertReadable();
        if ($this->drawingLocated) { return $this->relatedDrawing; }
        $reader = $this->getReader(); $ids = [];
        try {
            $reader->openZip($this->pathInZip);
            while ($reader->read()) {
                if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'drawing'
                    && $reader->namespaceURI === 'http://schemas.openxmlformats.org/spreadsheetml/2006/main') {
                    $id = $reader->getAttributeNs('id', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                    if ($id !== null && $id !== '') { $ids[] = $id; }
                }
            }
        } finally { $reader->close(); }
        if ($ids !== []) {
            $relations = $this->drawingRelationships($this->pathInZip, 'drawing');
            foreach ($ids as $id) {
                if (isset($relations[$id])) {
                    $this->requireDrawingPart($relations[$id]);
                    $this->relatedDrawing = $relations[$id]; break;
                }
            }
        }
        $this->drawingLocated = true;
        return $this->relatedDrawing;
    }

    /** Decode OOXML relationships using the checked XML reader and upstream package-path resolver. */
    private function drawingRelationships(string $source, string $type): array
    {
        $path = dirname($source) . '/_rels/' . basename($source) . '.rels';
        if (!$this->hasDrawingPart($path)) { return []; }
        $relations = []; $reader = $this->getReader();
        try {
            $reader->openZip($path);
            while ($reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'Relationship'
                    || $reader->namespaceURI !== 'http://schemas.openxmlformats.org/package/2006/relationships'
                    || !str_ends_with((string)$reader->getAttribute('Type'), '/' . $type)
                    || strcasecmp((string)$reader->getAttribute('TargetMode'), 'External') === 0) { continue; }
                $id = (string)$reader->getAttribute('Id');
                if ($id === '') { continue; }
                if (isset($relations[$id])) { throw new \UnexpectedValueException('Duplicate XLSX drawing relationship ID.'); }
                $relations[$id] = PackagePath::resolve($source, (string)$reader->getAttribute('Target'));
            }
        } finally { $reader->close(); }
        return $relations;
    }

    /** Build an immutable ZIP member lookup only when image metadata is first requested. */
    private function hasDrawingPart(string $path): bool
    {
        $this->drawingPartIndex ??= array_fill_keys($this->excel->innerFileList(), true);
        return isset($this->drawingPartIndex[$path]);
    }

    /** A referenced internal drawing/image must be an actual member, never an external resource. */
    private function requireDrawingPart(string $path): void
    {
        if (!$this->hasDrawingPart($path)) { throw new \UnexpectedValueException('Referenced XLSX drawing or image is missing.'); }
    }

    /** Keep native image-list shapes while parsing anchors/attributes through XMLReader and DOM. */
    protected function extractDrawingInfo($xmlName): array
    {
        $relations = $this->drawingRelationships($xmlName, 'image');
        $result = ['xml' => $xmlName, 'rel' => dirname($xmlName) . '/_rels/' . basename($xmlName) . '.rels', 'images' => [], 'rows' => []];
        $reader = $this->getReader();
        $namespace = 'http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing';
        try {
            $reader->openZip($xmlName);
            while ($reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT) { continue; }
                if ($reader->depth === 0 && ($reader->localName !== 'wsDr' || $reader->namespaceURI !== $namespace)) {
                    throw new \UnexpectedValueException('Invalid XLSX drawing root.');
                }
                if ($reader->depth !== 1 || $reader->namespaceURI !== $namespace
                    || !in_array($reader->localName, ['oneCellAnchor', 'twoCellAnchor'], true)) { continue; }
                $document = new \DOMDocument();
                $anchor = $reader->expand($document);
                if (!$anchor instanceof \DOMElement) { throw new \UnexpectedValueException('Invalid XLSX image anchor.'); }
                $xpath = new \DOMXPath($document);
                $xpath->registerNamespace('xdr', $namespace);
                $xpath->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
                $column = self::imageAnchorIndex($xpath->query('./xdr:from/xdr:col', $anchor)->item(0)?->textContent ?? '', 16383);
                $row = self::imageAnchorIndex($xpath->query('./xdr:from/xdr:row', $anchor)->item(0)?->textContent ?? '', 1048575);
                foreach ($xpath->query('./xdr:pic', $anchor) as $picture) {
                    $blip = $xpath->query('./xdr:blipFill/a:blip', $picture)->item(0);
                    $id = $blip?->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'embed');
                    if ($id === null || !isset($relations[$id])) { continue; }
                    $target = $relations[$id]; $this->requireDrawingPart($target);
                    $colLetter = \avadim\FastExcelReader\Excel::colLetter((int)$column + 1);
                    $rowNumber = (string)((int)$row + 1); $cell = $colLetter . $rowNumber;
                    $properties = $xpath->query('./xdr:nvPicPr/xdr:cNvPr', $picture)->item(0);
                    $name = $properties?->hasAttribute('name') ? $properties->getAttribute('name') : $cell;
                    if (!isset($result['images'][$cell])) { $result['rows'][$rowNumber][] = $cell; }
                    $result['images'][$cell] = ['rId' => $id, 'name' => $name, 'colIdx' => (int)$column, 'col' => $colLetter,
                        'rowIdx' => (int)$row, 'row' => $rowNumber, 'cell' => $cell, 'target' => $target];
                }
            }
        } finally { $reader->close(); }
        // Publish only after parsing succeeds, so a failed scan cannot leave a partially populated cache.
        foreach ($result['images'] as $cell => $image) { $this->addImage($cell, basename($image['target']), $image['name']); }
        return $result;
    }

    /** Accept XML integer lexical forms without overflowing or rejecting leading zeros/signs. */
    private static function imageAnchorIndex(string $text, int $maximum): int
    {
        $text = trim($text); $negative = str_starts_with($text, '-');
        if ($negative || str_starts_with($text, '+')) { $text = substr($text, 1); }
        if (!ctype_digit($text)) { throw new \UnexpectedValueException('Invalid XLSX image anchor integer.'); }
        $text = ltrim($text, '0');
        if ($text === '') { return 0; }
        if ($negative || strlen($text) > strlen((string)$maximum) || ($index = (int)$text) > $maximum) {
            throw new \UnexpectedValueException('XLSX image anchor exceeds worksheet bounds.');
        }
        return $index;
    }

    /** Count actual cell entries, including repeated rich images also used by floating drawings. */
    public function countImages(): int
    {
        $this->assertReadable();
        if ($this->countImages === -1) {
            $images = $this->images; $drawing = $this->props['drawings'] ?? null;
            try {
                $this->_countDrawingsImages();
                if ($this->excel->hasRichValueImages()) { $this->extractRichValueImages(0); }
                $this->countImages = count($this->images);
            } catch (\Throwable $error) {
                $this->images = $images;
                if ($drawing === null) { unset($this->props['drawings']); } else { $this->props['drawings'] = $drawing; }
                throw $error;
            }
        }
        return $this->countImages;
    }

    /** Scan every cell; distinct metadata/media counts are not a limit on repeated cell references. */
    protected function extractRichValueImages(int $numImages)
    {
        $reader = $this->getReader(); $images = [];
        try {
            $reader->openZip($this->pathInZip);
            while ($reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'c'
                    || $reader->namespaceURI !== 'http://schemas.openxmlformats.org/spreadsheetml/2006/main') { continue; }
                $vm = $reader->getAttribute('vm');
                if ($vm === null || $vm === '') { continue; }
                $vm = self::imageAnchorIndex($vm, 4294967295);
                $target = $this->excel->metadataImage($vm);
                if ($target === null) { continue; }
                $cell = strtoupper((string)$reader->getAttribute('r'));
                if (!preg_match('/\A([A-Z]{1,3})([1-9][0-9]*)\z/D', $cell, $parts)
                    || \avadim\FastExcelReader\Excel::colNum($parts[1]) > 16384) { throw new \UnexpectedValueException('Invalid XLSX rich-image cell address.'); }
                self::imageAnchorIndex($parts[2], 1048576);
                $images[$cell] = ['file' => $target, 'name' => $this->excel->metadataImageName($vm), 'vm' => $vm];
            }
        } finally { $reader->close(); }
        foreach ($images as $cell => $image) { $this->addImage($cell, basename($image['file']), $image['name'], ['r' => $cell, 'vm' => $image['vm'], 'target' => $image['file']]); }
    }

    /** Select one consistent source for image lists, byte reads and extraction; in-cell metadata wins a cell collision. */
    private function imageTarget(string $cell): ?string
    {
        $this->countImages(); $cell = strtoupper($cell);
        return $this->images[$cell]['meta']['target'] ?? $this->props['drawings']['images'][$cell]['target'] ?? null;
    }

    /** Keep rich and floating image reads on validated internal ZIP entries. */
    public function imageEntryFullPath(string $cell): ?string
    {
        $target = $this->imageTarget($cell);
        return $target === null ? null : 'zip://' . $this->zipFilename . '#' . $target;
    }

    /** Return the same display name exposed by getImageList(), including rich-image descriptions. */
    public function getImageName(string $cell): ?string
    {
        $this->countImages(); return $this->images[strtoupper($cell)]['image_name'] ?? null;
    }

    /** Build the row index only for row-list callers, then read just the requested row. */
    public function getImageListByRow($row): array
    {
        $this->countImages(); $result = [];
        if ($this->indexedImageRows === null) {
            $rows = [];
            foreach ($this->images as $cell => $_) { $rows[substr($cell, strspn($cell, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'))][] = $cell; }
            $this->indexedImageRows = $rows;
        }
        foreach ($this->indexedImageRows[(string)$row] ?? [] as $cell) {
            $image = $this->images[$cell];
            $result[$cell] = ['image_name' => $image['image_name'], 'file_name' => $image['file_name']];
        }
        return $result;
    }

    /** Preserve native extraction behavior but derive default names from either supported image source. */
    public function saveImage(string $cell, ?string $filename = null): ?string
    {
        $target = $this->imageTarget($cell);
        return $target === null ? null : parent::saveImage($cell, $filename ?: basename($target));
    }

    /** Support $reader->sheet()->saveImageTo('B4', $directory) as the first image call for either image kind. */
    public function saveImageTo(string $cell, string $dirname): ?string
    {
        $target = $this->imageTarget($cell);
        return $target === null ? null : $this->saveImage($cell, str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $dirname) . DIRECTORY_SEPARATOR . basename($target));
    }
}
