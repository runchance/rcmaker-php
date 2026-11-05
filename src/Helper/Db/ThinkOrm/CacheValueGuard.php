<?php

declare(strict_types=1);

namespace RC\Helper\Db\ThinkOrm;

/** Bound automatic-cache inspection without serializing objects, consuming streams or altering query values. */
final class CacheValueGuard
{
    public const MAX_DEPTH = 64;
    public const NODE_BUDGET = 4096;

    /** Share one remaining-node budget across sibling arrays/fragments; cycles and repeated DAGs terminate too. */
    public static function accept(array $values, int &$remaining, int $depth = 0): bool
    {
        if ($depth > self::MAX_DEPTH) { return false; }
        foreach ($values as $value) {
            if ($remaining-- <= 0) { return false; }
            switch (gettype($value)) {
                case 'array':
                    if (!self::accept($value, $remaining, $depth + 1)) { return false; }
                    break;
                case 'object':
                case 'resource':
                case 'resource (closed)':
                    return false;
            }
        }
        return true;
    }
}
