<?php

namespace App\Support\Finance;

use App\Models\Transaction;
use App\Support\Paperless\PaperlessClient;
use App\Support\PdfTheme;

/**
 * Bank bookings and their documents in paperless-ngx: mark a linked invoice as paid (tag and note
 * with the booking) or, without an invoice, send a receipt of the booking itself to paperless.
 */
class TransactionDocuments
{
    public static function paidTag(): string
    {
        return __('Paid');
    }

    /**
     * "Paid on 03.10.2026: 49,90 € to Stadtwerke (purpose …)".
     */
    public static function paidNote(Transaction $transaction): string
    {
        return trim(__('Paid on :date: :amount to :payee', [
            'date' => $transaction->booked_on->format('d.m.Y'),
            'amount' => number_format(abs($transaction->amount), 2, ',', '.').' €',
            'payee' => $transaction->counterparty ?: '—',
        ]).($transaction->purpose ? ' ('.mb_substr($transaction->purpose, 0, 140).')' : '').' – E11LifeOS');
    }

    /**
     * Tags the document "Paid" and adds a note with the booking.
     */
    public static function markPaid(PaperlessClient $client, int $documentId, Transaction $transaction): void
    {
        $document = $client->document($documentId);
        $client->update($documentId, ['tags' => array_values(array_unique([...$document['tags'], $client->ensureTag(self::paidTag())]))]);
        $client->addNote($documentId, self::paidNote($transaction));
    }

    public static function title(Transaction $transaction): string
    {
        return mb_substr(__('Booking :date :payee', ['date' => $transaction->booked_on->format('d.m.Y'), 'payee' => $transaction->counterparty ?: number_format($transaction->amount, 2, ',', '.').' €']), 0, 128);
    }

    /**
     * A one-page receipt of the booking as PDF.
     */
    public static function receiptPdf(Transaction $transaction): string
    {
        return PdfTheme::render(view('finance.booking-receipt', ['transaction' => $transaction, 'category' => FinanceCategories::all()[$transaction->category] ?? null])->render(), $transaction->household_id);
    }

    /**
     * Sends the receipt to paperless (tagged "Paid" for expenses); returns the task id.
     */
    public static function sendReceipt(PaperlessClient $client, Transaction $transaction): string
    {
        $tags = $transaction->isExpense() ? [$client->ensureTag(self::paidTag())] : [];

        return $client->uploadContents(self::receiptPdf($transaction), 'buchung-'.$transaction->booked_on->format('Y-m-d').'-'.$transaction->id.'.pdf', self::title($transaction), $tags, $transaction->booked_on->toDateString());
    }
}
