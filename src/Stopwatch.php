<?php

declare(strict_types=1);

namespace RC;

use RC\Helper\Stopwatch\Stopwatch as StopwatchEngine;
use RC\Helper\Stopwatch\StopwatchEvent;

/**
 * Lazy process-local framework profiling facade.
 * Use start()/stop() or stopwatch($name); create an independent engine for overlapping background tasks.
 */
class Stopwatch
{
    private static ?StopwatchEngine $_stopwatch = null;
    public static $_framework = [];
    public static $_controller = [];

    /** Start a named event with optional category; the reserved controller name begins a fresh request profile. */
    public static function start(string $eventname, ?string $category = null): StopwatchEvent
    {
        $watch = self::instance();
        if ($eventname === '__controller__' && $watch->getRootSectionEvents() !== []) {
            $watch->reset();
        }
        return $watch->start($eventname, $category);
    }

    /** Stop and restart a known event; a never-initialized facade retains the historical no-op behavior. */
    public static function lap(string $eventname): ?StopwatchEvent
    {
        return self::$_stopwatch?->lap($eventname);
    }

    /** Check a name without initializing the upstream library. */
    public static function isStarted(string $eventname): bool
    {
        return self::$_stopwatch?->isStarted($eventname) ?? false;
    }

    /** Open a section, lazily creating the engine even when no event has been started yet. */
    public static function openSection(?string $id = null): void
    {
        self::instance()->openSection($id);
    }

    /** Stop a section by id; stopSection($ignoredEvent, $id) remains accepted for older callers. */
    public static function stopSection(mixed $eventname = null, ?string $id = null): void
    {
        $id ??= $eventname;
        if (!is_string($id)) {
            throw new \InvalidArgumentException('A stopwatch section id string is required.');
        }
        self::instance()->stopSection($id);
    }

    /** Return a section's events; both getSectionEvents($id) and the old two-argument form are supported. */
    public static function getSectionEvents(mixed $eventname = null, ?string $id = null): array
    {
        $id ??= $eventname;
        if (!is_string($id)) {
            throw new \InvalidArgumentException('A stopwatch section id string is required.');
        }
        return self::$_stopwatch?->getSectionEvents($id) ?? [];
    }

    /** Stop one event, preserving every other event and section. */
    public static function stop(string $eventname): ?StopwatchEvent
    {
        return self::$_stopwatch?->stop($eventname);
    }

    /** Get a known event; unknown names retain the native LogicException contract. */
    public static function getEvent(string $eventname): StopwatchEvent
    {
        return self::instance()->getEvent($eventname);
    }

    /** Inspect recorded sections without allocating a timer when profiling has never started. */
    public static function getSections(): array
    {
        return self::$_stopwatch?->getSections() ?? [];
    }

    /** Inspect root events using the upstream 8.1 API without lazy initialization. */
    public static function getRootSectionEvents(): array
    {
        return self::$_stopwatch?->getRootSectionEvents() ?? [];
    }

    /** Explicitly discard all profiling events/sections; cached framework boot statistics are separate. */
    public static function reset(): void
    {
        self::$_stopwatch?->reset();
    }

    /** Stop one helper measurement and release idle root state in one facade call, preserving active siblings/sections. */
    public static function stopAndCollect(string $eventname): array
    {
        $watch = self::$_stopwatch;
        if ($watch === null || !$watch->isStarted($eventname)) {
            return ['time' => 0, 'memory' => 0];
        }
        $event = $watch->stop($eventname);
        $result = ['time' => $event->getDuration(), 'memory' => $event->getMemory()];
        if (count($watch->getSections()) === 1) {
            foreach ($watch->getRootSectionEvents() as $active) {
                if ($active->isStarted()) {
                    return $result;
                }
            }
            $watch->reset();
        }
        return $result;
    }

    /** Create the fractional-precision engine only on first use, including under an authoritative Composer map. */
    private static function instance(): StopwatchEngine
    {
        if (self::$_stopwatch === null) {
            require_once __DIR__ . '/Helper/Stopwatch/bootstrap.php';
            self::$_stopwatch = new StopwatchEngine(true);
        }
        return self::$_stopwatch;
    }
}
