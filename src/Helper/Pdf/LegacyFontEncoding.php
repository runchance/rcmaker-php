<?php

declare(strict_types=1);

namespace RC\Helper\Pdf;

use Com\Tecnick\Pdf\Font\Import\Core;

/** Reuse the upstream glyph-name table when expanding legacy byte-keyed width maps. */
final class LegacyFontEncoding extends Core
{
    /** Convert one native encoding-table glyph name without maintaining a second RC glyph dictionary. */
    public static function unicode(string $name): ?int
    {
        return parent::getGlyphUnicode($name);
    }
}
