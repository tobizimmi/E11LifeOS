<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a trusted person that they may open someone's emergency folder in an emergency, with their personal link.
 */
class TrustedPersonInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public function __construct(public string $ownerName, public string $personName, public string $url, public int $waitDays) {}

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
            ->subject(__(':name has named you as a trusted person', ['name' => $this->ownerName]))
            ->greeting(__('Hello :name!', ['name' => $this->personName]))
            ->line(__(':name keeps an emergency folder with important documents, contacts and wishes and has named you as a trusted person.', ['name' => $this->ownerName]))
            ->line(__('If something happens, open the link below and request access. :name is informed and can decline; otherwise the folder opens for you after :days days.', ['name' => $this->ownerName, 'days' => $this->waitDays]))
            ->action(__('Open emergency access'), $this->url)
            ->line(__('Please keep this e-mail – the link is personal and cannot be shown again.'));
    }
}
