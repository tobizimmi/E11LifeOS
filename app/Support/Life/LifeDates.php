<?php

namespace App\Support\Life;

use App\Models\Contact;
use App\Models\EmergencyEntry;
use App\Models\HealthEntry;
use App\Models\HomeTask;
use App\Models\Meter;
use App\Models\User;
use App\Models\Vehicle;
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
     * Shared records (a vehicle of the household) are shown to everyone but only remind the person who created them ("remind").
     *
     * @return list<array{date: string, title: string, url: string, kind: string, remind_days: int, remind: bool}>
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
                'remind' => true,
            ];
        }

        array_push($events, ...self::vehicleEvents($user, $from, $until));
        array_push($events, ...self::homeEvents($user, $from, $until));
        array_push($events, ...self::peopleEvents($user, $from, $until));

        HealthEntry::query()->whereHas('profile', fn ($query) => $query->where('user_id', $user->id))->with('profile')
            ->where('kind', '!=', HealthEntry::MEDICATION)->whereBetween('next_due_on', [$from->toDateString(), $until->toDateString()])->get()
            ->each(function (HealthEntry $entry) use (&$events) {
                $events[] = ['date' => $entry->next_due_on->toDateString(), 'title' => ($entry->kind === HealthEntry::VACCINATION ? '💉 ' : '🩺 ').$entry->title.' · '.$entry->profile->name, 'url' => route('health.index', ['profile' => $entry->health_profile_id, 'tab' => $entry->kind === HealthEntry::APPOINTMENT ? 'appointments' : 'prevention']), 'kind' => 'health', 'remind_days' => $entry->kind === HealthEntry::APPOINTMENT ? 1 : 14, 'remind' => true];
            });

        usort($events, fn (array $a, array $b) => [$a['date'], $a['title']] <=> [$b['date'], $b['title']]);

        return $events;
    }

    /**
     * Events for which a reminder is due today: at their own lead time, a week and a day before and on the day.
     *
     * @return list<array{date: string, title: string, url: string, kind: string, remind_days: int, remind: bool}>
     */
    public static function dueForReminder(User $user): array
    {
        $today = today();
        $maxLead = 400;

        return array_values(array_filter(self::between($user, $today, $today->copy()->addDays($maxLead)), function (array $event) use ($today) {
            $daysLeft = (int) $today->diffInDays(Carbon::parse($event['date']), false);

            return $event['remind'] && in_array($daysLeft, [...self::REMIND_BEFORE, $event['remind_days']], true);
        }));
    }

    /**
     * Inspection, service and tyre changes of the vehicles the member can see.
     *
     * @return list<array{date: string, title: string, url: string, kind: string, remind_days: int, remind: bool}>
     */
    private static function vehicleEvents(User $user, Carbon $from, Carbon $until): array
    {
        $household = $user->currentHousehold();

        if (! $household) {
            return [];
        }

        $events = [];

        foreach (Vehicle::query()->visibleTo($user, $household->id)->get() as $vehicle) {
            $url = route('vehicles.show', $vehicle);
            $remind = $vehicle->created_by === $user->id;

            foreach ([['inspection_due', '✅ '.__('Inspection (TÜV)'), 30], ['service_due', '🔧 '.__('Service'), 14]] as [$field, $label, $lead]) {
                if ($vehicle->{$field} && $vehicle->{$field}->between($from, $until)) {
                    $events[] = ['date' => $vehicle->{$field}->toDateString(), 'title' => $label.' · '.$vehicle->name, 'url' => $url, 'kind' => 'vehicle', 'remind_days' => $lead, 'remind' => $remind];
                }
            }

            if ($vehicle->tyre_reminders) {
                foreach (VehicleStats::tyreChangesBetween($from, $until) as $change) {
                    $events[] = ['date' => $change['date']->toDateString(), 'title' => '🛞 '.$change['label'].' · '.$vehicle->name, 'url' => $url, 'kind' => 'vehicle', 'remind_days' => 14, 'remind' => $remind];
                }
            }
        }

        return $events;
    }

    /**
     * Maintenance jobs at home and the yearly meter reading on 31 December.
     *
     * @return list<array{date: string, title: string, url: string, kind: string, remind_days: int, remind: bool}>
     */
    private static function homeEvents(User $user, Carbon $from, Carbon $until): array
    {
        $household = $user->currentHousehold();

        if (! $household) {
            return [];
        }

        $events = [];

        foreach (HomeTask::where('household_id', $household->id)->whereBetween('next_due_on', [$from->toDateString(), $until->toDateString()])->get() as $task) {
            $events[] = ['date' => $task->next_due_on->toDateString(), 'title' => trim(($task->icon ?: '🔧').' '.$task->title), 'url' => route('house.index'), 'kind' => 'home', 'remind_days' => $task->remind_days, 'remind' => $task->created_by === $user->id];
        }

        $meterOwners = Meter::where('household_id', $household->id)->pluck('created_by');

        if ($meterOwners->isNotEmpty()) {
            for ($year = $from->year; $year <= $until->year; $year++) {
                $date = Carbon::create($year, 12, 31);

                if ($date->between($from, $until)) {
                    $events[] = ['date' => $date->toDateString(), 'title' => '🔢 '.__('Read the meters'), 'url' => route('house.index', ['tab' => 'meters']), 'kind' => 'home', 'remind_days' => 0, 'remind' => $meterOwners->contains($user->id)];
                }
            }
        }

        return $events;
    }

    /**
     * Birthdays and anniversaries of the people the member can see (every year in the range).
     *
     * @return list<array{date: string, title: string, url: string, kind: string, remind_days: int, remind: bool}>
     */
    private static function peopleEvents(User $user, Carbon $from, Carbon $until): array
    {
        $household = $user->currentHousehold();

        if (! $household) {
            return [];
        }

        $events = [];

        foreach (Contact::query()->visibleTo($user, $household->id)->where(fn ($query) => $query->whereNotNull('birthday')->orWhereNotNull('anniversary'))->get() as $contact) {
            foreach (['birthday', 'anniversary'] as $field) {
                if (! $contact->{$field}) {
                    continue;
                }

                for ($date = Contact::nextOccurrence($contact->{$field}, $from); $date->lte($until); $date = Contact::nextOccurrence($contact->{$field}, $date->copy()->addDay())) {
                    $years = $date->year - $contact->{$field}->year;
                    $title = $field === 'birthday'
                        ? '🎂 '.($contact->birth_year_known && $years > 0 ? __(':name turns :age', ['name' => $contact->name, 'age' => $years]) : __('Birthday of :name', ['name' => $contact->name]))
                        : '💍 '.trim(($contact->anniversary_label ?: __('Anniversary')).' · '.$contact->name).($years > 0 ? ' ('.$years.')' : '');

                    $events[] = ['date' => $date->toDateString(), 'title' => $title, 'url' => route('people.index', ['person' => $contact->id]), 'kind' => 'people', 'remind_days' => $contact->remind_days, 'remind' => $contact->created_by === $user->id];
                }
            }
        }

        return $events;
    }
}
