<?php

namespace App\Console\Commands;

use App\Models\Item;
use App\Models\User;
use App\Notifications\TaskDueNow;
use App\Support\WebPush;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('reminders:timed')]
#[Description('Remind people of tasks whose due time has come (push, or e-mail without a push device); runs every minute')]
class SendTimedTaskReminders extends Command
{
    /**
     * Older due times are not reminded any more (e.g. after the scheduler was down for a day).
     */
    private const MAX_DELAY_HOURS = 12;

    public function handle(WebPush $webPush): int
    {
        $reminded = 0;

        Item::query()
            ->where('type', Item::TYPE_TODO)
            ->where('done', false)
            ->whereNotNull('due_time')
            ->whereNull('reminded_at')
            ->with('collection.properties')
            ->each(function (Item $task) use ($webPush, &$reminded) {
                $dueAt = $task->dueAt();

                if (! $dueAt || $dueAt->isFuture()) {
                    return;
                }

                // Mark first, so a slow push service can never cause a second reminder.
                $task->forceFill(['reminded_at' => now()])->saveQuietly();

                if ($dueAt->lt(now()->subHours(self::MAX_DELAY_HOURS)) || ! ($recipient = $this->recipient($task))) {
                    return;
                }

                $pushed = $webPush->send($recipient, '⏰ '.$task->title, __('Due now (:time)', ['time' => $task->due_time]), route('tasks.index', ['task' => $task->id]));

                if ($pushed === 0 && $recipient->reminder_emails) {
                    $recipient->notify(new TaskDueNow($task));
                }

                $reminded++;
            });

        $this->info("Timed reminders: {$reminded}");

        return self::SUCCESS;
    }

    /**
     * The assignee, otherwise the creator; private tasks only ever reach their creator.
     */
    private function recipient(Item $task): ?User
    {
        $assigneeProperty = $task->collection?->systemProperty('assignee');
        $assigneeId = $assigneeProperty ? $task->propertyValue($assigneeProperty) : null;
        $userId = ! $task->isPrivate() && $assigneeId ? (int) $assigneeId : $task->created_by;

        $user = $userId ? User::find($userId) : null;

        return $user && $user->households()->whereKey($task->household_id)->exists() ? $user : null;
    }
}
