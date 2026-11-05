<?php

namespace RC\Helper\Excel;

/** XMLReader adapter: reject DTDs, prohibit external entities and surface malformed XML. */
class XmlReader extends \avadim\FastExcelReader\Reader
{
    private ?string $temporaryDirectory = null;

    /** Configure this reader only; used by the workbook and its sheet readers. */
    public function temporaryDirectory(?string $directory): self
    {
        $this->temporaryDirectory = $directory;
        return $this;
    }

    /** Allocate extraction fallbacks using exclusive, local temporary files. */
    protected function makeTempFile()
    {
        $file = ReaderFiles::temporary($this->temporaryDirectory);
        $this->tmpFiles[] = $file;
        return $file;
    }

    /** Never accept caller options that enable DTD loading or entity substitution. */
    public function openZip(string $innerFile, ?string $encoding = null, ?int $options = null): bool
    {
        $this->close();
        $this->xmlParserProperties = [self::LOADDTD => false, self::VALIDATE => false, self::SUBST_ENTITIES => false];
        $flags = LIBXML_NONET | LIBXML_COMPACT;
        if (defined('LIBXML_NO_XXE')) {
            $flags |= LIBXML_NO_XXE;
        }
        $opened = parent::openZip($innerFile, $encoding, $flags);
        if (!$opened) {
            throw new \RuntimeException('Cannot open XLSX XML part.');
        }
        return true;
    }

    /** Keep libxml error handling scoped to one synchronous read, never across a yielded row. */
    public function read(): bool
    {
        $previous = libxml_use_internal_errors(true);
        $before = $previous ? count(libxml_get_errors()) : 0;
        try {
            $result = parent::read();
            // XMLReader returns false on parser failure; do not allocate an error array per healthy node.
            if (!$result) {
                $errors = libxml_get_errors();
                for ($index = $before, $count = count($errors); $index < $count; $index++) {
                    if ($errors[$index]->level >= LIBXML_ERR_ERROR) {
                        throw new \RuntimeException('Invalid XLSX XML: ' . trim($errors[$index]->message));
                    }
                }
            }
            if ($result && ($this->nodeType === self::DOC_TYPE || $this->nodeType === self::ENTITY || $this->nodeType === self::ENTITY_REF)) {
                throw new \RuntimeException('DTD and entity declarations are not allowed in XLSX files.');
            }
            return $result;
        } finally {
            // Switching back to false clears only errors collected while we owned the buffer.
            libxml_use_internal_errors($previous);
        }
    }

    /** Subtree expansion can parse ahead of read(); its errors must not become missing cells. */
    public function expand(?\DOMNode $baseNode = null): \DOMNode|false
    {
        return $this->checkedSubtree(fn () => parent::expand($baseNode));
    }

    /** Read scalar XML text with the same parser-error handling as streamed nodes. */
    public function readString(): string
    {
        return $this->checkedSubtree(fn () => parent::readString());
    }

    /** Keep optional upstream raw-XML features within the checked parser boundary. */
    public function readInnerXml(): string
    {
        return $this->checkedSubtree(fn () => parent::readInnerXml());
    }

    /** Keep optional upstream raw-XML features within the checked parser boundary. */
    public function readOuterXml(): string
    {
        return $this->checkedSubtree(fn () => parent::readOuterXml());
    }

    /** Scope subtree operations synchronously and preserve pre-existing caller errors. */
    private function checkedSubtree(callable $operation): mixed
    {
        $previous = libxml_use_internal_errors(true);
        $before = $previous ? count(libxml_get_errors()) : 0;
        try {
            $result = $operation();
            $errors = libxml_get_errors();
            for ($index = $before, $count = count($errors); $index < $count; $index++) {
                if ($errors[$index]->level >= LIBXML_ERR_ERROR) {
                    throw new \RuntimeException('Invalid XLSX XML: ' . trim($errors[$index]->message));
                }
            }
            return $result;
        } finally {
            libxml_use_internal_errors($previous);
        }
    }
}
