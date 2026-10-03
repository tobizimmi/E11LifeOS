<?php

namespace App\Support\Life;

use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Habits of a member: what is due on a day, ticking off and counting, streaks, the habit
 * strength (as in Loop Habit Tracker a missed day does not reset everything) and the
 * connections between habits, activities and mood.
 */
class HabitTracker
{
    /** Days of history used for streaks, strength and the year view. */
    public const HISTORY_DAYS = 371;

    /**
     * @return Collection<int, Habit>
     */
    public static function active(User $user): Collection
    {
        return Habit::where('user_id', $user->id)->whereNull('archived_at')->orderBy('position')->orderBy('id')->get();
    }

    /**
     * Logged amounts per habit and day for the history window.
     *
     * @param  Collection<int, Habit>  $habits
     * @return array<int, array<string, int>> habit id => [Y-m-d => value]
     */
    public static function logs(Collection $habits, ?Carbon $until = null): array
    {
        $until ??= today();
        $logs = array_fill_keys($habits->pluck('id')->all(), []);

        HabitLog::whereIn('habit_id', $habits->pluck('id'))
            ->whereDate('date', '>=', $until->copy()->subDays(self::HISTORY_DAYS)->toDateString())
            ->whereDate('date', '<=', $until->toDateString())
            ->get(['habit_id', 'date', 'value'])
            ->each(function (HabitLog $log) use (&$logs) {
                $logs[$log->habit_id][$log->date->toDateString()] = $log->value;
            });

        return $logs;
    }

    /**
     * Sets the amount of a day (0 removes the entry).
     */
    public static function set(Habit $habit, Carbon $date, int $value): void
    {
        $value = max(0, min(9999, $value));

        if ($value === 0) {
            HabitLog::where('habit_id', $habit->id)->whereDate('date', $date->toDateString())->delete();

            return;
        }

        $log = HabitLog::where('habit_id', $habit->id)->whereDate('date', $date->toDateString())->first();
        $log ? $log->update(['value' => $value]) : HabitLog::create(['habit_id' => $habit->id, 'date' => $date->toDateString(), 'value' => $value]);
    }

    /**
     * Ticks a habit off (or back), or adds one to a counted habit.
     */
    public static function tap(Habit $habit, Carbon $date, int $step = 1): int
    {
        $current = (int) HabitLog::where('habit_id', $habit->id)->whereDate('date', $date->toDateString())->value('value');
        $value = $habit->type === Habit::COUNT ? max(0, $current + $step) : ($current > 0 ? 0 : 1);
        self::set($habit, $date, $value);

        return $value;
    }

    /**
     * Streak, best streak, strength (0–100) and the share of fulfilled planned days of the last 30 days.
     *
     * @param  array<string, int>  $logs  Y-m-d => value
     * @return array{streak: int, best: int, strength: int, rate: int, unit: string, today: int, week: int}
     */
    public static function stats(Habit $habit, array $logs, ?Carbon $today = null): array
    {
        $today = ($today ?? today())->copy()->startOfDay();
        $start = Carbon::parse($habit->created_at)->startOfDay()->max($today->copy()->subDays(self::HISTORY_DAYS));
        $done = fn (Carbon $day) => $habit->isFulfilledBy($logs[$day->toDateString()] ?? 0);

        if ($habit->schedule === Habit::WEEKLY) {
            return self::weeklyStats($habit, $logs, $today, $start);
        }

        // Strength: exponential smoothing over the planned days, so one missed day costs a little.
        $strength = 0.0;
        $streak = 0;
        $best = 0;
        $planned30 = 0;
        $done30 = 0;

        for ($day = $start->copy(); $day->lte($today); $day->addDay()) {
            if (! $habit->isDueOn($day)) {
                continue;
            }

            $fulfilled = $done($day);

            if ($day->isSameDay($today) && ! $fulfilled) {
                break; // Today is not over yet.
            }

            $strength = $strength * 0.93 + ($fulfilled ? 0.07 : 0);
            $streak = $fulfilled ? $streak + 1 : 0;
            $best = max($best, $streak);

            if ($day->gt($today->copy()->subDays(30))) {
                $planned30++;
                $done30 += $fulfilled ? 1 : 0;
            }
        }

        return [
            'streak' => $streak,
            'best' => $best,
            'strength' => (int) round($strength * 100),
            'rate' => $planned30 > 0 ? (int) round($done30 / $planned30 * 100) : 0,
            'unit' => 'days',
            'today' => $logs[$today->toDateString()] ?? 0,
            'week' => self::doneInWeek($habit, $logs, $today),
        ];
    }

    /**
     * "x times a week": streak and strength in weeks; the current week counts once reached.
     *
     * @param  array<string, int>  $logs
     * @return array{streak: int, best: int, strength: int, rate: int, unit: string, today: int, week: int}
     */
    private static function weeklyStats(Habit $habit, array $logs, Carbon $today, Carbon $start): array
    {
        $target = max(1, (int) $habit->per_week);
        $strength = 0.0;
        $streak = 0;
        $best = 0;
        $weeks = 0;
        $reached = 0;

        for ($week = $start->copy()->startOfWeek(); $week->lte($today); $week->addWeek()) {
            $count = self::doneInWeek($habit, $logs, $week);
            $current = $week->isSameWeek($today);

            if ($current && $count < $target) {
                break;
            }

            $share = min(1, $count / $target);
            $strength = $strength * 0.75 + $share * 0.25;
            $streak = $count >= $target ? $streak + 1 : 0;
            $best = max($best, $streak);

            if ($week->gt($today->copy()->subWeeks(5))) {
                $weeks++;
                $reached += $count >= $target ? 1 : 0;
            }
        }

        return [
            'streak' => $streak,
            'best' => $best,
            'strength' => (int) round($strength * 100),
            'rate' => $weeks > 0 ? (int) round($reached / $weeks * 100) : 0,
            'unit' => 'weeks',
            'today' => $logs[$today->toDateString()] ?? 0,
            'week' => self::doneInWeek($habit, $logs, $today),
        ];
    }

    /**
     * @param  array<string, int>  $logs
     */
    private static function doneInWeek(Habit $habit, array $logs, Carbon $day): int
    {
        $count = 0;

        for ($date = $day->copy()->startOfWeek(); $date->lte($day->copy()->endOfWeek()); $date->addDay()) {
            $count += $habit->isFulfilledBy($logs[$date->toDateString()] ?? 0) ? 1 : 0;
        }

        return $count;
    }

    /**
     * The last year as weeks of seven days (Monday first) for the year view.
     *
     * @param  array<string, int>  $logs
     * @return list<list<array{date: string, level: int, value: int, future: bool, due: bool}>>
     */
    public static function heatmap(Habit $habit, array $logs, ?Carbon $today = null): array
    {
        $today = ($today ?? today())->copy();
        $weeks = [];

        for ($week = $today->copy()->subWeeks(52)->startOfWeek(); $week->lte($today); $week->addWeek()) {
            $days = [];

            for ($i = 0; $i < 7; $i++) {
                $date = $week->copy()->addDays($i);
                $value = $logs[$date->toDateString()] ?? 0;
                $target = $habit->type === Habit::COUNT ? max(1, $habit->target) : 1;
                $days[] = [
                    'date' => $date->toDateString(),
                    'value' => $value,
                    'level' => $value <= 0 ? 0 : (int) min(4, ceil($value / $target * 4)),
                    'future' => $date->gt($today),
                    'due' => $habit->isDueOn($date),
                ];
            }

            $weeks[] = $days;
        }

        return $weeks;
    }

    /**
     * Mood over time, mood by activity and by habit (done vs. not done), from the journal.
     *
     * @return array{moods: list<array{date: string, mood: int}>, average: ?float, activities: list<array{label: string, average: float, count: int, diff: float}>, habits: list<array{habit: Habit, with: float, without: float, diff: float}>}
     */
    public static function insights(User $user, int $days = 90): array
    {
        $from = today()->subDays($days - 1)->toDateString();
        $entries = JournalEntry::where('user_id', $user->id)->where('date', '>=', $from)->whereNotNull('mood')->orderBy('date')->get(['date', 'mood', 'activities']);
        $average = $entries->isNotEmpty() ? round($entries->avg('mood'), 1) : null;

        $activities = $entries->flatMap(fn (JournalEntry $entry) => collect($entry->activities ?? [])->map(fn (string $activity) => ['label' => $activity, 'mood' => $entry->mood]))
            ->groupBy('label')
            ->filter(fn (Collection $rows) => $rows->count() >= 2)
            ->map(fn (Collection $rows, string $label) => ['label' => $label, 'average' => round($rows->avg('mood'), 1), 'count' => $rows->count(), 'diff' => round($rows->avg('mood') - (float) $average, 1)])
            ->sortByDesc('diff')->values()->all();

        $moodByDate = $entries->mapWithKeys(fn (JournalEntry $entry) => [$entry->date->toDateString() => $entry->mood]);
        $habits = self::active($user);
        $logs = self::logs($habits);
        $habitRows = [];

        foreach ($habits as $habit) {
            $with = $moodByDate->filter(fn (int $mood, string $date) => $habit->isFulfilledBy($logs[$habit->id][$date] ?? 0));
            $without = $moodByDate->reject(fn (int $mood, string $date) => $habit->isFulfilledBy($logs[$habit->id][$date] ?? 0));

            if ($with->count() >= 3 && $without->count() >= 3) {
                $habitRows[] = ['habit' => $habit, 'with' => round($with->avg(), 1), 'without' => round($without->avg(), 1), 'diff' => round($with->avg() - $without->avg(), 1)];
            }
        }

        usort($habitRows, fn (array $a, array $b) => $b['diff'] <=> $a['diff']);

        return [
            'moods' => $entries->map(fn (JournalEntry $entry) => ['date' => $entry->date->toDateString(), 'mood' => $entry->mood])->values()->all(),
            'average' => $average,
            'activities' => $activities,
            'habits' => $habitRows,
        ];
    }

    /**
     * Lines for the weekly review: how the habits went in the last seven days, and the mood.
     *
     * @return list<string>
     */
    public static function weekLines(User $user): array
    {
        $habits = self::active($user);
        $lines = [];

        if ($habits->isNotEmpty()) {
            $logs = self::logs($habits);
            $planned = 0;
            $done = 0;

            foreach ($habits as $habit) {
                $fulfilled = 0;
                $due = 0;

                for ($day = today()->subDays(6); $day->lte(today()); $day->addDay()) {
                    $due += $habit->isDueOn($day) ? 1 : 0;
                    $fulfilled += $habit->isFulfilledBy($logs[$habit->id][$day->toDateString()] ?? 0) ? 1 : 0;
                }

                $target = $habit->schedule === Habit::WEEKLY ? max(1, (int) $habit->per_week) : $due;
                $planned += $target;
                $done += min($fulfilled, $target);
            }

            $lines[] = __(':done of :planned habits done (:percent %)', ['done' => $done, 'planned' => $planned, 'percent' => $planned > 0 ? (int) round($done / $planned * 100) : 0]);
            $best = $habits->map(fn (Habit $habit) => ['habit' => $habit, 'stats' => self::stats($habit, $logs[$habit->id])])->sortByDesc(fn (array $row) => $row['stats']['streak'])->first();

            if ($best && $best['stats']['streak'] >= 3) {
                $lines[] = __('Longest streak: :habit – :count', ['habit' => $best['habit']->icon.' '.$best['habit']->name, 'count' => trans_choice($best['stats']['unit'] === 'weeks' ? ':count week|:count weeks' : ':count day|:count days', $best['stats']['streak'])]);
            }
        }

        $moods = JournalEntry::where('user_id', $user->id)->where('date', '>=', today()->subDays(6)->toDateString())->whereNotNull('mood')->pluck('mood');

        if ($moods->isNotEmpty()) {
            $lines[] = __('Mood on average: :mood', ['mood' => Journal::moods()[(int) round($moods->avg())]['icon'].' '.number_format($moods->avg(), 1, ',', '.')]);
        }

        return $lines;
    }
}
