<?php

namespace App\Notifications;

use App\Models\Item;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * E-mail at a task's due time, for people without a device that gets push notifications.
 */
class TaskDueNow extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public function __construct(public Item $task) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('⏰ '.$this->task->title.' · '.config('app.name'))
            ->greeting(__('Reminder'))
            ->line(__(':title is due now (:time).', ['title' => $this->task->title, 'time' => $this->task->due_time]))
            ->action(__('Open task'), route('tasks.index', ['task' => $this->task->id]))
            ->line(__('You can turn these e-mails off in your profile.'));
    }
}
