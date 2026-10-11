<?php

namespace App\Notifications;

use App\Models\Collection;
use App\Models\Item;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Daily e-mail: the recipient's tasks that are due today or overdue, plus date
 * reminders from collections (e.g. a contract's cancellation deadline).
 */
class TaskReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    /**
     * @param  SupportCollection<int, array{task: Item, due: string}>  $tasks
     * @param  SupportCollection<int, array{entry: Item, collection: Collection, property: string, date: string}>  $dates
     */
    public function __construct(public SupportCollection $tasks, public Collection $collection, public ?SupportCollection $dates = null)
    {
        $this->dates ??= collect();
    }

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
            ->subject(($this->tasks->isNotEmpty()
                ? trans_choice(':count task is due|:count tasks are due', $this->tasks->count())
                : trans_choice(':count reminder|:count reminders', $this->dates->count())).' · '.config('app.name'))
            ->greeting(__('Good morning, :name!', ['name' => $notifiable->name]));

        if ($this->tasks->isNotEmpty()) {
            $message->line($overdue > 0
                ? trans_choice(':count task is overdue|:count tasks are overdue', $overdue).' '.__('Here is what is due:')
                : __('Here is what is due today:'));

            foreach ($this->tasks as $row) {
                $message->line('• '.$row['task']->title.($row['due'] < $today ? ' ('.__('due :date', ['date' => Carbon::parse($row['due'])->translatedFormat('j. M')]).')' : ''));
            }
        }

        if ($this->dates->isNotEmpty()) {
            $message->line(__('Coming up:'));

            foreach ($this->dates as $row) {
                $message->line('• '.trim($row['collection']->icon.' '.$row['entry']->title).' – '.$row['property'].': '
                    .($row['date'] === $today ? __('today') : Carbon::parse($row['date'])->translatedFormat('j. F Y')));
            }
        }

        if ($this->tasks->isNotEmpty()) {
            $message->action(__('Open tasks'), route('tasks.index'));
        } else {
            $message->action(__('Open :name', ['name' => $this->dates->first()['collection']->name]), route('collections.show', $this->dates->first()['collection']));
        }

        return $message->line(__('You can turn these e-mails off in your profile.'));
    }
}
