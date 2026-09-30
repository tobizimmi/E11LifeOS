<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Daily morning briefing e-mail (see App\Support\MorningBriefing).
 */
class MorningBriefingNotification extends Notification
{
    use Queueable;

    /**
     * @param  array{date: string, weather: ?string, sections: list<array{icon: string, title: string, lines: list<string>, url: string}>}  $briefing
     */
    public function __construct(public array $briefing) {}

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
            ->subject(__('Good morning').' · '.$this->briefing['date'].' · '.config('app.name'))
            ->greeting(__('Good morning, :name!', ['name' => $notifiable->name]));

        if ($this->briefing['weather']) {
            $message->line($this->briefing['weather']);
        }

        foreach ($this->briefing['sections'] as $section) {
            $message->line('**'.$section['icon'].' '.$section['title'].'**');

            foreach ($section['lines'] as $line) {
                $message->line('• '.$line);
            }
        }

        if ($this->briefing['sections'] === []) {
            $message->line(__('Nothing due today.'));
        }

        return $message
            ->action(__('Open :app', ['app' => config('app.name')]), route('dashboard'))
            ->line(__('You can change or switch off the morning briefing in your profile.'));
    }
}
