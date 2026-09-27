<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Weekly summary e-mail (see App\Support\WeeklyDigest).
 */
class WeeklyDigestNotification extends Notification
{
    use Queueable;

    /**
     * @param  array{week: string, sections: list<array{icon: string, title: string, lines: list<string>, url: string}>}  $digest
     */
    public function __construct(public array $digest) {}

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
            ->subject(__('Your week').' · '.$this->digest['week'].' · '.config('app.name'))
            ->greeting(__('Hello :name!', ['name' => $notifiable->name]))
            ->line(__('Here is your overview for the coming week.'));

        foreach ($this->digest['sections'] as $section) {
            $message->line('**'.$section['icon'].' '.$section['title'].'**');

            foreach ($section['lines'] as $line) {
                $message->line('• '.$line);
            }
        }

        return $message
            ->action(__('Open :app', ['app' => config('app.name')]), route('dashboard'))
            ->line(__('You can change or switch off the weekly summary in your profile.'));
    }
}
