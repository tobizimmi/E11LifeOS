<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * E-mail about a security-relevant event on the account (new device, repeated failed sign-ins).
 * Always sent, independent of the reminder settings.
 */
class SecurityAlert extends Notification
{
    use Queueable;

    /**
     * @param  list<string>  $lines
     */
    public function __construct(public string $subject, public array $lines) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)->subject('🔐 '.$this->subject.' · '.config('app.name'))->greeting($this->subject);

        foreach ($this->lines as $line) {
            $message->line($line);
        }

        return $message
            ->action(__('Check security log'), route('profile').'#security')
            ->line(__('If this was not you, change your password right away and sign out all other devices in your profile.'));
    }
}
