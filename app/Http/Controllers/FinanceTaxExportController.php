<?php

namespace App\Http\Controllers;

use App\Support\Finance\TaxCategories;
use App\Support\Finance\TaxYear;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The member's tax-relevant bookings of a year as CSV (Excel-friendly, semicolons) for the
 * tax return or the tax adviser.
 */
class FinanceTaxExportController extends Controller
{
    public function __invoke(Request $request, int $year): StreamedResponse
    {
        abort_unless($year >= 2000 && $year <= 2100, 404);

        $user = $request->user();
        $bookings = TaxYear::bookings($user, (int) $user->currentHousehold()?->id, $year);
        $categories = TaxCategories::all();

        return response()->streamDownload(function () use ($bookings, $categories) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [__('Date'), __('Tax category'), __('Form'), __('Payee'), __('Purpose'), __('Amount'), __('Tax-relevant amount')], ';');

            foreach ($bookings as $transaction) {
                fputcsv($out, [
                    $transaction->booked_on->format('d.m.Y'),
                    $categories[$transaction->tax_category]['label'] ?? $transaction->tax_category,
                    $categories[$transaction->tax_category]['form'] ?? '',
                    $transaction->counterparty,
                    $transaction->purpose,
                    number_format($transaction->amount, 2, ',', ''),
                    number_format(TaxYear::relevantAmount($transaction), 2, ',', ''),
                ], ';');
            }

            fclose($out);
        }, 'steuer-'.$year.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
