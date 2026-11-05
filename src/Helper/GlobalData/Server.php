<?php

namespace RC\Helper\GlobalData;

require_once __DIR__ . '/bootstrap.php';

/**
 * Native GlobalData memory engine with bounded, object-free requests.
 * Example: new Server('127.0.0.1', 2207); Worker::runAll(). One Worker owns one in-memory data set.
 */
class Server extends \GlobalData\Server
{
    private int $maxFrame = Codec::DEFAULT_MAX_FRAME;

    /** Default to loopback: the native protocol has no authentication or transport encryption. */
    public function __construct($ip = '127.0.0.1', $port = 2207, array $options = [])
    {
        if (array_diff(array_keys($options), ['max_frame'])) {
            throw new \InvalidArgumentException('Unknown GlobalData server option.');
        }
        $this->maxFrame = Codec::frameLimit($options['max_frame'] ?? Codec::DEFAULT_MAX_FRAME);
        if (!is_string($ip) || $ip === '' || preg_match('/[\x00-\x1f\x7f]/', $ip)) {
            throw new \InvalidArgumentException('Invalid GlobalData server bind address.');
        }
        $unix = str_starts_with($ip, 'unix://');
        if (!$unix && filter_var($port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 65535]]) === false) {
            throw new \InvalidArgumentException('Invalid GlobalData server port.');
        }
        if ($unix && !str_starts_with(substr($ip, 7), '/')) {
            throw new \InvalidArgumentException('GlobalData Unix socket path must be absolute.');
        }
        // A frame:// listener resolves its protocol again in Worker::listen(), replacing our size guard.
        $host = !$unix && str_contains($ip, ':') && !str_starts_with($ip, '[') ? '[' . $ip . ']' : $ip;
        $this->_worker = new \Workerman\Worker($unix ? $ip : 'tcp://' . $host . ':' . $port);
        $this->_worker->protocol = Frame::class;
        $this->_worker->count = 1;
        $this->_worker->name = 'globalDataServer';
        $this->_worker->reloadable = false;
        $this->_worker->onMessage = [$this, 'onMessage'];
        Frame::configure($this->_worker, $this->maxFrame);
    }

    /** Validate before the upstream engine can deserialize or mutate; invalid requests close generically. */
    public function onMessage($connection, $buffer)
    {
        // Workerman's short-packet cache may bypass Frame::input() on a new connection.
        if ($connection instanceof \Workerman\Connection\TcpConnection) {
            $connection->maxPackageSize = $this->maxFrame;
            $connection->maxSendBufferSize = $this->maxFrame;
        }
        if ($buffer === 'ping') {
            return;
        }
        try {
            if (!is_string($buffer)) {
                throw new \UnexpectedValueException('GlobalData payload must be bytes.');
            }
            Codec::request(Codec::decode($buffer, $this->maxFrame));
        } catch (\UnexpectedValueException) {
            return $connection->close(serialize('bad request'));
        }
        return parent::onMessage($connection, $buffer);
    }
}
