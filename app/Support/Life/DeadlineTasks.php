<?php

namespace App\Support\Life;

use App\Models\Collection;
use App\Models\DeadlineMarker;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Turns actionable life-area deadlines (expiring documents and IDs, vehicle inspection and service)
 * into to-dos once they come within their lead time, so the deadline lands in the task list and not
 * only in the calendar and the push reminder. Each deadline is created once (see DeadlineMarker).
 */
class DeadlineTasks
{
    /** Life-date kinds that deserve a to-do (actionable deadlines, not birthdays or trips). */
    public const ACTIONABLE = ['document', 'vehicle'];

    /** A to-do appears at most this many days before the deadline, even if the lead time is longer. */
    public const MAX_LEAD_DAYS = 60;

    /**
     * Creates the due deadline to-dos and returns how many were created.
     */
    public static function create(): int
    {
        $created = 0;

        User::query()->each(function (User $user) use (&$created) {
            $created += self::createForUser($user);
        });

        return $created;
    }

    public static function createForUser(User $user): int
    {
        $household = $user->currentHousehold();

        if (! $household) {
            return 0;
        }

        $today = today();
        $events = LifeDates::between($user, $today, $today->copy()->addDays(self::MAX_LEAD_DAYS));
        $created = 0;

        foreach ($events as $event) {
            if (! $event['remind'] || ! in_array($event['kind'], self::ACTIONABLE, true)) {
                continue;
            }

            $daysLeft = (int) $today->diffInDays(Carbon::parse($event['date']), false);
            $lead = min($event['remind_days'], self::MAX_LEAD_DAYS);

            if ($daysLeft < 0 || $daysLeft > $lead) {
                continue;
            }

            $key = $event['kind'].':'.$event['date'].':'.md5($event['title'].'|'.$event['url']);

            if (DeadlineMarker::where('user_id', $user->id)->where('key', $key)->exists()) {
                continue;
            }

            self::createTask($user, $household->id, $event);
            DeadlineMarker::create(['user_id' => $user->id, 'key' => $key, 'due_on' => $event['date']]);
            $created++;
        }

        return $created;
    }

    /**
     * @param  array{date: string, title: string, url: string, kind: string, remind_days: int, remind: bool}  $event
     */
    private static function createTask(User $user, int $householdId, array $event): void
    {
        $tasks = Collection::tasksFor($householdId)->load('properties');

        $task = new Item([
            'household_id' => $householdId,
            'collection_id' => $tasks->id,
            'type' => Item::TYPE_TODO,
            'visibility' => Item::VISIBILITY_PRIVATE,
            'title' => mb_substr($event['title'], 0, 255),
            'content' => ['body' => __('Deadline from your life areas.')."\n\n".$event['url']],
            'created_by' => $user->id,
            'position' => (int) Item::where('collection_id', $tasks->id)->max('position') + 1,
        ]);

        if ($dueProperty = $tasks->systemProperty('due')) {
            $task->setPropertyValue($dueProperty, $event['date']);
        }

        if ($assigneeProperty = $tasks->systemProperty('assignee')) {
            $task->setPropertyValue($assigneeProperty, $user->id);
        }

        $task->save();
    }
}
