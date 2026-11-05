<?php

declare(strict_types=1);

namespace RC\Cli;

use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Socket;
use Workerman\Worker;

/** @internal Share one native listener between Windows PHP processes, without a TCP proxy or upstream patches. */
final class WindowsListener
{
    /** @var array<int, string> Shared-memory export IDs remain owned by their target process generation. */
    private array $exports = [];
    private Socket $socket;

    /** Bind through Workerman's own address/protocol parser; the supervisor never accepts connections. */
    public function __construct(private Worker $worker)
    {
        if (!self::available()) { throw new RuntimeException('Windows count > 1 on a listening worker requires the sockets extension and WSAPROTOCOL_INFO functions.'); }
        $worker->listen(false);
        $stream = (new ReflectionProperty(Worker::class, 'mainSocket'))->getValue($worker);
        if (!is_resource($stream) || ($socket = socket_import_stream($stream)) === false) {
            $worker->unlisten();
            throw new RuntimeException('Cannot export this Windows listener; use a supported TCP/UDP socket.');
        }
        $this->socket = $socket;
        // The supervisor owns this definition directly, not through Workerman's worker registry.
        $registry = new ReflectionProperty(Worker::class, 'workers'); $workers = $registry->getValue();
        $key = array_search($worker, $workers, true);
        if ($key !== false) {
            unset($workers[$key]); $registry->setValue(null, $workers);
            foreach (['pidMap', 'idMap'] as $property) {
                if (!property_exists(Worker::class, $property)) { continue; }
                $map = new ReflectionProperty(Worker::class, $property); $value = $map->getValue();
                unset($value[$key]); $map->setValue(null, $value);
            }
        }
    }

    /** Check native capability only when a listening group actually asks for multiple replicas. */
    public static function available(): bool
    {
        return PHP_OS_FAMILY === 'Windows' && function_exists('socket_wsaprotocol_info_export')
            && function_exists('socket_wsaprotocol_info_import') && function_exists('socket_wsaprotocol_info_release');
    }

    /** Export solely for a freshly spawned, owned PID; the identifier is not usable by another PID. */
    public function exportTo(int $pid): string
    {
        if ($pid < 1 || isset($this->exports[$pid])) { throw new RuntimeException('Invalid or duplicate listener handoff.'); }
        $id = socket_wsaprotocol_info_export($this->socket, $pid);
        if ($id === false) { throw new RuntimeException('Windows could not duplicate the listening socket.'); }
        return $this->exports[$pid] = $id;
    }

    /** Release this generation's metadata after the child exits, never another worker's socket. */
    public function release(int $pid): void
    {
        if (isset($this->exports[$pid])) {
            socket_wsaprotocol_info_release($this->exports[$pid]); unset($this->exports[$pid]);
        }
    }

    /** Close only the supervisor's original handle after its children have stopped. */
    public function close(): void
    {
        foreach (array_keys($this->exports) as $pid) { $this->release($pid); }
        $this->worker->unlisten();
    }

    /** Adopt before Worker::runAll(); normal Workerman accepts, TLS and protocol handling remain authoritative. */
    public static function attach(Worker $worker, string $id, array $context): void
    {
        $socket = socket_wsaprotocol_info_import($id);
        if ($socket === false || ($stream = socket_export_stream($socket)) === false) { throw new RuntimeException('Cannot import the supervisor listener.'); }
        try {
            (new ReflectionMethod(Worker::class, 'parseSocketAddress'))->invoke($worker);
            if ($context !== [] && !stream_context_set_options($stream, $context)) { throw new RuntimeException('Cannot apply listener stream context.'); }
            if (!stream_set_blocking($stream, false)) { throw new RuntimeException('Cannot configure the imported listener.'); }
            if ($worker->transport === 'ssl') { stream_socket_enable_crypto($stream, false); }
            (new ReflectionProperty(Worker::class, 'mainSocket'))->setValue($worker, $stream);
        } catch (\Throwable $error) { fclose($stream); throw $error; }
    }
}
