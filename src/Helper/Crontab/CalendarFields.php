<?php

declare(strict_types=1);

namespace RC\Helper\Crontab;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/DayOfMonth.php';

/** Keep the upstream field factory, with the RC day-field fixes isolated from pristine sources. */
class CalendarFields extends \Cron\FieldFactory
{
    private ?DayOfMonth $day = null;

    /** Return stable flyweights; calendar fields carry no application, request or task state. */
    public function getField(int $position): \Cron\FieldInterface
    {
        return $position === \Cron\CronExpression::DAY
            ? ($this->day ??= new DayOfMonth()) : parent::getField($position);
    }
}
