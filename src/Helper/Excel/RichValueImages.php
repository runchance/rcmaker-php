<?php

namespace RC\Helper\Excel;

use avadim\FastExcelReader\Internal\PackagePath;

/** MS-XLSX local-image index adaptation; the upstream workbook/cell parser remains unchanged. */
trait RichValueImages
{
    private const RICH_NS = 'http://schemas.microsoft.com/office/spreadsheetml/2017/richdata';
    private const META_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const IMAGE_REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** Resolve vm -> metadata block -> rich value -> structure field -> relationship slot -> image. */
    protected function _loadMetadataImages(string $metadataFile, string $richValueRelFile)
    {
        $values = $this->richMetadata($metadataFile);
        if ($values === []) { return; }
        $structures = [];
        foreach ($this->richNodes($this->richPart(['rdRichValueStructure', 'richValueStructure']), 'rvStructures', 's', self::RICH_NS) as $index => $node) {
            $definition = ['image' => null, 'text' => null];
            if ($node->getAttribute('t') === '_localImage') {
                foreach (self::richChildren($node, self::RICH_NS, 'k') as $position => $key) {
                    if ($key->getAttribute('n') === '_rvRel:LocalImageIdentifier') {
                        if ($key->getAttribute('t') !== 'i' || $definition['image'] !== null) { throw new \UnexpectedValueException('Invalid XLSX local-image structure.'); }
                        $definition['image'] = $position;
                    } elseif ($key->getAttribute('n') === 'Text' && $key->getAttribute('t') === 's') { $definition['text'] = $position; }
                }
                if ($definition['image'] === null) { throw new \UnexpectedValueException('XLSX local-image structure has no image identifier.'); }
            }
            $structures[$index] = $definition;
        }
        $needed = array_fill_keys(array_values($values), true); $records = []; $found = [];
        foreach ($this->richNodes($this->richPart(['rdRichValue', 'richValue']), 'rvData', 'rv', self::RICH_NS) as $index => $node) {
            if (!isset($needed[$index])) { continue; }
            $found[$index] = true; $structure = self::richIndex($node->getAttribute('s'));
            if (!isset($structures[$structure])) { throw new \UnexpectedValueException('XLSX rich-value structure reference is missing.'); }
            $definition = $structures[$structure];
            if ($definition['image'] === null) { continue; }
            $fields = self::richChildren($node, self::RICH_NS, 'v');
            if (!isset($fields[$definition['image']])) { throw new \UnexpectedValueException('XLSX local-image value is missing.'); }
            $records[$index] = ['slot' => self::richIndex($fields[$definition['image']]->textContent),
                'description' => $definition['text'] !== null && isset($fields[$definition['text']]) ? $fields[$definition['text']]->textContent : null];
        }
        if (array_diff_key($needed, $found) !== []) { throw new \UnexpectedValueException('XLSX rich-value reference is missing.'); }
        if ($records === []) { return; }
        $slots = [];
        foreach ($this->richNodes($richValueRelFile, 'richValueRels', 'rel', 'http://schemas.microsoft.com/office/spreadsheetml/2022/richvaluerel') as $index => $node) {
            $slots[$index] = $node->getAttributeNS(self::IMAGE_REL_NS, 'id');
        }
        $targets = []; $relationships = dirname($richValueRelFile) . '/_rels/' . basename($richValueRelFile) . '.rels';
        foreach ($this->richNodes($relationships, 'Relationships', 'Relationship', 'http://schemas.openxmlformats.org/package/2006/relationships') as $node) {
            if (!str_ends_with($node->getAttribute('Type'), '/image')) { continue; }
            $id = $node->getAttribute('Id');
            if ($id === '' || array_key_exists($id, $targets)) { throw new \UnexpectedValueException('Invalid XLSX rich-image relationship ID.'); }
            $targets[$id] = strcasecmp($node->getAttribute('TargetMode'), 'External') === 0 ? null
                : $this->richExistingPart(PackagePath::resolve($richValueRelFile, $node->getAttribute('Target')));
        }
        $resolved = [];
        foreach ($values as $metadataIndex => $valueIndex) {
            if (!isset($records[$valueIndex])) { continue; }
            $record = $records[$valueIndex]; $id = $slots[$record['slot']] ?? '';
            if ($id === '' || !array_key_exists($id, $targets)) { throw new \UnexpectedValueException('XLSX rich-image relationship reference is missing.'); }
            if ($targets[$id] === null) { continue; }
            $resolved[$metadataIndex] = ['file_name' => $targets[$id], 'description' => $record['description']];
        }
        $this->valueMetadataImages = $resolved;
    }

    /** Read all metadata tables in one streaming pass; resolve references after their ordinal slots are known. */
    private function richMetadata(string $part): array
    {
        $reader = (new XmlReader($this->file))->temporaryDirectory($this->readerTempDirectory);
        $types = $future = $values = $seen = []; $section = null; $index = 0;
        try {
            $reader->openZip($this->richExistingPart($part));
            while ($reader->read()) {
                if ($reader->depth === 1 && $reader->nodeType === \XMLReader::END_ELEMENT) { $section = null; }
                if ($reader->namespaceURI !== self::META_NS || $reader->nodeType !== \XMLReader::ELEMENT) { continue; }
                if ($reader->depth === 1 && in_array($reader->localName, ['metadataTypes', 'futureMetadata', 'valueMetadata'], true)) {
                    if ($reader->localName === 'futureMetadata' && $reader->getAttribute('name') !== 'XLRICHVALUE') { $section = null; continue; }
                    $section = $reader->localName;
                    if (isset($seen[$section])) { throw new \UnexpectedValueException('Duplicate XLSX rich metadata table.'); }
                    $seen[$section] = true; $index = 0; continue;
                }
                if ($section === null || $reader->depth !== 2 || $reader->localName !== ($section === 'metadataTypes' ? 'metadataType' : 'bk')) { continue; }
                $slot = $index++;
                if ($section === 'metadataTypes') {
                    if ($reader->getAttribute('name') === 'XLRICHVALUE') { $types[$slot + 1] = true; }
                    continue;
                }
                $document = new \DOMDocument(); $node = $reader->expand($document);
                if (!$node instanceof \DOMElement) { throw new \UnexpectedValueException('Invalid XLSX metadata block XML.'); }
                if ($section === 'futureMetadata') {
                    $blocks = $node->getElementsByTagNameNS(self::RICH_NS, 'rvb');
                    if ($blocks->length !== 1) { throw new \UnexpectedValueException('Invalid XLSX rich-value metadata block.'); }
                    $future[$slot] = self::richIndex($blocks->item(0)->getAttribute('i'));
                } else {
                    foreach (self::richChildren($node, self::META_NS, 'rc') as $record) {
                        if (!isset($types[self::richIndex($record->getAttribute('t'))])) { continue; }
                        if (isset($values[$slot])) { throw new \UnexpectedValueException('Duplicate XLSX rich metadata reference.'); }
                        $values[$slot] = self::richIndex($record->getAttribute('v'));
                    }
                }
            }
        } finally { $reader->close(); }
        foreach ($values as &$value) {
            if (!isset($future[$value])) { throw new \UnexpectedValueException('Invalid XLSX rich-value metadata reference.'); }
            $value = $future[$value];
        }
        unset($value); return $values;
    }

    /** Use declared parts instead of fixed richData filenames. */
    private function richPart(array $types): string
    {
        foreach ($types as $type) {
            if (isset($this->relations[$type])) { return $this->richExistingPart(reset($this->relations[$type])); }
        }
        throw new \UnexpectedValueException('Required XLSX rich-value part is not declared.');
    }

    /** Match the package's actual spelling after its ZIP safety checks. */
    private function richExistingPart(string $part): string
    {
        $actual = $this->checkInnerFile($part);
        if ($actual === null) { throw new \UnexpectedValueException('Required XLSX rich-value part is missing.'); }
        return $actual;
    }

    /** Stream one direct-child record at a time; preserve ordinal indices of unrelated records. */
    private function richNodes(string $part, string $parent, string $child, string $namespace, ?string $name = null): \Generator
    {
        $reader = (new XmlReader($this->file))->temporaryDirectory($this->readerTempDirectory);
        $depth = null; $index = 0; $seen = false;
        try {
            $reader->openZip($this->richExistingPart($part));
            while ($reader->read()) {
                if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === $parent && $reader->namespaceURI === $namespace
                    && ($name === null || $reader->getAttribute('name') === $name)) {
                    if ($seen) { throw new \UnexpectedValueException('Duplicate XLSX rich-value record container.'); }
                    $seen = true; $depth = $reader->isEmptyElement ? null : $reader->depth; continue;
                }
                if ($depth !== null && $reader->nodeType === \XMLReader::END_ELEMENT && $reader->depth === $depth) { $depth = null; }
                if ($depth === null || $reader->nodeType !== \XMLReader::ELEMENT || $reader->depth !== $depth + 1
                    || $reader->namespaceURI !== $namespace || $reader->localName !== $child) { continue; }
                $document = new \DOMDocument(); $node = $reader->expand($document);
                if (!$node instanceof \DOMElement) { throw new \UnexpectedValueException('Invalid XLSX rich-value record XML.'); }
                yield $index++ => $node;
            }
        } finally { $reader->close(); }
    }

    /** Only direct record fields occupy slots; extension descendants cannot shift their positions. */
    private static function richChildren(\DOMElement $parent, string $namespace, string $name): array
    {
        $children = [];
        foreach ($parent->childNodes as $child) { if ($child instanceof \DOMElement && $child->namespaceURI === $namespace && $child->localName === $name) { $children[] = $child; } }
        return $children;
    }

    /** Validate unsigned XML indices before PHP casts, accepting zero prefixes and optional signs. */
    private static function richIndex(string $text): int
    {
        $text = trim($text); $negative = str_starts_with($text, '-');
        if ($negative || str_starts_with($text, '+')) { $text = substr($text, 1); }
        if (!ctype_digit($text)) { throw new \UnexpectedValueException('Invalid XLSX rich-value index.'); }
        $text = ltrim($text, '0'); if ($text === '') { return 0; }
        if ($negative || strlen($text) > 10 || ($index = (int)$text) > 4294967295) { throw new \UnexpectedValueException('XLSX rich-value index exceeds unsigned bounds.'); }
        return $index;
    }

    /** Detect rich images from resolved metadata, not unused-media counts shared with drawings. */
    public function hasRichValueImages(): bool
    {
        $this->assertOpen(); return $this->valueMetadataImages !== [];
    }

    /** Workbook drawing presence follows actual sheet relationships, not drawingN.xml filenames. */
    public function hasDrawings(): bool
    {
        $this->assertOpen();
        foreach ($this->sheets as $sheet) { if ($sheet->hasDrawings()) { return true; } }
        return false;
    }

    /** Rich-only workbooks contain images even when there are no floating drawings. */
    public function hasImages(): bool
    {
        $this->assertOpen();
        foreach ($this->sheets as $sheet) { if ($sheet->countImages() > 0) { return true; } }
        return false;
    }

    /** Count image-bearing cells across sheets; publish the cache only after every scan succeeds. */
    public function countImages(): int
    {
        $this->assertOpen();
        if ($this->countImages === -1) {
            $count = 0;
            foreach ($this->sheets as $sheet) { $count += $sheet->countImages(); }
            $this->countImages = $count;
        }
        return $this->countImages;
    }

    /** Preserve the native unused-media helper, deduplicating actual drawing targets rather than numeric list keys. */
    public function countExtraImages(): int
    {
        $this->assertOpen(); $drawn = [];
        foreach ($this->sheets as $sheet) { foreach ($sheet->_getDrawingsImageFiles() as $part) { $drawn[$part] = true; } }
        $imageNames = array_fill_keys($this->mediaImageFiles(), true); $count = 0;
        foreach ($this->relations['media'] ?? [] as $part) { if (isset($imageNames[basename($part)]) && !isset($drawn[$part])) { ++$count; } }
        return $count;
    }

    /** Keep optional image descriptions available without adding them to ordinary cell values. */
    public function metadataImageName(int $vmIndex): ?string
    {
        $this->assertOpen(); $entry = $this->valueMetadataImages[$vmIndex - 1] ?? null;
        return $entry === null ? null : ($entry['description'] ?? basename($entry['file_name']));
    }
}
