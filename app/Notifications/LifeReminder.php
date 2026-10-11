<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Upcoming dates of the life areas: expiring documents, inspections, maintenance, check-ups, birthdays.
 */
class LifeReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    /**
     * @param  list<array{date: string, title: string, url: string, kind: string, remind_days: int}>  $events
     */
    public function __construct(public array $events) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject(__('Upcoming dates').' · '.config('app.name'))
            ->greeting(__('Hello :name!', ['name' => $notifiable->name]));

        foreach ($this->events as $event) {
            $message->line(Carbon::parse($event['date'])->translatedFormat('D, j. F Y').': '.$event['title']);
        }

        return $message->action(__('Show dates'), $this->events[0]['url']);
    }
}
