<?php

namespace RC\Helper\Excel;

use avadim\FastExcelReader\Interfaces\InterfaceSheetReader;
use avadim\FastExcelReader\Interfaces\InterfaceXmlReader;

/** Upstream XLSX parser with RC-owned factories and deterministic close(). */
class XlsxBook extends \avadim\FastExcelReader\Excel
{
    use BookLifecycle;
    use RichValueImages;

    private array $declaredNumberFormats = [];

    /** Configure the instance before the upstream constructor starts parsing. */
    public function __construct(string $file, ?string $temporaryDirectory = null)
    {
        $this->readerTempDirectory = $temporaryDirectory;
        try {
            parent::__construct($file);
        } catch (\Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    /** Supply the safe parser without modifying bundled upstream sources. */
    public static function createReader(string $file, ?array $parserProperties = []): InterfaceXmlReader
    {
        return new XmlReader($file);
    }

    /** Each sheet owns its parser; different sheets may stream independently. */
    public static function createSheet(string $sheetName, $sheetId, $file, $path, $excel): InterfaceSheetReader
    {
        return new XlsxSheet($sheetName, (string)$sheetId, $file, $path, $excel);
    }

    /** Classify declared cell styles by expanded XML names before any worksheet starts streaming. */
    protected function _loadStyles(?string $innerFile = null)
    {
        $innerFile = $this->checkInnerFile($innerFile ?: 'xl/styles.xml');
        if ($innerFile === null) {
            throw new \UnexpectedValueException('Referenced XLSX styles part is missing.');
        }
        try {
            $this->xmlReader->openZip($innerFile);
            $namespace = $this->readStyleNamespace();
            $formats = [];
            $group = '';
            while ($this->xmlReader->read()) {
                if ($this->xmlReader->depth === 1) {
                    $group = $this->xmlReader->nodeType === \XMLReader::ELEMENT
                        && $this->xmlReader->namespaceURI === $namespace ? $this->xmlReader->localName : '';
                    continue;
                }
                if ($this->xmlReader->nodeType !== \XMLReader::ELEMENT || $this->xmlReader->depth !== 2
                    || $this->xmlReader->namespaceURI !== $namespace) {
                    continue;
                }
                $name = $this->xmlReader->localName;
                if ($group === 'numFmts' && $name === 'numFmt') {
                    $formats[(int)$this->xmlReader->getAttribute('numFmtId')] = (string)$this->xmlReader->getAttribute('formatCode');
                } elseif (($group === 'cellStyleXfs' || $group === 'cellXfs') && $name === 'xf') {
                    $id = (int)$this->xmlReader->getAttribute('numFmtId');
                    $pattern = $formats[$id] ?? '';
                    if ($this->_isDatePattern($pattern === '' ? $id : null, $pattern)) {
                        $style = ['format' => $pattern, 'formatType' => 'd'];
                    } elseif ($pattern) {
                        $style = ['format' => $pattern];
                        if ($this->_isNumberPattern($id, $pattern)) {
                            $style['formatType'] = 'n';
                        }
                    } elseif ($id > 0 && isset($this->builtinFormats[$id]['category'])) {
                        $style = ['formatType' => $this->builtinFormats[$id]['category']];
                    } else {
                        $style = null;
                    }
                    $this->styles[$group][] = $style;
                }
            }
        } finally {
            $this->xmlReader->close();
        }
    }

    /** Follow the declared styles relationship, reusing the native decoders after XML name normalization. */
    protected function _loadCompleteStyles(?string $innerFile = null)
    {
        if (!$innerFile) {
            if (empty($this->relations['styles'])) {
                return;
            }
            $innerFile = $this->checkInnerFile(reset($this->relations['styles']));
            if ($innerFile === null) {
                throw new \UnexpectedValueException('Referenced XLSX styles part is missing.');
            }
        }
        try {
            $this->xmlReader->openZip($innerFile);
            $namespace = $this->readStyleNamespace();
            $document = new \DOMDocument();
            $xpath = null;
            $loaders = ['numFmts' => ['_loadStyleNumFmts', 'numFmt'], 'fonts' => ['_loadStyleFonts', 'font'],
                'fills' => ['_loadStyleFills', 'fill'], 'borders' => ['_loadStyleBorders', 'border'],
                'cellStyleXfs' => ['_loadStyleCellXfs', 'xf'], 'cellXfs' => ['_loadStyleCellXfs', 'xf']];
            while ($this->xmlReader->read()) {
                if ($this->xmlReader->nodeType !== \XMLReader::ELEMENT || $this->xmlReader->depth !== 1
                    || $this->xmlReader->namespaceURI !== $namespace) {
                    continue;
                }
                $name = $this->xmlReader->localName;
                if (isset($loaders[$name])) {
                    [$loader, $childName] = $loaders[$name];
                    $node = $this->xmlReader->expand($document);
                    if (!$node instanceof \DOMElement) {
                        throw new \UnexpectedValueException('Cannot expand XLSX style group.');
                    }
                    if ($this->styleGroupNeedsNormalization($namespace, $childName)) {
                        if ($xpath === null) {
                            $xpath = new \DOMXPath($document);
                            $xpath->registerNamespace('s', $namespace);
                        }
                        $this->normalizeStyleNames($node, $xpath, $namespace, $childName);
                    }
                    $this->$loader($node, $name);
                }
            }
            if (empty($this->styles['_']['numFmts'])) {
                $this->_loadStyleNumFmts(null, 'numFmts');
            }
        } finally {
            $this->xmlReader->close();
        }
    }

    /** Use the existing forward scan to detect affected groups, avoiding a second DOM walk for ordinary XML. */
    private function styleGroupNeedsNormalization(string $namespace, string $childName): bool
    {
        $needed = $this->xmlReader->prefix !== '';
        if (!$this->xmlReader->isEmptyElement) {
            while ($this->xmlReader->read()) {
                if ($this->xmlReader->depth === 1) {
                    break;
                }
                if (!$needed && $this->xmlReader->nodeType === \XMLReader::ELEMENT) {
                    $needed = $this->xmlReader->prefix !== '' || $this->xmlReader->namespaceURI !== $namespace
                        || ($this->xmlReader->depth === 2 && $this->xmlReader->localName !== $childName);
                }
            }
        }
        return $needed;
    }

    /** Read the styles root once per scan; unrelated XML vocabularies must not become cell styles. */
    private function readStyleNamespace(): string
    {
        while ($this->xmlReader->read()) {
            if ($this->xmlReader->nodeType !== \XMLReader::ELEMENT) {
                continue;
            }
            $namespace = $this->xmlReader->namespaceURI;
            if ($this->xmlReader->depth !== 0 || $this->xmlReader->localName !== 'styleSheet'
                || !in_array($namespace, ['http://schemas.openxmlformats.org/spreadsheetml/2006/main',
                    'http://purl.oclc.org/ooxml/spreadsheetml/main'], true)) {
                throw new \UnexpectedValueException('Invalid XLSX styles root or namespace.');
            }
            return $namespace;
        }
        throw new \UnexpectedValueException('XLSX styles part is empty.');
    }

    /** Keep upstream metadata algorithms unchanged: normalize prefixes and exclude foreign extension nodes. */
    private function normalizeStyleNames(\DOMElement $root, \DOMXPath $xpath, string $namespace, string $childName): void
    {
        $nodes = $xpath->query('descendant-or-self::*[not(self::s:*) or contains(name(), ":")]
            | *[not(self::s:' . $childName . ')]', $root);
        foreach ($nodes as $node) {
            if ($node->namespaceURI !== $namespace
                || ($node->parentNode?->isSameNode($root) && $node->localName !== $childName)) {
                $node->parentNode?->removeChild($node);
            } else {
                $node->prefix = '';
            }
        }
    }

    /** Record definition ownership from the same DOM already decoded by the native style loader. */
    protected function _loadStyleNumFmts($root, $tagName)
    {
        parent::_loadStyleNumFmts($root, $tagName);
        $declared = [];
        if ($root) {
            foreach ($root->childNodes as $node) {
                if ($node->nodeType !== XML_ELEMENT_NODE) { continue; }
                $id = $node->getAttribute('numFmtId');
                if ($id !== '' && $node->getAttribute('formatCode') !== '') { $declared[(int)$id] = true; }
            }
        }
        $this->declaredNumberFormats = $declared;
    }

    /** Distinguish a declaration even when its code exactly matches the built-in default. */
    protected function hasDeclaredNumberFormat(int $formatId): bool
    {
        return isset($this->declaredNumberFormats[$formatId]);
    }

    /** Release the metadata parser as well as worksheet parsers. */
    protected function releaseReaderResources(): void
    {
        $this->valueMetadataImages = [];
        $this->declaredNumberFormats = [];
        $this->fileList = $this->relations = [];
        $this->themeColors = $this->properties = null;
        if (isset($this->xmlReader)) {
            $this->xmlReader->close();
            unset($this->xmlReader);
        }
    }
}
