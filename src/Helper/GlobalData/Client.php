<?php

namespace RC\Helper\GlobalData;

require_once __DIR__ . '/bootstrap.php';

/**
 * Synchronous GlobalData client. Example: $client = new Client('127.0.0.1:2207'); $client->key = 'value'.
 * Uses upstream command builders; sockets, deadlines and timers belong to this instance and process.
 */
class Client extends \GlobalData\Client
{
    /** Retained for old subclasses only; never stores sockets. */
    protected static $_static = [];

    private int $ownerPid;
    private int $maxFrame = Codec::DEFAULT_MAX_FRAME;
    private string $routing = 'first';
    private bool $busy = false;
    private ?float $deadline = null;
    private ?int $activeOffset = null;
    private array $heartbeats = [];

    /** Options: max_frame (total bytes), routing ('first' preserves legacy RC; 'crc32' opts into sharding). */
    public function __construct($servers, array $options = [])
    {
        if (array_diff(array_keys($options), ['max_frame', 'routing'])) {
            throw new \InvalidArgumentException('Unknown GlobalData client option.');
        }
        $this->maxFrame = Codec::frameLimit($options['max_frame'] ?? Codec::DEFAULT_MAX_FRAME);
        $this->routing = $options['routing'] ?? 'first';
        if (!in_array($this->routing, ['first', 'crc32'], true)) {
            throw new \InvalidArgumentException('GlobalData routing must be first or crc32.');
        }
        $addresses = [];
        foreach ((array) $servers as $address) {
            if (!is_string($address) || $address === '' || preg_match('/[\x00-\x1f\x7f]/', $address)) {
                throw new \InvalidArgumentException('Invalid GlobalData server address.');
            }
            if (str_starts_with($address, 'unix://')) {
                if (!str_starts_with(substr($address, 7), '/')) {
                    throw new \InvalidArgumentException('GlobalData Unix socket path must be absolute.');
                }
            } else {
                $address = str_starts_with($address, 'tcp://') ? $address : 'tcp://' . $address;
                $parts = parse_url($address);
                if (str_contains($address, ' ') || $parts === false || ($parts['scheme'] ?? '') !== 'tcp' || empty($parts['host'])
                    || !isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])
                    || isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])) {
                    throw new \InvalidArgumentException('GlobalData expects host:port, [IPv6]:port or unix:///path.');
                }
            }
            $addresses[] = $address;
        }
        parent::__construct($addresses);
        $this->_globalConnections = [];
        $this->ownerPid = getmypid();
    }

    /** Read a key; a missing key and a stored null both return null, as upstream does. */
    public function __get($key)
    {
        return $this->operation('get', $key, [$key]);
    }

    /** Store a scalar/null/array value; object serialization hooks are never supported. */
    public function __set($key, $value)
    {
        return $this->operation('set', $key, [$key, $value]);
    }

    /** Remove a key and consume the server acknowledgement before reusing its socket. */
    public function __unset($key)
    {
        return $this->operation('delete', $key, [$key]);
    }

    /** Atomically add only when the server's native isset-based existence check permits it. */
    public function add($key, $value)
    {
        return $this->operation('add', $key, [$key, $value]);
    }

    /** Atomically increment using the native engine; numeric string steps remain supported. */
    public function increment($key, $step = 1)
    {
        if (!is_numeric($step) || !is_finite((float) $step)) {
            throw new \InvalidArgumentException('GlobalData increment step must be finite and numeric.');
        }
        return $this->operation('increment', $key, [$key, $step]);
    }

    /** Bound the comparison before hashing native serialized bytes; CAS MD5 is not authentication. */
    public function cas($key, $old_value, $new_value)
    {
        $digest = md5(Codec::encode($old_value, $this->maxFrame));
        return $this->operation('cas', $key, [$key, $digest, $new_value]);
    }

    /** Close only this client's local descriptors and timers. No QUIT command or other instance is touched. */
    public function close(): void
    {
        $this->checkProcess();
        if ($this->busy) {
            throw new \LogicException('Cannot close an active GlobalData operation.');
        }
        $this->dropAll();
    }

    /** Release resources without sending network commands or throwing during shutdown. */
    public function __destruct()
    {
        $this->dropAll();
    }

    /** Reject socket aliasing without letting destruction of the rejected clone close the original. */
    public function __clone(): void
    {
        $this->_globalConnections = [];
        $this->heartbeats = [];
        throw new \LogicException('GlobalData clients cannot be cloned.');
    }

    /** Live clients are process-local; create a new client instead of serializing one. */
    public function __serialize(): array
    {
        throw new \LogicException('GlobalData clients cannot be serialized.');
    }

    /** Never reconstruct live socket ownership from serialized data. */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('GlobalData clients cannot be unserialized.');
    }

    /** Return an instance-owned socket, opening it only for a new operation and never replaying old writes. */
    protected function getConnection($key)
    {
        $this->checkProcess();
        $offset = $this->routing === 'first' || count($this->_globalServers) === 1
            ? 0 : abs(crc32((string) $key) % count($this->_globalServers));
        $this->activeOffset = $offset;
        $connection = $this->_globalConnections[$offset] ?? null;
        if (!is_resource($connection) || feof($connection)) {
            $this->drop($offset);
            $connection = @stream_socket_client($this->_globalServers[$offset], $code, $message, $this->remaining($this->operationDeadline()));
            if ($connection === false) {
                throw new \RuntimeException('GlobalData connection failed (' . $code . '): ' . $message);
            }
            stream_set_blocking($connection, true);
            $this->_globalConnections[$offset] = $connection;
        }
        $this->heartbeat($offset, $connection);
        return $connection;
    }

    /** Encode the native envelope and handle partial writes within one absolute RPC deadline. */
    protected function writeToRemote($data, $connection)
    {
        $payload = Codec::encode($data, $this->maxFrame);
        $this->writeAll($connection, pack('N', strlen($payload) + 4) . $payload, $this->operationDeadline());
    }

    /** Read exactly one frame. Coalesced packets remain available for the next command. */
    protected function readFromRemote($connection)
    {
        $deadline = $this->operationDeadline();
        $length = unpack('Nlength', $this->readExact($connection, 4, $deadline))['length'];
        if ($length < 6 || $length > $this->maxFrame) {
            throw new \UnexpectedValueException('Invalid GlobalData frame length.');
        }
        return Codec::decode($this->readExact($connection, $length - 4, $deadline), $this->maxFrame);
    }

    /** Validate the operation before invoking the original upstream command-building implementation once. */
    private function operation(string $command, mixed $key, array $arguments): mixed
    {
        $this->checkProcess();
        if ($this->busy) {
            throw new \LogicException('GlobalData operations cannot be reentered.');
        }
        if (!is_string($key) && !is_int($key)) {
            throw new \InvalidArgumentException('GlobalData keys must be strings or integers.');
        }
        $this->deadline = $this->operationDeadline();
        $this->busy = true;
        try {
            $result = match ($command) {
                'get' => parent::__get(...$arguments),
                'set' => parent::__set(...$arguments),
                'delete' => parent::__unset(...$arguments),
                'add' => parent::add(...$arguments),
                'increment' => parent::increment(...$arguments),
                'cas' => $this->casWithDigest(...$arguments),
            };
            if (($command === 'add' || $command === 'cas') && !is_bool($result)
                || $command === 'increment' && $result !== false && !is_int($result) && !is_float($result)) {
                throw new \UnexpectedValueException('Invalid GlobalData command response.');
            }
            return $result;
        } catch (\Throwable $error) {
            if ($this->activeOffset !== null) {
                $this->drop($this->activeOffset);
            }
            throw $error;
        } finally {
            $this->busy = false;
            $this->deadline = null;
            $this->activeOffset = null;
        }
    }

    /** Keep the native CAS envelope/order while avoiding a second, unbounded serialization of its comparison. */
    private function casWithDigest(string|int $key, string $digest, mixed $value): mixed
    {
        $connection = $this->getConnection($key);
        $this->writeToRemote(['cmd' => 'cas', 'md5' => $digest, 'key' => $key, 'value' => $value], $connection);
        return $this->readFromRemote($connection);
    }

    /** Forked children close inherited descriptors locally, without affecting their parent's TCP session. */
    private function checkProcess(): void
    {
        if ($this->ownerPid !== getmypid()) {
            $this->dropAll();
            $this->ownerPid = getmypid();
            $this->busy = false;
            $this->deadline = null;
            $this->activeOffset = null;
        }
    }

    /** Return one monotonic deadline, including connect, write, header and body time. */
    private function operationDeadline(): float
    {
        if ($this->deadline !== null) {
            return $this->deadline;
        }
        if ((!is_int($this->timeout) && !is_float($this->timeout)) || !is_finite((float) $this->timeout) || $this->timeout <= 0 || $this->timeout > 86400) {
            throw new \InvalidArgumentException('GlobalData timeout must be positive finite seconds, at most 86400.');
        }
        return hrtime(true) / 1e9 + $this->timeout;
    }

    /** Check ownership and the remaining total budget before every blocking I/O call. */
    private function remaining(float $deadline): float
    {
        if ($this->ownerPid !== getmypid()) {
            throw new \RuntimeException('GlobalData process changed during an operation.');
        }
        $remaining = $deadline - hrtime(true) / 1e9;
        if ($remaining <= 0) {
            throw new \RuntimeException('GlobalData operation timed out.');
        }
        return $remaining;
    }

    /** Apply fractional stream timeouts instead of truncating them to whole seconds. */
    private function streamTimeout($connection, float $deadline): void
    {
        if (!is_resource($connection)) {
            throw new \RuntimeException('GlobalData connection is closed.');
        }
        $remaining = $this->remaining($deadline);
        $seconds = (int) floor($remaining);
        if (!stream_set_timeout($connection, $seconds, max(1, (int) (($remaining - $seconds) * 1e6)))) {
            throw new \RuntimeException('Cannot configure GlobalData socket timeout.');
        }
    }

    /** Complete a single already-encoded request; never reconnect or resend its prefix. */
    private function writeAll($connection, string $buffer, float $deadline): void
    {
        $length = strlen($buffer);
        $offset = 0;
        while ($offset < $length) {
            $this->streamTimeout($connection, $deadline);
            $written = @fwrite($connection, substr($buffer, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('GlobalData write failed; its outcome may be unknown.');
            }
            $offset += $written;
            $this->remaining($deadline);
        }
    }

    /** Read only the bytes needed by this frame, with bounded allocation and no packet overread. */
    private function readExact($connection, int $length, float $deadline): string
    {
        $buffer = '';
        while (strlen($buffer) < $length) {
            $this->streamTimeout($connection, $deadline);
            $part = @fread($connection, min(8192, $length - strlen($buffer)));
            if ($part === false || $part === '') {
                throw new \RuntimeException('GlobalData read failed or timed out; its outcome may be unknown.');
            }
            $buffer .= $part;
            $this->remaining($deadline);
        }
        return $buffer;
    }

    /** Install a weak heartbeat only when an actual Workerman loop exists; plain CLI needs no Timer setup. */
    private function heartbeat(int $offset, $connection): void
    {
        if (isset($this->heartbeats[$offset]) || $this->pingInterval === 0 || $this->pingInterval === 0.0) {
            return;
        }
        if ((!is_int($this->pingInterval) && !is_float($this->pingInterval))
            || !is_finite((float) $this->pingInterval) || $this->pingInterval < 0 || $this->pingInterval > 86400) {
            throw new \InvalidArgumentException('GlobalData pingInterval must be finite seconds from 0 to 86400.');
        }
        if (!class_exists(\Workerman\Worker::class) || \Workerman\Worker::$globalEvent === null) {
            return;
        }
        $loop = \Workerman\Worker::$globalEvent;
        $weak = \WeakReference::create($this);
        $resourceId = get_resource_id($connection);
        $pid = $this->ownerPid;
        $id = $loop->repeat((float) $this->pingInterval, static function () use ($weak, $offset, $resourceId, $pid): void {
            $client = $weak->get();
            if ($client === null) {
                return;
            }
            if ($pid !== getmypid()) {
                $client->checkProcess();
                return;
            }
            $stream = $client->_globalConnections[$offset] ?? null;
            if (!is_resource($stream)) {
                $client->drop($offset);
                return;
            }
            if ($client->busy || get_resource_id($stream) !== $resourceId) {
                return;
            }
            $client->busy = true;
            try {
                $client->deadline = $client->operationDeadline();
                $client->writeAll($stream, pack('N', 8) . 'ping', $client->deadline);
            } catch (\Throwable) {
                $client->drop($offset);
            } finally {
                $client->busy = false;
                $client->deadline = null;
            }
        });
        $this->heartbeats[$offset] = [$loop, $id];
    }

    /** Remove only the timer recorded for this socket and close only this instance's descriptor. */
    private function drop(int $offset): void
    {
        if (isset($this->heartbeats[$offset])) {
            [$loop, $id] = $this->heartbeats[$offset];
            unset($this->heartbeats[$offset]);
            try { $loop->offRepeat($id); } catch (\Throwable) {}
        }
        $connection = $this->_globalConnections[$offset] ?? null;
        unset($this->_globalConnections[$offset]);
        if (is_resource($connection)) {
            @fclose($connection);
        }
    }

    /** Release all per-instance state, including a timer whose stream was already closed externally. */
    private function dropAll(): void
    {
        foreach (array_unique([...array_keys($this->_globalConnections ?? []), ...array_keys($this->heartbeats)]) as $offset) {
            $this->drop($offset);
        }
    }
}
