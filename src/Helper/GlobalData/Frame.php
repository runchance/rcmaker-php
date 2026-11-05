<?php

namespace RC\Helper\GlobalData;

/** Native Workerman Frame encoding/decoding with a per-server inbound size bound. */
final class Frame extends \Workerman\Protocols\Frame
{
    private static ?\WeakMap $limits = null;

    /** Register one server without retaining a stopped Worker forever. */
    public static function configure(\Workerman\Worker $worker, int $maxFrame): void
    {
        self::$limits ??= new \WeakMap();
        self::$limits[$worker] = $maxFrame;
    }

    /** Called by TcpConnection before it buffers the declared payload. */
    public static function input(string $buffer, $connection = null): int
    {
        $worker = $connection?->worker;
        $limit = $worker !== null && self::$limits !== null ? (self::$limits[$worker] ?? Codec::DEFAULT_MAX_FRAME) : Codec::DEFAULT_MAX_FRAME;
        if ($connection !== null) {
            $connection->maxPackageSize = $limit;
            $connection->maxSendBufferSize = $limit;
        }
        $length = parent::input($buffer);
        return $length === 0 && strlen($buffer) < 4 ? 0 : ($length < 6 || $length > $limit ? -1 : $length);
    }
}
