<?php

declare(strict_types=1);

namespace RC\Helper\Process;

use Workerman\Connection\ConnectionInterface;
use Workerman\Protocols\Text;

/** Keep native Text encoding/decoding, but bound each frame rather than the entire coalesced TCP read. */
final class AccessLogProtocol extends Text
{
    /** A batch of valid short frames may be larger than the limit for a single frame. */
    public static function input(string $buffer, ConnectionInterface $connection): int
    {
        $newline = strpos($buffer, "\n");
        if ($newline !== false && $newline < AccessLog::MAX_PACKET_BYTES) { return $newline + 1; }
        if ($newline !== false || strlen($buffer) >= AccessLog::MAX_PACKET_BYTES) { $connection->close(); }
        return 0;
    }
}
