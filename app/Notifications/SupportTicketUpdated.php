<?php

namespace App\Notifications;

use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * The support answered a request or marked it as solved.
 */
class SupportTicketUpdated extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public function __construct(public SupportTicket $ticket, public ?SupportMessage $reply = null) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(($this->reply ? __('Answer to your request :number', ['number' => $this->ticket->number()]) : __('Your request :number was solved', ['number' => $this->ticket->number()])).' · '.config('app.name'))
            ->greeting(__('Hello :name!', ['name' => $notifiable->name]))
            ->line('„'.$this->ticket->subject.'“');

        if ($this->reply) {
            $mail->line(Str::limit($this->reply->body, 600));
        } else {
            $mail->line(__('We marked your request as solved. If something is still open, simply answer – the request opens again.'));
        }

        $mail->action(__('Open request'), route('support.show', $this->ticket));

        if ($this->ticket->status !== 'closed' && ($address = $this->ticket->replyAddress())) {
            $mail->replyTo($address)->line(__('You can also simply answer this e-mail – your answer is added to the request.'));
        }

        return $mail;
    }
}
