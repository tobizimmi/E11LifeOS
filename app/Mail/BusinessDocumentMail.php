<?php

namespace App\Mail;

use App\Models\BusinessProfile;
use App\Models\Invoice;
use App\Support\Business\DocumentPdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * An invoice, quote, credit note or dunning letter sent to the customer, with the PDF attached
 * and a link to the customer portal.
 */
class BusinessDocumentMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Invoice $invoice,
        public BusinessProfile $profile,
        public string $mailSubject,
        public string $body,
        public ?int $reminderLevel = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address((string) config('mail.from.address'), $this->profile->company_name),
            replyTo: $this->profile->email ? [new Address($this->profile->email, $this->profile->company_name)] : [],
            subject: $this->mailSubject,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.business-document', with: [
            'portalUrl' => route('documents.public', $this->invoice->publicToken()),
        ]);
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $pdf = $this->reminderLevel
            ? DocumentPdf::reminder($this->invoice, $this->profile, $this->reminderLevel)
            : DocumentPdf::document($this->invoice, $this->profile);

        return [Attachment::fromData(fn () => $pdf, DocumentPdf::filename($this->invoice, $this->reminderLevel))->withMime('application/pdf')];
    }
}
