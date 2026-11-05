# Scheduled Tasks

[简体中文](README.md) | English

Run second/minute schedules inside resident workers. Stable `cron-expression 3.6.1` is bundled; no extra installation is needed. Unused tasks do not load the parser or create timers.

## Configuration

Add a process to the existing `config/process.php`, keeping other entries:

```php
'RC_Crontab_Task' => [
    'handler' => support\process\Crontab::class,
    'count' => 1,
],
```

Put the handler in `support/process/Crontab.php`:

```php
namespace support\process;

use RC\Helper\Crontab\Crontab as Task;

/** Register schedules inside the framework-managed task worker. */
class Crontab
{
    /** The framework injects runtime type, Worker and Timer; do not bootstrap the framework in business code. */
    public function __construct($type, $worker, $timer)
    {
        new Task('*/10 * * * * *', static function (): void {
            echo date('Y-m-d H:i:s'), PHP_EOL;
        }, $type, 'heartbeat');

        new Task('0 2 * * *', static function (): void {
            // Call an existing application model or shared function here.
        }, $type, 'daily-task');
    }
}
```

Existing custom workers can register during startup too. Never create schedules repeatedly in HTTP requests or in a pre-fork master. With Workerman directly, register in `onWorkerStart`.

## Expressions

Five fields start with minutes; six fields start with seconds. A trailing year or shell command is not supported.

```text
sec min hour day month weekday    Six fields
    min hour day month weekday    Five fields, second fixed at 0
```

| Example | Meaning |
| --- | --- |
| `*/5 * * * * *` | Every five seconds |
| `*/5 * * * *` | Every five minutes |
| `5-15/5 * * * * *` | Seconds 5, 10 and 15 each minute |
| `5/10 * * * * *` | Every ten seconds from second 5 through second 55 |
| `0 9 * * MON-FRI` | Weekdays at 09:00 |
| `0 0 L * *` | Last calendar day at 00:00 |
| `0 0 LW * *` | Last weekday of the month at 00:00 |
| `0 0 1W * *` | Weekday nearest day 1 without crossing the month |
| `0 0 * * FRI#2` | Second Friday of the month at 00:00 |
| `0 0 * * 5L` | Last Friday of the month at 00:00 |
| `@hourly` / `@daily` | Hourly / daily at midnight |

Supports numbers, lists, ascending ranges, positive steps, `JAN-DEC`, `SUN-SAT`, and Sunday as `0` or `7`. `?` can occupy either day field alone, not both. Built-in hour/day/week/month/year macros work; process-global custom aliases are not read. A target such as `31W` does not match when that date does not exist in the month.

> RC retains its historical day-of-month AND weekday rule. `0 0 1 * MON` means a first day that is also Monday, not every first day OR every Monday.

Zero steps, reversed ranges, out-of-range values, incomplete lists and unknown syntax throw during registration. A positive step wider than its finite range selects only the origin, without wrapping. Maximum expression length is 1024 bytes.

## Cancellation

```php
$task = new \RC\Helper\Crontab\Crontab('*/10 * * * * *', static function (): void {
    // Perform the task.
}, 'workerman', 'sync');

$id = $task->getId();
$name = $task->getName();
$all = \RC\Helper\Crontab\Crontab::getAll();
$removed = $task->destroy(); // Or Crontab::remove($id) / remove($task).
```

Removal cancels the owned pending timer. An already-dequeued stale callback checks registration too. Repeated removal returns false; removing the last task releases the shared minute timer. Names do not deduplicate registrations, and IDs are process-local.

The original `($rule, $callback, $type, $name)` shape remains. Omitted type defaults to Workerman. Explicit `type=null` registers metadata without starting, and later tasks cannot activate it by switching a global driver. Do not clone a task; construct another registration instead.

## Runtime Boundaries

- Uses the task process `default_timezone`, falling back to application timezone. Do not temporarily mutate process-global timezone from business requests.
- Mid-minute registration schedules the current second onward, not earlier occurrences. Real Unix minutes distinguish repeated local DST minutes. A system-clock step back does not reschedule an already planned Unix minute; a large rollback can pause new plans until that minute is passed. A monotonic timer waking early after rollback waits again instead of running application code early.
- Busy-loop delays can execute overdue points within the current minute consecutively. Entire skipped older minutes are not replayed. This is neither hard-real-time nor persistent scheduling; process exits do not create durable catch-up records.
- Normal Workerman callbacks run in the same process. Blocking work delays other tasks; use existing framework queues/independent workers where appropriate. Exceptions reach the existing event-loop error handler; the adapter does not swallow them or promise continuation after that handler terminates a process.
- `count=1` prevents duplicate local task workers. Multiple hosts still need idempotency or suitable distributed coordination. Names, IDs and compiled expressions do not guarantee cross-host exactly-once execution.
- At most one pending timer per task and one minute timer per driver. `getAll()` includes explicit dormant registrations. External `Timer::delAll()` destroys other framework timers too; it is not single-task cleanup.

Swoole retains only its existing timer bridge, with no new coroutine support. Workerman is the mainline and the actual event-loop validation target.

## Parsing And Packaging

```php
$parser = new \RC\Helper\Crontab\Parser();
$valid = $parser->isValid('5-15/5 * * * * *');
$times = $parser->parse('5-15/5 * * * * *', 0, 'UTC'); // [5, 10, 15]
```

`parse()` returns all matching timestamps in the selected local minute, possibly before the supplied second. It is not a next-run search. Timestamp zero is not current time. The third argument changes only this parse's timezone, with no tasks/timers or global timezone changes. Validation checks grammar/ranges, not whether February 31 exists.

Runtime sources/licenses stay inside the package and load from PHAR without releasing PHP sources beside an executable. Compatible external Composer sources take precedence; only exact tested `dragonmantank/cron-expression:3.6.1` is currently accepted. Re-audit adapters before another version. Former protected parser/scheduler internals are not compatibility APIs; custom subclasses need review. See [migration notes](../../../data/docs/crontab-migration.en.md).
