<?php
/**
 * RC timer/lifecycle adapter derived from the Workerman Crontab public API.
 * Original API copyright walkor <walkor@workerman.net>, MIT License.
 * Upstream scheduling and parser files are not modified or masqueraded as pristine copies here.
 */

declare(strict_types=1);

namespace RC\Helper\Crontab;

require_once __DIR__ . '/Parser.php';

/** Register in onWorkerStart(); each task owns at most one timer plus one shared minute timer per driver. */
class Crontab
{
    protected $_rule;
    protected $_callback;
    protected $_name;
    protected $_id;
    protected static $_instances = [];

    private static int $nextId = 0;
    private static array $minuteTimers = [];
    private Parser $parser;
    private ?string $driver;
    private ?int $timer = null;
    private ?int $plannedMinute = null;
    private array $times = [];

    /** Keep ($rule, $callback, $type, $name); omitted type uses Workerman, null registers without starting. */
    public function __construct($rule, $callback, $type = 'workerman', $name = null)
    {
        $this->parser = new Parser();
        if (!is_string($rule) || !$this->parser->isValid($rule) || !is_callable($callback)) {
            throw new \InvalidArgumentException('Cron requires a valid expression and callable callback.');
        }
        if (!in_array($type, ['workerman', 'swoole', null], true) || ($name !== null && !is_string($name))) {
            throw new \InvalidArgumentException('Invalid cron timer driver or name.');
        }
        $this->_rule = $rule;
        $this->_callback = $callback;
        $this->_name = $name;
        $this->driver = $type;
        $this->_id = self::createId();
        self::$_instances[$this->_id] = $this;
        try {
            if ($type !== null) {
                $this->plan(microtime(true), true);
                self::ensureMinuteTimer($type);
            }
        } catch (\Throwable $error) {
            self::remove($this->_id);
            throw $error;
        }
    }

    /** Return the original developer expression; compilation never mutates this value. */
    public function getRule()
    {
        return $this->_rule;
    }

    /** Return the configured task callback for inspection, not a timer wrapper. */
    public function getCallback()
    {
        return $this->_callback;
    }

    /** Return the optional display name; names do not provide distributed uniqueness. */
    public function getName()
    {
        return $this->_name;
    }

    /** Return a process-local, non-reused registration ID. */
    public function getId()
    {
        return $this->_id;
    }

    /** Cancel this task and release its queued callbacks; repeated destroy returns false. */
    public function destroy()
    {
        return self::remove($this->_id);
    }

    /** Inspect active and explicitly dormant registrations in this PHP process only. */
    public static function getAll()
    {
        return self::$_instances;
    }

    /** Remove by ID or task object, also preventing a previously dequeued stale timer from invoking it. */
    public static function remove($id)
    {
        if ($id instanceof self) {
            $id = $id->getId();
        }
        if ((!is_int($id) && !is_string($id)) || !isset(self::$_instances[$id])) {
            return false;
        }
        $task = self::$_instances[$id];
        unset(self::$_instances[$id]);
        $task->times = [];
        $timer = $task->timer;
        $task->timer = null;
        try {
            if ($timer !== null) {
                self::cancelTimer($task->driver, $timer);
            }
        } finally {
            $driver = $task->driver;
            if ($driver !== null && !self::hasDriver($driver) && isset(self::$minuteTimers[$driver])) {
                $minuteTimer = self::$minuteTimers[$driver];
                unset(self::$minuteTimers[$driver]);
                self::cancelTimer($driver, $minuteTimer);
            }
        }
        return true;
    }

    /** A clone must not create a second object owning the same registration/timer. */
    public function __clone(): void
    {
        throw new \LogicException('Create a new Crontab instead of cloning a registered task.');
    }

    /** Generate a process-local ID without resetting it when the final task is removed. */
    protected static function createId()
    {
        if (self::$nextId === PHP_INT_MAX) {
            throw new \OverflowException('Cron registration IDs are exhausted.');
        }
        return ++self::$nextId;
    }

    /** Plan one actual local minute; do not replay older minutes or repeat one after a backward clock step. */
    private function plan(float $now, bool $new): void
    {
        $second = (int) floor($now);
        $minute = self::minuteStart($now);
        if ($this->plannedMinute !== null && $minute <= $this->plannedMinute) {
            return;
        }
        $this->plannedMinute = $minute;
        if ($this->timer !== null) {
            self::cancelTimer($this->driver, $this->timer);
            $this->timer = null;
        }
        $this->times = array_values(array_filter($this->parser->parse($this->_rule, $second),
            static fn(int $time): bool => !$new || $time >= $second));
        $this->armNext($now);
    }

    /** Retain only the next callback, arming its successor before running application code. */
    private function armNext(float $now): void
    {
        if ($this->times === []) {
            return;
        }
        $time = array_shift($this->times);
        $id = $this->_id;
        $timer = null;
        $timer = self::addTimer($this->driver, max(0.000001, $time - $now), static function () use ($id, $time, &$timer): void {
            $task = self::$_instances[$id] ?? null;
            if ($task === null || $task->timer !== $timer) {
                return;
            }
            $task->timer = null;
            $now = microtime(true);
            if ($time > (int) floor($now)) {
                array_unshift($task->times, $time);
                $task->armNext($now);
                return;
            }
            if ($time < self::minuteStart($now)) {
                $task->plan($now, false);
                return;
            }
            $task->armNext($now);
            ($task->_callback)();
        });
        $this->timer = $timer;
    }

    /** Keep one minute-boundary timer per live driver, never one repeating timer per task. */
    private static function ensureMinuteTimer(string $driver): void
    {
        if (isset(self::$minuteTimers[$driver]) || !self::hasDriver($driver)) {
            return;
        }
        $now = microtime(true);
        $delay = self::minuteStart($now) + 60 - $now;
        $timer = null;
        $timer = self::addTimer($driver, max(0.000001, $delay), static function () use ($driver, &$timer): void {
            if ((self::$minuteTimers[$driver] ?? null) !== $timer) {
                return;
            }
            unset(self::$minuteTimers[$driver]);
            try {
                $now = microtime(true);
                foreach (self::$_instances as $task) {
                    if ($task->driver === $driver) {
                        $task->plan($now, false);
                    }
                }
            } finally {
                self::ensureMinuteTimer($driver);
            }
        });
        self::$minuteTimers[$driver] = $timer;
    }

    /** Locate the actual local minute without rebuilding a date that could lose a repeated DST offset. */
    private static function minuteStart(float $now): int
    {
        $second = (int) floor($now);
        return $second - (int) date('s', $second);
    }

    /** Check registrations rather than letting a new task overwrite a process-global driver. */
    private static function hasDriver(string $driver): bool
    {
        foreach (self::$_instances as $task) {
            if ($task->driver === $driver) {
                return true;
            }
        }
        return false;
    }

    /** Bridge existing timer APIs only; Swoole is legacy compatibility, not new coroutine support. */
    private static function addTimer(?string $driver, float $delay, callable $callback): int
    {
        if ($driver === 'workerman') {
            $timer = \Workerman\Timer::add($delay, $callback, [], false);
        } elseif ($driver === 'swoole' && class_exists(\Swoole\Timer::class)) {
            $timer = \Swoole\Timer::after(max(1, (int) ceil($delay * 1000)), $callback);
        } else {
            throw new \RuntimeException('The configured cron timer runtime is unavailable.');
        }
        // Workerman Event starts at zero; only null represents an absent Workerman timer.
        if (!is_int($timer) || $timer < ($driver === 'workerman' ? 0 : 1)) {
            throw new \RuntimeException('Unable to register a cron timer.');
        }
        return $timer;
    }

    /** Cancel only an owned timer; a false result may mean it was already dequeued, so wrappers also guard IDs. */
    private static function cancelTimer(?string $driver, int $timer): void
    {
        if ($driver === 'workerman') {
            \Workerman\Timer::del($timer);
        } elseif ($driver === 'swoole') {
            \Swoole\Timer::clear($timer);
        }
    }
}
