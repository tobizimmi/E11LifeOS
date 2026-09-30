<?php

namespace App\Support\Life;

use App\Models\WorkDay;
use App\Models\WorkProfile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Working time account and leave: target versus actual hours per day, overtime balance,
 * remaining leave, bridge days and the break rules of the German Working Hours Act.
 */
class WorkTime
{
    /**
     * @var array<int, array<string, string>>
     */
    private array $holidays = [];

    /**
     * Days before this are not counted as missing (tracking started later than the period).
     */
    public readonly Carbon $trackingSince;

    public function __construct(public readonly WorkProfile $profile, ?Carbon $trackingSince = null)
    {
        $this->trackingSince = ($trackingSince ?? $profile->created_at ?? today())->copy()->startOfDay();
    }

    /**
     * Tracking starts with the first entry or when the profile was set up, whichever is earlier.
     */
    public static function forProfile(WorkProfile $profile): self
    {
        $firstEntry = WorkDay::where('user_id', $profile->user_id)->min('date');
        $since = $profile->created_at?->copy() ?? today();

        return new self($profile, $firstEntry && Carbon::parse($firstEntry)->lt($since) ? Carbon::parse($firstEntry) : $since);
    }

    /**
     * Whether a past working day without an entry counts as missing.
     */
    public function isTracked(Carbon $date): bool
    {
        return $date->gte($this->trackingSince);
    }

    /**
     * @return array<string, string>
     */
    public function holidays(int $year): array
    {
        return $this->holidays[$year] ??= PublicHolidays::for($year, $this->profile->state);
    }

    public function holidayName(Carbon $date): ?string
    {
        return $this->holidays($date->year)[$date->toDateString()] ?? null;
    }

    public function isWorkingDay(Carbon $date): bool
    {
        return in_array($date->dayOfWeekIso, $this->profile->work_days, true) && $this->holidayName($date) === null;
    }

    public function targetMinutes(Carbon $date): int
    {
        return $this->isWorkingDay($date) ? $this->profile->dailyTargetMinutes() : 0;
    }

    /**
     * Minutes the day counts for: leave and sick days count as the target, time off in lieu as nothing.
     */
    public function creditedMinutes(Carbon $date, ?WorkDay $day): int
    {
        if (! $day) {
            return 0;
        }

        $target = $this->targetMinutes($date);

        return match ($day->type) {
            'vacation', 'sick' => $target,
            'half_vacation' => intdiv($target, 2) + $day->workedMinutes(),
            'overtime_off' => 0,
            default => $day->workedMinutes(),
        };
    }

    /**
     * One row per day with target, credited minutes and difference.
     *
     * @param  Collection<int, WorkDay>  $days
     * @return array{rows: list<array{date: Carbon, holiday: ?string, working: bool, day: ?WorkDay, target: int, actual: int, diff: int, hint: ?string, missing: bool}>, target: int, actual: int, diff: int}
     */
    public function period(Carbon $from, Carbon $to, Collection $days): array
    {
        $byDate = $days->keyBy(fn (WorkDay $day) => $day->date->toDateString());
        $rows = [];
        $totals = ['target' => 0, 'actual' => 0];

        for ($date = $from->copy()->startOfDay(); $date->lte($to); $date->addDay()) {
            $day = $byDate[$date->toDateString()] ?? null;
            $target = $this->targetMinutes($date);
            // Future days without an entry are not counted as missing yet.
            $counts = $day !== null || ($date->lt(today()) && $this->isTracked($date));
            $actual = $this->creditedMinutes($date, $day);

            $rows[] = [
                'date' => $date->copy(),
                'holiday' => $this->holidayName($date),
                'working' => $this->isWorkingDay($date),
                'day' => $day,
                'target' => $target,
                'actual' => $actual,
                'diff' => $counts ? $actual - $target : 0,
                'hint' => $day ? self::breakHint($day) : null,
                'missing' => ! $day && $target > 0 && $counts,
            ];

            if ($counts) {
                $totals['target'] += $target;
                $totals['actual'] += $actual;
            }
        }

        return ['rows' => $rows, 'target' => $totals['target'], 'actual' => $totals['actual'], 'diff' => $totals['actual'] - $totals['target']];
    }

    /**
     * Overtime balance of the year up to today (starting value from the settings included).
     *
     * @param  Collection<int, WorkDay>  $days  entries of the year
     */
    public function balance(Collection $days, ?Carbon $today = null): int
    {
        $today ??= today();

        return $this->profile->overtime_start_minutes + $this->period($today->copy()->startOfYear(), $today, $days)['diff'];
    }

    /**
     * @param  Collection<int, WorkDay>  $days  entries of the year
     * @return array{entitlement: float, taken: float, planned: float, remaining: float, sick: int}
     */
    public function leave(int $year, Collection $days, ?Carbon $today = null): array
    {
        $today ??= today();
        $value = fn (WorkDay $day) => $day->type === 'half_vacation' ? 0.5 : 1.0;
        $leave = $days->filter(fn (WorkDay $day) => $day->date->year === $year && in_array($day->type, ['vacation', 'half_vacation'], true) && $this->isWorkingDay($day->date));
        $entitlement = $this->profile->vacation_days + ($this->profile->carry_over_year === $year ? $this->profile->carry_over_days : 0.0);
        $taken = (float) $leave->filter(fn (WorkDay $day) => $day->date->lte($today))->sum($value);
        $planned = (float) $leave->filter(fn (WorkDay $day) => $day->date->gt($today))->sum($value);

        return [
            'entitlement' => $entitlement,
            'taken' => $taken,
            'planned' => $planned,
            'remaining' => $entitlement - $taken - $planned,
            'sick' => $days->filter(fn (WorkDay $day) => $day->date->year === $year && $day->type === 'sick')->count(),
        ];
    }

    /**
     * Working days in a range (what a holiday there costs in leave days).
     *
     * @return list<Carbon>
     */
    public function workingDaysBetween(Carbon $from, Carbon $to): array
    {
        $dates = [];

        for ($date = $from->copy()->startOfDay(); $date->lte($to) && count($dates) < 366; $date->addDay()) {
            if ($this->isWorkingDay($date)) {
                $dates[] = $date->copy();
            }
        }

        return $dates;
    }

    /**
     * Bridge days: a holiday next to a weekend-like gap where few leave days give many days off.
     *
     * @return list<array{holiday: string, date: Carbon, leave: list<Carbon>, free_days: int}>
     */
    public function bridgeDays(int $year, ?Carbon $from = null): array
    {
        $from ??= today();
        $tips = [];

        foreach ($this->holidays($year) as $date => $name) {
            $holiday = Carbon::parse($date);

            if ($holiday->lt($from) || ! in_array($holiday->dayOfWeekIso, $this->profile->work_days, true)) {
                continue;
            }

            // Leave days between the holiday and the nearest day off (at most two).
            foreach ([-1, 1] as $direction) {
                $leave = [];
                $cursor = $holiday->copy()->addDays($direction);

                while ($this->isWorkingDay($cursor) && count($leave) < 3) {
                    $leave[] = $cursor->copy();
                    $cursor->addDays($direction);
                }

                if ($leave === [] || count($leave) > 2) {
                    continue;
                }

                $start = $direction < 0 ? $cursor->copy()->addDay() : $holiday->copy();
                $end = $direction < 0 ? $holiday->copy() : $cursor->copy()->subDay();

                // Extend over the free days on both ends (weekend, other holidays).
                while (! $this->isWorkingDay($start->copy()->subDay()) && $start->diffInDays($end) < 14) {
                    $start->subDay();
                }

                while (! $this->isWorkingDay($end->copy()->addDay()) && $start->diffInDays($end) < 14) {
                    $end->addDay();
                }

                $tips[] = ['holiday' => $name, 'date' => $holiday, 'leave' => $leave, 'free_days' => (int) $start->diffInDays($end) + 1];
            }
        }

        return collect($tips)->unique(fn (array $tip) => $tip['leave'][0]->toDateString())->sortBy(fn (array $tip) => $tip['date']->timestamp)->values()->all();
    }

    /**
     * Breaks required by § 4 ArbZG (30 min over 6 h, 45 min over 9 h) and the 10-hour limit.
     */
    public static function breakHint(WorkDay $day): ?string
    {
        if (! $day->starts_at || ! $day->ends_at) {
            return null;
        }

        $worked = $day->workedMinutes();
        $required = $worked > 540 ? 45 : ($worked > 360 ? 30 : 0);

        if ($worked > 600) {
            return __('More than 10 hours of work – the legal maximum.');
        }

        return $day->break_minutes < $required ? __('At least :minutes minutes of break are required for this working time.', ['minutes' => $required]) : null;
    }

    public static function format(int $minutes, bool $signed = false): string
    {
        $sign = $minutes < 0 ? '−' : ($signed && $minutes > 0 ? '+' : '');
        $minutes = abs($minutes);

        return sprintf('%s%d:%02d', $sign, intdiv($minutes, 60), $minutes % 60);
    }
}
