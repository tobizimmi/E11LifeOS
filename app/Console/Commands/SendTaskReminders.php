<?php

namespace App\Console\Commands;

use App\Models\Collection;
use App\Models\Item;
use App\Models\User;
use App\Notifications\TaskReminder;
use App\Support\WebPush;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('reminders:send')]
#[Description('E-mail and/or push everyone who opted in their due/overdue tasks and date reminders from collections (runs daily via the scheduler)')]
class SendTaskReminders extends Command
{
    public function handle(WebPush $webPush): int
    {
        $sent = 0;
        $pushed = 0;

        User::query()
            ->where(fn ($query) => $query->where('reminder_emails', true)->orWhereHas('pushSubscriptions'))
            ->each(function (User $user) use (&$sent, &$pushed, $webPush) {
                $household = $user->currentHousehold();

                if (! $household) {
                    return;
                }

                $collection = Collection::tasksFor($household->id);
                $tasks = $this->dueTasksFor($user, $collection);
                $dates = $this->dateRemindersFor($user, $household->id);

                if ($tasks->isEmpty() && $dates->isEmpty()) {
                    return;
                }

                if ($user->reminder_emails) {
                    $user->notify(new TaskReminder($tasks, $collection, $dates));
                    $sent++;
                }

                if ($webPush->send($user, ...$this->pushText($tasks, $dates, $collection)) > 0) {
                    $pushed++;
                }
            });

        $this->info("Reminders sent: {$sent}, pushed: {$pushed}");

        return self::SUCCESS;
    }

    /**
     * Title, body and target URL of the push notification.
     *
     * @param  \Illuminate\Support\Collection<int, array{task: Item, due: string}>  $tasks
     * @param  \Illuminate\Support\Collection<int, array{entry: Item, collection: Collection, property: string, date: string}>  $dates
     * @return array{0: string, 1: string, 2: string}
     */
    private function pushText(\Illuminate\Support\Collection $tasks, \Illuminate\Support\Collection $dates, Collection $collection): array
    {
        $title = $tasks->isNotEmpty()
            ? trans_choice(':count task is due|:count tasks are due', $tasks->count())
            : trans_choice(':count reminder|:count reminders', $dates->count());

        $lines = $tasks->map(fn (array $row) => '• '.$row['task']->title)
            ->concat($dates->map(fn (array $row) => '• '.$row['entry']->title.' – '.$row['property'].': '
                .($row['date'] === now()->toDateString() ? __('today') : Carbon::parse($row['date'])->translatedFormat('j. M'))));

        $body = $lines->take(4)->implode("\n").($lines->count() > 4 ? "\n".__('and :count more', ['count' => $lines->count() - 4]) : '');
        $url = $tasks->isNotEmpty() ? route('tasks.index') : route('collections.show', $dates->first()['collection']);

        return [$title, $body, $url];
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
            ->orderBy('id')
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
