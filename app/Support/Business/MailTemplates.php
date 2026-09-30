<?php

namespace App\Support\Business;

use App\Models\BusinessProfile;
use App\Models\Invoice;

/**
 * Pre-filled subject and text for e-mails with business documents (editable before sending).
 */
class MailTemplates
{
    /**
     * @return array{subject: string, body: string}
     */
    public static function for(Invoice $invoice, BusinessProfile $profile, ?int $reminderLevel = null): array
    {
        $euro = fn (float $value) => number_format($value, 2, ',', '.').' €';
        $replace = [
            'number' => (string) $invoice->number,
            'company' => $profile->company_name,
            'amount' => $euro(abs($invoice->gross_total)),
            'open' => $euro($invoice->outstanding() + ($reminderLevel ? $profile->reminderFee($reminderLevel) : 0)),
            'date' => ($invoice->due_on ?? today())->format('d.m.Y'),
            'valid' => $invoice->valid_until?->format('d.m.Y') ?? '',
            'deadline' => today()->addDays(max(1, $profile->reminder_days))->format('d.m.Y'),
            'name' => $profile->owner_name ?: $profile->company_name,
        ];

        [$subject, $body] = match (true) {
            $reminderLevel === 1 => [__('Payment reminder for invoice :number', $replace), __("Hello,\n\nthe payment of invoice :number may have been overlooked. The open amount is :open. Please transfer it by :deadline.\n\nIf you have already paid, please disregard this e-mail.\n\nKind regards\n:name", $replace)],
            $reminderLevel !== null => [__('Dunning letter for invoice :number', $replace), __("Hello,\n\nunfortunately invoice :number is still unpaid. Please transfer the open amount of :open by :deadline at the latest. The dunning letter is attached.\n\nKind regards\n:name", $replace)],
            $invoice->isQuote() => [__('Quote :number from :company', $replace), __("Hello,\n\nplease find attached our quote :number amounting to :amount. It is valid until :valid – you can also accept it online with the link below.\n\nKind regards\n:name", $replace)],
            $invoice->isCreditNote() => [__('Credit note :number from :company', $replace), __("Hello,\n\nplease find attached credit note :number amounting to :amount.\n\nKind regards\n:name", $replace)],
            default => [__('Invoice :number from :company', $replace), __("Hello,\n\nplease find attached invoice :number amounting to :amount, payable by :date.\n\nThank you for your order!\n\nKind regards\n:name", $replace)],
        };

        return ['subject' => $subject, 'body' => $body];
    }
}
