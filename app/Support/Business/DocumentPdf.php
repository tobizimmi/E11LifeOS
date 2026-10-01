<?php

namespace App\Support\Business;

use App\Models\BusinessProfile;
use App\Models\Invoice;
use App\Support\PdfTheme;
use Illuminate\Support\Str;

/**
 * Renders invoices, quotes, credit notes and dunning letters as PDF (no remote resources).
 */
class DocumentPdf
{
    public static function document(Invoice $invoice, BusinessProfile $profile): string
    {
        return self::render(view('business.invoice-pdf', [
            'invoice' => $invoice->loadMissing('items', 'customer', 'related'),
            'profile' => $profile,
        ])->render());
    }

    /**
     * Payment reminder (level 1) or dunning letter (2, 3) for an overdue invoice.
     */
    public static function reminder(Invoice $invoice, BusinessProfile $profile, int $level): string
    {
        return self::render(view('business.reminder-pdf', [
            'invoice' => $invoice,
            'profile' => $profile,
            'level' => max(1, min(3, $level)),
            'fee' => $profile->reminderFee($level),
            'deadline' => today()->addDays(max(1, $profile->reminder_days)),
        ])->render());
    }

    public static function filename(Invoice $invoice, ?int $reminderLevel = null): string
    {
        $kind = match (true) {
            $reminderLevel !== null => $reminderLevel === 1 ? 'zahlungserinnerung' : 'mahnung-'.($reminderLevel - 1),
            $invoice->type === Invoice::QUOTE => 'angebot',
            $invoice->type === Invoice::CREDIT_NOTE => 'gutschrift',
            default => 'rechnung',
        };

        return ($invoice->number ? $kind.'-'.Str::slug($invoice->number) : $kind.'-entwurf-'.$invoice->id).'.pdf';
    }

    private static function render(string $html): string
    {
        $pdf = PdfTheme::render($html, footer: false);

        return $pdf;
    }
}
