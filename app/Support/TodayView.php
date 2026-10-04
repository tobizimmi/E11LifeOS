<?php

namespace App\Support;

use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * "My day" at the top of the dashboard: everything with a date today from all modules in one
 * timeline (tasks, dates, meals, appointments …), what comes next, and depending on the time of
 * day the morning, the rest of the day or – in the evening – a preview of tomorrow.
 */
class TodayView
{
    public const MORNING = 'morning';

    public const DAY = 'day';

    public const EVENING = 'evening';

    /**
     * @return array{mode: string, title: string, events: list<array<string, mixed>>, next: ?array<string, mixed>, minutesUntilNext: ?int, tomorrow: list<array<string, mixed>>, overdue: int, done: int}
     */
    public static function for(User $user, int $householdId, ?Carbon $now = null): array
    {
        $now ??= now();
        $mode = match (true) {
            $now->hour < 11 => self::MORNING,
            $now->hour < 18 => self::DAY,
            default => self::EVENING,
        };
        $sources = array_keys(CalendarEvents::sources($user));
        $today = $now->copy()->startOfDay();
        $events = CalendarEvents::between($user, $householdId, $today, $today, $sources);
        $overdue = collect(CalendarEvents::between($user, $householdId, $today->copy()->subDays(60), $today->copy()->subDay(), [CalendarEvents::TASKS]))
            ->where('overdue', true)->count();
        $time = $now->format('H:i');
        $upcoming = collect($events)->filter(fn (array $event) => $event['time'] !== null && $event['time'] >= $time);
        $next = $upcoming->first();

        return [
            'mode' => $mode,
            'title' => match ($mode) {
                self::MORNING => __('Your morning'),
                self::DAY => __('Your day'),
                default => __('Your evening'),
            },
            'events' => array_map(fn (array $event) => $event + ['past' => $event['time'] !== null && $event['time'] < $time], $events),
            'next' => $next,
            'minutesUntilNext' => $next ? (int) $now->copy()->setSeconds(0)->diffInMinutes($today->copy()->setTimeFromTimeString($next['time']), true) : null,
            'tomorrow' => $mode === self::EVENING ? CalendarEvents::between($user, $householdId, $today->copy()->addDay(), $today->copy()->addDay(), $sources) : [],
            'overdue' => $overdue,
            'done' => Item::query()->forHousehold($householdId)->ofType(Item::TYPE_TODO)->visibleTo($user)
                ->where('done', true)->where('completed_at', '>=', $today)->count(),
        ];
    }
}
