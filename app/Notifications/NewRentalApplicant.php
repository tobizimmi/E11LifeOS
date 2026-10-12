<?php

namespace App\Notifications;

use App\Models\RealEstateApplicant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A new application with self-disclosure arrived through the public form.
 */
class NewRentalApplicant extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public function __construct(public RealEstateApplicant $applicant) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $realEstate = $this->applicant->realEstate;

        return (new MailMessage)
            ->subject(__('New application for :property', ['property' => $realEstate->name]).' · '.config('app.name'))
            ->greeting(__('Hello :name!', ['name' => $notifiable->name]))
            ->line(__(':applicant has applied for :property and filled in the self-disclosure.', ['applicant' => $this->applicant->name, 'property' => $realEstate->name]))
            ->action(__('View applicants'), route('real-estate.show', ['realEstate' => $realEstate->id, 'tab' => 'rental', 'rental' => 'applicants']));
    }
}
