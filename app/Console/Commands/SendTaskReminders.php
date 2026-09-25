<?php

namespace App\Console\Commands;

use App\Models\Collection;
use App\Models\Item;
use App\Models\User;
use App\Notifications\TaskReminder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('reminders:send')]
#[Description('E-mail everyone who opted in their due/overdue tasks and date reminders from collections (runs daily via the scheduler)')]
class SendTaskReminders extends Command
{
    public function handle(): int
    {
        $sent = 0;

        User::where('reminder_emails', true)->each(function (User $user) use (&$sent) {
            $household = $user->currentHousehold();

            if (! $household) {
                return;
            }

            $collection = Collection::tasksFor($household->id);
            $tasks = $this->dueTasksFor($user, $collection);
            $dates = $this->dateRemindersFor($user, $household->id);

            if ($tasks->isNotEmpty() || $dates->isNotEmpty()) {
                $user->notify(new TaskReminder($tasks, $collection, $dates));
                $sent++;
            }
        });

        $this->info("Reminders sent: {$sent}");

        return self::SUCCESS;
    }

    /**
     * Entries of the user's visible collections whose date property has a reminder
     * and falls on today + the reminder's days (or on today itself).
     *
     * @return \Illuminate\Support\Collection<int, array{entry: Item, collection: Collection, property: string, date: string}>
     */
    private function dateRemindersFor(User $user, int $householdId): \Illuminate\Support\Collection
    {
        $rows = collect();

        Collection::query()->visibleTo($user, $householdId)->with('properties')->get()->each(function (Collection $collection) use ($user, $rows) {
            $properties = $collection->properties->filter(fn ($property) => $property->remindDays() !== null);

            if ($properties->isEmpty()) {
                return;
            }

            $entries = Item::query()->forHousehold($collection->household_id)->visibleTo($user)
                ->where('collection_id', $collection->id)->where('done', false)->get();

            foreach ($properties as $property) {
                $dates = array_unique([now()->addDays($property->remindDays())->toDateString(), now()->toDateString()]);

                foreach ($entries as $entry) {
                    $value = (string) $entry->propertyValue($property);

                    if (in_array($value, $dates, true)) {
                        $rows->push(['entry' => $entry, 'collection' => $collection, 'property' => $property->name, 'date' => $value]);
                    }
                }
            }
        });

        return $rows->sortBy('date')->values();
    }

    /**
     * Open tasks due today or earlier that are assigned to the user, or unassigned ones they created.
     *
     * @return \Illuminate\Support\Collection<int, array{task: Item, due: string}>
     */
    private function dueTasksFor(User $user, Collection $collection): \Illuminate\Support\Collection
    {
        $dueProperty = $collection->systemProperty('due');
        $assigneeProperty = $collection->systemProperty('assignee');

        if (! $dueProperty) {
            return collect();
        }

        $today = now()->toDateString();

        return Item::query()
            ->forHousehold($collection->household_id)
            ->ofType(Item::TYPE_TODO)
            ->visibleTo($user)
            ->where('done', false)
            ->get()
            ->map(fn (Item $task) => ['task' => $task, 'due' => (string) $task->propertyValue($dueProperty), 'assignee' => $assigneeProperty ? $task->propertyValue($assigneeProperty) : null])
            ->filter(fn (array $row) => $row['due'] !== '' && $row['due'] <= $today)
            ->filter(fn (array $row) => $row['assignee'] !== null && $row['assignee'] !== ''
                ? (string) $row['assignee'] === (string) $user->id
                : $row['task']->created_by === $user->id)
            ->sortBy('due')
            ->map(fn (array $row) => ['task' => $row['task'], 'due' => $row['due']])
            ->values();
    }
}
