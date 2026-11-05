<?php

namespace RC\Helper\Cache\think\cache;

require_once dirname(__DIR__, 2) . '/bootstrap.php';

/** A tag view that reports write failures instead of recording nonexistent entries. */
class TagSet extends \think\cache\TagSet
{
    /** Write and then register the key; return false when the data write fails. */
    public function set($name, $value, $expire = null): bool
    {
        if (!$this->handler->set($name, $value, $expire)) {
            return false;
        }
        $this->append($name);
        return true;
    }
}
