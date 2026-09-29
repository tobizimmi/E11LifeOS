<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Mails around an emergency access: the owner learns of a request, the trusted person of the
 * decision (granted or declined).
 */
class EmergencyAccessUpdate extends Notification
{
    use Queueable;

    public const REQUESTED = 'requested';

    public const GRANTED = 'granted';

    public const DECLINED = 'declined';

    public function __construct(public string $event, public string $ownerName, public string $personName, public ?string $url = null, public ?string $grantsOn = null) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return match ($this->event) {
            self::REQUESTED => (new MailMessage)
                ->subject('⚠ '.__(':name requested access to your emergency folder', ['name' => $this->personName]))
                ->greeting(__('Hello :name!', ['name' => $this->ownerName]))
                ->line(__(':name has requested access to your emergency folder.', ['name' => $this->personName]))
                ->line(__('If you do nothing, the access opens on :date. If this is not an emergency, decline it now.', ['date' => $this->grantsOn]))
                ->action(__('Decline or allow now'), (string) $this->url),
            self::GRANTED => (new MailMessage)
                ->subject(__('Access to the emergency folder of :name', ['name' => $this->ownerName]))
                ->greeting(__('Hello :name!', ['name' => $this->personName]))
                ->line(__('You can now open the emergency folder of :name with your personal link from the first e-mail.', ['name' => $this->ownerName])),
            default => (new MailMessage)
                ->subject(__('Access to the emergency folder of :name', ['name' => $this->ownerName]))
                ->greeting(__('Hello :name!', ['name' => $this->personName]))
                ->line(__(':name has declined the request – everything is fine.', ['name' => $this->ownerName])),
        };
    }
}
