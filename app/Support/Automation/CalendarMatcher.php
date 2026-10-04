<?php

namespace App\Support\Automation;

use App\Models\HouseholdMember;
use App\Models\Trip;
use App\Models\User;
use App\Models\WorkDay;
use App\Support\CalendarEvents;
use Illuminate\Support\Carbon;

/**
 * Whether something is entered for a day: a calendar entry whose title contains a word (e.g.
 * "Urlaub"), a trip of the household or leave in "Working time & leave".
 */
class CalendarMatcher
{
    public const CALENDAR = 'calendar';

    public const TRIPS = 'trips';

    public const LEAVE = 'leave';

    /**
     * @param  list<string>  $sources
     */
    public static function active(User $user, int $householdId, string $keyword, array $sources, Carbon $day): bool
    {
        $keyword = mb_strtolower(trim($keyword));
        $date = $day->toDateString();

        if (in_array(self::CALENDAR, $sources, true) && $keyword !== '') {
            // From a month back, so entries spanning several days that began earlier count too.
            $events = CalendarEvents::between($user, $householdId, $day->copy()->subDays(31)->startOfDay(), $day->copy()->endOfDay(), array_keys(CalendarEvents::sources($user)));

            foreach ($events as $event) {
                if ($event['date'] <= $date && ($event['end'] ?? $event['date']) >= $date && str_contains(mb_strtolower($event['title']), $keyword)) {
                    return true;
                }
            }
        }

        if (in_array(self::TRIPS, $sources, true)
            && Trip::query()->visibleTo($user, $householdId)->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->exists()) {
            return true;
        }

        if (in_array(self::LEAVE, $sources, true)) {
            $members = HouseholdMember::where('household_id', $householdId)->pluck('user_id');

            if (WorkDay::whereIn('user_id', $members)->whereDate('date', $date)->whereIn('type', ['vacation', 'half_vacation'])->exists()) {
                return true;
            }
        }

        return false;
    }
}
