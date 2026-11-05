<?php

namespace RC\Helper\Redis\mix;

/** Standalone native pipeline with the same owner, fluent result mapping and discard lifecycle as Multi. */
class Pipeline extends Multi
{
    /** Commands are buffered by phpredis until the explicit exec() call. */
    protected function nativeMode(): int { return \Redis::PIPELINE; }
}
