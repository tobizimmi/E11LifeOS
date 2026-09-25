<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Next due date of a repeating task ("daily", "weekly", "monthly", "yearly").
 */
class Recurrence
{
    public const DAILY = 'daily';

    public const WEEKLY = 'weekly';

    public const MONTHLY = 'monthly';

    public const YEARLY = 'yearly';

    /**
     * Steps from the due date until the result lies after $today, so a task
     * that was ticked off late does not reappear already overdue. Monthly
     * dates keep their original day where the month has it (31 Jan → 28 Feb
     * → 31 Mar).
     */
    public static function next(string $dueDate, string $rule, ?Carbon $today = null): ?string
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $date = Carbon::createFromFormat('!Y-m-d', $dueDate);

        if (! $date) {
            return null;
        }

        $anchorDay = $date->day;
        $steps = 0;

        do {
            $date = match ($rule) {
                self::DAILY => $date->addDay(),
                self::WEEKLY => $date->addWeek(),
                self::MONTHLY => $date->addMonthNoOverflow()->setDay(min($anchorDay, $date->copy()->addMonthNoOverflow()->startOfMonth()->subDay()->day)),
                self::YEARLY => $date->addYearNoOverflow(),
                default => null,
            };

            if ($date === null || ++$steps > 5000) {
                return null;
            }
        } while ($date->lte($today));

        return $date->format('Y-m-d');
    }

    /**
     * Upcoming dates after $dueDate that fall into [$start, $end] (calendar preview).
     *
     * @return array<int, string>
     */
    public static function occurrencesBetween(string $dueDate, string $rule, Carbon $start, Carbon $end, int $limit = 62): array
    {
        $dates = [];
        $current = $dueDate;

        while (count($dates) < $limit && ($current = self::next($current, $rule, Carbon::createFromFormat('!Y-m-d', $current))) !== null) {
            if ($current > $end->format('Y-m-d')) {
                break;
            }

            if ($current >= $start->format('Y-m-d')) {
                $dates[] = $current;
            }
        }

        return $dates;
    }
}
