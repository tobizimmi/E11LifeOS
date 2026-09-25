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
#[Description('E-mail everyone who opted in their tasks that are due today or overdue (runs daily via the scheduler)')]
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

            if ($tasks->isNotEmpty()) {
                $user->notify(new TaskReminder($tasks, $collection));
                $sent++;
            }
        });

        $this->info("Reminders sent: {$sent}");

        return self::SUCCESS;
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
