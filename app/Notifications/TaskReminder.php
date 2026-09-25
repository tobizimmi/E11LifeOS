<?php

namespace App\Notifications;

use App\Models\Collection;
use App\Models\Item;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Daily e-mail: the recipient's tasks that are due today or overdue.
 */
class TaskReminder extends Notification
{
    use Queueable;

    /**
     * @param  SupportCollection<int, array{task: Item, due: string}>  $tasks
     */
    public function __construct(public SupportCollection $tasks, public Collection $collection) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $today = now()->toDateString();
        $overdue = $this->tasks->filter(fn (array $row) => $row['due'] < $today)->count();

        $message = (new MailMessage)
            ->subject(trans_choice(':count task is due|:count tasks are due', $this->tasks->count()).' · '.config('app.name'))
            ->greeting(__('Good morning, :name!', ['name' => $notifiable->name]))
            ->line($overdue > 0
                ? trans_choice(':count task is overdue|:count tasks are overdue', $overdue).' '.__('Here is what is due:')
                : __('Here is what is due today:'));

        foreach ($this->tasks as $row) {
            $message->line('• '.$row['task']->title.($row['due'] < $today ? ' ('.__('due :date', ['date' => Carbon::parse($row['due'])->translatedFormat('j. M')]).')' : ''));
        }

        return $message
            ->action(__('Open tasks'), route('collections.show', $this->collection))
            ->line(__('You can turn these e-mails off in your profile.'));
    }
}
