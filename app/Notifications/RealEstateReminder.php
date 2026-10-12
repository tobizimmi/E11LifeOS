<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Upcoming invoice due dates, subsidy deadlines and trade starts of the member's properties.
 */
class RealEstateReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    /**
     * @param  list<array{date: string, kind: string, title: string, url: string, overdue: bool}>  $events
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
            ->subject(__('Dates for your property').' · '.config('app.name'))
            ->greeting(__('Hello :name!', ['name' => $notifiable->name]));

        foreach ($this->events as $event) {
            $date = Carbon::parse($event['date']);
            $message->line(($event['overdue'] ? '⚠ '.__('overdue since :date', ['date' => $date->format('d.m.Y')]) : $date->translatedFormat('D, j. F')).': '.$event['title']);
        }

        return $message->action(__('Open property'), $this->events[0]['url']);
    }
}
