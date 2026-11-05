<?php

declare(strict_types=1);

namespace RC\Helper\Crontab;

require_once __DIR__ . '/bootstrap.php';

/** Repair native W/LW matching using the supplied calendar, without changing upstream files. */
class DayOfMonth extends \Cron\DayOfMonthField
{
    /** Match the last/nearest weekday without overflowing the month or consulting another timezone. */
    public function isSatisfiedBy(\DateTimeInterface $date, $value, bool $invert): bool
    {
        if ($value === 'LW' || preg_match('/^([0-9]+)W$/D', $value, $match)) {
            $last = (int) $date->format('t');
            $day = $value === 'LW' ? $last : (int) $match[1];
            if ($day < 1 || $day > $last) {
                return false;
            }
            $target = \DateTimeImmutable::createFromInterface($date)
                ->setDate((int) $date->format('Y'), (int) $date->format('n'), $day);
            $weekday = (int) $target->format('N');
            if ($weekday === 6) {
                $day += $day === 1 ? 2 : -1;
            } elseif ($weekday === 7) {
                $day += $day === $last ? -2 : 1;
            }
            return (int) $date->format('j') === $day;
        }
        return parent::isSatisfiedBy($date, $value, $invert);
    }
}
