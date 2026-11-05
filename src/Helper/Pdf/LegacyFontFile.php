<?php

declare(strict_types=1);

namespace RC\Helper\Pdf;

use Com\Tecnick\File\File;
use Com\Tecnick\Pdf\Font\Exception as FontException;

/** Scoped font-data view; the original reader and native font validation remain authoritative. */
final class LegacyFontFile extends File
{
    private ?string $coreName = null;

    /** Use only while the native font loader reads this explicit definition; never replace the document's reader. */
    public function __construct(
        private File $reader, private string $definition, private bool $fakeStyle = false,
        private bool $nativeJson = false,
    ) {
        parent::__construct();
    }

    /** Retain the document's current local/PHAR resource policy. */
    public function isAllowedFile(string $file): bool
    {
        return $this->reader->isAllowedFile($file);
    }

    /** Return the declared standard Core font name only after authorized, data-only loading. */
    public function coreName(): ?string
    {
        return $this->coreName;
    }

    /** Let native Font/Load validate normalized JSON fields and keep all other resource reads unchanged. */
    public function getFileData(string $file): string|false
    {
        $bytes = $this->reader->getFileData($file);
        if ($bytes === false || $file !== $this->definition) { return $bytes; }
        try {
            if ($this->nativeJson) {
                $font = json_decode($bytes, true, 5, JSON_THROW_ON_ERROR);
                if (!is_array($font)) { throw new FontException('The font definition file has a bad format: '.$file); }
            } elseif (LegacyFontDefinition::isProtected($bytes)) {
                try { $metadata = $this->reader->getFileData($file.LegacyFontDefinition::SIDECAR_SUFFIX); }
                catch (\Com\Tecnick\File\Exception $error) { throw new FontException('Cannot read protected legacy font metadata; rebuild from the original font definition.', 0, $error); }
                if ($metadata === false) { throw new FontException('Protected legacy font metadata is missing; rebuild from the original font definition.'); }
                $font = LegacyFontDefinition::fromMetadata($bytes, $metadata);
            } else { $font = LegacyFontDefinition::decode($bytes); }
            if (!$this->nativeJson && ($font['type'] ?? null) === 'Core') {
                if (!in_array($font['name'], \Com\Tecnick\Pdf\Font\Core::FONT, true)) {
                    throw new FontException('Legacy Core definitions must declare a standard PDF font name.');
                }
                $this->coreName = $font['name'];
            }
            if ($this->fakeStyle) { $font['fakestyle'] = true; }
            return json_encode($font, JSON_THROW_ON_ERROR);
        }
        catch (\JsonException $error) { throw new FontException('Font metadata must be valid UTF-8 JSON data.', 0, $error); }
    }
}
