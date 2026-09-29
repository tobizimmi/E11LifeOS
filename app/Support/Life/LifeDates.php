<?php

namespace App\Support\Life;

use App\Models\EmergencyEntry;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Dates of the life areas (expiring documents and later vehicles, maintenance, health, people)
 * for the calendar and the daily reminders.
 */
class LifeDates
{
    /**
     * Days before a date on which a reminder goes out (besides the entry's own lead time).
     */
    public const REMIND_BEFORE = [7, 1, 0];

    /**
     * @return list<array{date: string, title: string, url: string, kind: string, remind_days: int}>
     */
    public static function between(User $user, Carbon $from, Carbon $until): array
    {
        $events = [];

        foreach (EmergencyEntry::where('user_id', $user->id)->whereNotNull('expires_on')->whereBetween('expires_on', [$from->toDateString(), $until->toDateString()])->get() as $entry) {
            $events[] = [
                'date' => $entry->expires_on->toDateString(),
                'title' => '🪪 '.__(':document expires', ['document' => $entry->title]),
                'url' => route('emergency.index', ['section' => $entry->section]),
                'kind' => 'document',
                'remind_days' => $entry->remind_days,
            ];
        }

        usort($events, fn (array $a, array $b) => [$a['date'], $a['title']] <=> [$b['date'], $b['title']]);

        return $events;
    }

    /**
     * Events for which a reminder is due today: at their own lead time, a week and a day before and on the day.
     *
     * @return list<array{date: string, title: string, url: string, kind: string, remind_days: int}>
     */
    public static function dueForReminder(User $user): array
    {
        $today = today();
        $maxLead = 400;

        return array_values(array_filter(self::between($user, $today, $today->copy()->addDays($maxLead)), function (array $event) use ($today) {
            $daysLeft = (int) $today->diffInDays(Carbon::parse($event['date']), false);

            return in_array($daysLeft, [...self::REMIND_BEFORE, $event['remind_days']], true);
        }));
    }
}
