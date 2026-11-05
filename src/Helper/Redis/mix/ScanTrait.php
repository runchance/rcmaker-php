<?php

namespace RC\Helper\Redis\mix;

require_once dirname(__DIR__, 2) . '/Db/mix/bootstrap.php';

/** Preserve the stable upstream reference-aware cursor forwarding without copying its implementation. */
trait ScanTrait
{
    use \Mix\Redis\ScanTrait { scan as private upstreamScan; }

    /** Keep upstream cursor handling and forward the native SCAN TYPE option added after the legacy trait. */
    public function scan(&$iterator, $pattern = '', $count = 0, $type = null)
    {
        if ($type === null) { return $this->upstreamScan($iterator, $pattern, $count); }
        return $this->__call(__FUNCTION__, [&$iterator, $pattern, $count, $type]);
    }
}
