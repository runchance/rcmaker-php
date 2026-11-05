<?php

declare(strict_types=1);

namespace RC\Helper\Cache;

use RC\Helper\Cache\think\cache\TagSet;

/** Tag view returned by MemcacheStore::tag(); set/remember behavior stays upstream-compatible. */
final class MemcacheTagSet extends TagSet
{
    /** Remove all storage buckets as well as their members, rather than just the legacy single key. */
    public function clear(): bool
    {
        foreach ($this->tag as $tag) {
            $this->handler->clearTagged($tag);
        }
        return true;
    }
}
