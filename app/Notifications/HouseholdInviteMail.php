<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sends a household invite link to someone who does not have an account yet (on-demand mail).
 */
class HouseholdInviteMail extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public function __construct(public string $url, public ?string $householdName = null) {}

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
            ->subject(__('Your invitation to :app', ['app' => config('app.name')]))
            ->greeting(__('Hello!'));

        $message = $this->householdName !== null
            ? $message->line(__('You have been invited to join the household “:name”.', ['name' => $this->householdName]))
            : $message->line(__('You have been invited to :app.', ['app' => config('app.name')]));

        return $message
            ->action(__('Accept invitation'), $this->url)
            ->line(__('The link is valid for a limited time. If you did not expect this e-mail, you can ignore it.'));
    }
}
