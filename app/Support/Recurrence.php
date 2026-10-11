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
     * dates keep their anchor day where the month has it (31 Jan → 28 Feb →
     * 31 Mar) – pass $anchorDay to keep that anchor fixed across repeated
     * calls instead of re-deriving it from an already-shifted $dueDate
     * (otherwise a short month in between would permanently shrink it).
     */
    public static function next(string $dueDate, string $rule, ?Carbon $today = null, ?int $anchorDay = null): ?string
    {
        $today = ($today ?? now())->copy()->startOfDay();

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $dueDate);
        } catch (\Exception) {
            return null;
        }

        $anchorDay ??= $date->day;
        $steps = 0;

        do {
            $date = match ($rule) {
                self::DAILY => $date->addDay(),
                self::WEEKLY => $date->addWeek(),
                self::MONTHLY => self::addMonthKeepingAnchor($date, $anchorDay),
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
     * Advances $date by one month, then snaps onto $anchorDay (or the last day of the
     * month, whichever is smaller).
     */
    private static function addMonthKeepingAnchor(Carbon $date, int $anchorDay): Carbon
    {
        $target = $date->addMonthNoOverflow();
        $lastDayOfTarget = $target->copy()->startOfMonth()->addMonthNoOverflow()->subDay()->day;

        return $target->setDay(min($anchorDay, $lastDayOfTarget));
    }

    /**
     * Upcoming dates after $dueDate that fall into [$start, $end] (calendar preview).
     *
     * @return array<int, string>
     */
    public static function occurrencesBetween(string $dueDate, string $rule, Carbon $start, Carbon $end, int $limit = 62, ?int $anchorDay = null): array
    {
        try {
            $anchorDay ??= Carbon::createFromFormat('!Y-m-d', $dueDate)->day;
            $current = self::fastForward($dueDate, $rule, $start, $anchorDay);
        } catch (\Exception) {
            return [];
        }

        $dates = [];

        while (count($dates) < $limit && ($current = self::next($current, $rule, Carbon::createFromFormat('!Y-m-d', $current), $anchorDay)) !== null) {
            if ($current > $end->format('Y-m-d')) {
                break;
            }

            if ($current >= $start->format('Y-m-d')) {
                $dates[] = $current;
            }
        }

        return $dates;
    }

    /**
     * Arithmetically jumps $dueDate to just short of $start instead of stepping through
     * every occurrence since the task was created (an old daily/weekly repeat would
     * otherwise need thousands of next() calls to catch up). The remaining gap is closed
     * by the normal next() loop, so no occurrence is skipped.
     */
    private static function fastForward(string $dueDate, string $rule, Carbon $start, int $anchorDay): string
    {
        $date = Carbon::createFromFormat('!Y-m-d', $dueDate);

        $periods = match ($rule) {
            self::DAILY => (int) $date->diffInDays($start, false),
            self::WEEKLY => intdiv((int) $date->diffInDays($start, false), 7),
            self::MONTHLY => (int) $date->diffInMonths($start, false),
            self::YEARLY => (int) $date->diffInYears($start, false),
            default => 0,
        };

        // Stays at least one period behind $start: next() still walks the remaining
        // steps so no occurrence is skipped, it just starts much closer.
        $periods = max(0, $periods - 1);

        if ($periods === 0) {
            return $dueDate;
        }

        return (match ($rule) {
            self::DAILY => $date->copy()->addDays($periods),
            self::WEEKLY => $date->copy()->addWeeks($periods),
            self::MONTHLY => $date->copy()->setDay(min($anchorDay, $date->daysInMonth))->addMonthsNoOverflow($periods),
            self::YEARLY => $date->copy()->addYearsNoOverflow($periods),
            default => $date->copy(),
        })->format('Y-m-d');
    }
}
