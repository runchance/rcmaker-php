<?php

namespace RC\Helper\Process;

use RC\Container;
use RC\Config;
use RC\Helper\Queue\RedisQueue\Client;
use RC\Helper\Queue\RedisQueue\Package;

/** Queue process handler: discover support/app consumers, then subscribe after validating the complete set. */
class QueueConsumer
{
    protected $_consumerDir = '';
    protected $_timer;
    protected $_type;
    protected $_work;
    private bool $started = false;
    private array $subscriptions = [];

    /** Framework injects runtime, worker and timer; constructor.consumer_dir selects the PSR-4 consumer tree. */
    public function __construct($type, $work, $timer, $consumer_dir = '')
    {
        $this->_consumerDir = $consumer_dir;
        $this->_type = $type;
        $this->_timer = $timer;
        $this->_work = $work;
        $this->start();
    }

    /** Discover once; missing directories retain the existing warning instead of creating files. */
    public function start()
    {
        if ($this->started) {
            return;
        }
        if (!is_dir($this->_consumerDir)) {
            echo "Consumer directory {$this->_consumerDir} not exists\r\n";
            return;
        }
        $root = rtrim(str_replace('\\', '/', realpath(base_path()) ?: base_path()), '/') . '/';
        $appRoot = $appNamespace = null;
        $definitions = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->_consumerDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = str_replace('\\', '/', $file->getRealPath() ?: $file->getPathname());
            $inside = DIRECTORY_SEPARATOR === '\\'
                ? strncasecmp($path, $root, strlen($root)) === 0 : strncmp($path, $root, strlen($root)) === 0;
            if (!$inside) {
                throw new \RuntimeException('Queue consumer must be inside the application base path.');
            }
            $relative = substr($path, strlen($root), -4);
            $class = str_replace('/', '\\', $relative);
            // support/ stays global even when apps_path spans the whole project.
            $global = DIRECTORY_SEPARATOR === '\\' ? strncasecmp($relative, 'support/', 8) === 0 : str_starts_with($relative, 'support/');
            if (!$global) {
                if ($appRoot === null) {
                    $appPath = Config::get('app', 'apps_path') ?? base_path() . '/apps';
                    $appName = Config::get('app', 'app_name') ?? 'app';
                    if (!is_string($appPath) || $appPath === '' || !is_string($appName) || trim($appName, '\\') === '') {
                        throw new \RuntimeException('Queue consumer apps_path and app_name must be non-empty strings.');
                    }
                    $appRoot = rtrim(str_replace('\\', '/', realpath($appPath) ?: $appPath), '/') . '/';
                    $appNamespace = trim($appName, '\\') . '\\';
                }
                $inApp = DIRECTORY_SEPARATOR === '\\'
                    ? strncasecmp($path, $appRoot, strlen($appRoot)) === 0 : strncmp($path, $appRoot, strlen($appRoot)) === 0;
                if ($inApp) { $class = $appNamespace . str_replace('/', '\\', substr($path, strlen($appRoot), -4)); }
            }
            $consumer = Container::get($class);
            if (!is_callable([$consumer, 'handle'])) {
                throw new \RuntimeException('Queue consumer requires public handle($data): ' . $class);
            }
            $queues = $consumer->queue ?? null;
            $queues = is_array($queues) ? $queues : [$queues];
            if ($queues === []) {
                throw new \RuntimeException('Queue consumer requires a queue name: ' . $class);
            }
            foreach ($queues as $queue) {
                if (!is_string($queue)) {
                    throw new \RuntimeException('Invalid consumer queue name: ' . $class);
                }
                Package::queue($queue);
            }
            $queues = array_values(array_unique($queues));
            // Consumers that need worker_id declare it explicitly, avoiding deprecated dynamic properties.
            if (property_exists($consumer, 'worker_id')) {
                $property = new \ReflectionProperty($consumer, 'worker_id');
                if (!$property->isPublic() || $property->isReadOnly() || $property->isStatic()) {
                    throw new \RuntimeException('Consumer worker_id must be a writable public property: ' . $class);
                }
                $consumer->worker_id = $this->_work->id;
            }
            $connection = $consumer->connection ?? 'default';
            if (!is_string($connection) || $connection === '') {
                throw new \RuntimeException('Invalid consumer connection: ' . $class);
            }
            foreach ($queues as $queue) {
                $identity = $connection . "\0" . $queue;
                if (isset($definitions[$identity])) {
                    throw new \RuntimeException('Duplicate queue handler in this process: ' . $connection . '/' . $queue);
                }
                $definitions[$identity] = [$connection, $queue, [$consumer, 'handle']];
            }
        }
        try {
            foreach ($definitions as [$name, $queue, $callback]) {
                $connection = Client::connection($name, $this->_type);
                $connection->subscribe($queue, $callback);
                $this->subscriptions[] = [$connection, $queue];
            }
            $this->started = true;
        } catch (\Throwable $error) {
            $this->onWorkerStop();
            throw $error;
        }
    }

    /** Framework worker_bind() calls this during shutdown; cancel only this handler's subscriptions. */
    public function onWorkerStop(): void
    {
        foreach ($this->subscriptions as [$connection, $queue]) {
            $connection->unsubscribe($queue);
        }
        $this->subscriptions = [];
        $this->started = false;
    }
}
