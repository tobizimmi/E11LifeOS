<?php

namespace App\Support\Business;

use App\Models\BookkeepingEntry;
use App\Models\BusinessProfile;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Yearly profit calculation (Einnahmenüberschussrechnung) of a small business, grouped like the
 * Anlage EÜR. Cash basis (Zuflussprinzip): invoices count in the year they were paid.
 */
class EuerReport
{
    /**
     * Kleinunternehmer limits since 2025 (§ 19 UStG): previous year / current year.
     */
    public const LIMIT_PREVIOUS_YEAR = 25000;

    public const LIMIT_CURRENT_YEAR = 100000;

    /**
     * @return array<string, mixed>
     */
    public static function build(User $user, int $householdId, int $year, ?BusinessProfile $profile): array
    {
        $smallBusiness = $profile?->small_business ?? true;
        $lines = array_fill_keys(array_keys(BookkeepingCategories::euerLines()), 0.0);
        $monthly = array_fill(1, 12, ['income' => 0.0, 'expenses' => 0.0]);
        $rows = [];
        $nonDeductible = 0.0;
        $missingReceipts = 0;

        $invoices = Invoice::where('user_id', $user->id)->where('household_id', $householdId)
            ->where('status', Invoice::PAID)->whereYear('paid_on', $year)->orderBy('paid_on')->get();

        foreach ($invoices as $invoice) {
            if ($invoice->small_business) {
                $lines['small_business_revenue'] += $invoice->gross_total;
            } else {
                $lines['taxable_revenue'] += $invoice->net_total;
                $lines['vat_received'] += $invoice->vat_total;
            }

            $monthly[$invoice->paid_on->month]['income'] += $invoice->gross_total;
            $rows[] = [
                'date' => $invoice->paid_on, 'kind' => BookkeepingEntry::INCOME, 'reference' => $invoice->number,
                'description' => $invoice->subject ?: __('Invoice :number', ['number' => $invoice->number]),
                'counterparty' => strtok($invoice->recipient, "\n") ?: '', 'category' => __('Invoice'),
                'line' => $invoice->small_business ? 'small_business_revenue' : 'taxable_revenue',
                'net' => $invoice->net_total, 'vat' => $invoice->vat_total, 'gross' => $invoice->gross_total, 'receipt' => true,
            ];
        }

        $entries = BookkeepingEntry::where('user_id', $user->id)->where('household_id', $householdId)
            ->whereYear('booked_on', $year)->orderBy('booked_on')->orderBy('id')->get();

        foreach ($entries as $entry) {
            $net = $entry->netAmount();
            $line = BookkeepingCategories::line($entry->category);

            if ($entry->isExpense()) {
                if ($entry->category === BookkeepingCategories::TAX_PAYMENT) {
                    $lines['vat_payments'] += $entry->amount;
                } else {
                    // Without the small business rule the contained VAT is claimed separately as input tax.
                    $expense = $smallBusiness ? $entry->amount : $net;

                    if ($entry->category === BookkeepingCategories::ENTERTAINMENT) {
                        $nonDeductible += $expense * (1 - BookkeepingCategories::ENTERTAINMENT_DEDUCTIBLE);
                        $expense *= BookkeepingCategories::ENTERTAINMENT_DEDUCTIBLE;
                    }

                    $lines[$line] += $expense;

                    if (! $smallBusiness) {
                        $lines['vat_paid'] += $entry->vat_amount;
                    }
                }

                $monthly[$entry->booked_on->month]['expenses'] += $entry->amount;
                $missingReceipts += $entry->receipt_path ? 0 : 1;
            } else {
                if ($line === 'vat_refunded') {
                    $lines['vat_refunded'] += $entry->amount;
                } elseif ($smallBusiness) {
                    $line = 'small_business_revenue';
                    $lines[$line] += $entry->amount;
                } elseif ($entry->vat_amount > 0) {
                    $line = 'taxable_revenue';
                    $lines[$line] += $net;
                    $lines['vat_received'] += $entry->vat_amount;
                } else {
                    $line = 'tax_free_revenue';
                    $lines[$line] += $entry->amount;
                }

                $monthly[$entry->booked_on->month]['income'] += $entry->amount;
            }

            $rows[] = [
                'date' => $entry->booked_on, 'kind' => $entry->kind, 'reference' => '#'.$entry->id,
                'description' => $entry->description, 'counterparty' => (string) $entry->counterparty,
                'category' => BookkeepingCategories::label($entry->category),
                'line' => $entry->category === BookkeepingCategories::TAX_PAYMENT ? 'vat_payments' : $line,
                'net' => $smallBusiness ? $entry->amount : $net, 'vat' => $smallBusiness ? 0.0 : $entry->vat_amount, 'gross' => $entry->amount,
                'receipt' => (bool) $entry->receipt_path,
            ];
        }

        $lines = array_map(fn (float $amount) => round($amount, 2), $lines);
        $definitions = BookkeepingCategories::euerLines();
        $incomeTotal = round(array_sum(array_filter($lines, fn (string $key) => $definitions[$key]['section'] === 'income', ARRAY_FILTER_USE_KEY)), 2);
        $expenseTotal = round(array_sum(array_filter($lines, fn (string $key) => $definitions[$key]['section'] === 'expense', ARRAY_FILTER_USE_KEY)), 2);
        $revenue = round($lines['small_business_revenue'] + $lines['taxable_revenue'] + $lines['tax_free_revenue'], 2);

        usort($rows, fn (array $a, array $b) => $a['date'] <=> $b['date']);

        $open = Invoice::where('user_id', $user->id)->where('household_id', $householdId)
            ->where('status', Invoice::OPEN)->whereYear('issued_on', '<=', $year);

        return [
            'year' => $year,
            'smallBusiness' => $smallBusiness,
            'lines' => $lines,
            'incomeTotal' => $incomeTotal,
            'expenseTotal' => $expenseTotal,
            'profit' => round($incomeTotal - $expenseTotal, 2),
            'revenue' => $revenue,
            'limitStatus' => match (true) {
                ! $smallBusiness => null,
                $revenue > self::LIMIT_CURRENT_YEAR => 'exceeded',
                $revenue > self::LIMIT_PREVIOUS_YEAR => 'next_year',
                default => 'ok',
            },
            'vat' => [
                'received' => $lines['vat_received'],
                'paid' => $lines['vat_paid'],
                'payments' => $lines['vat_payments'],
                'refunded' => $lines['vat_refunded'],
                'balance' => round($lines['vat_received'] - $lines['vat_paid'] - $lines['vat_payments'] + $lines['vat_refunded'], 2),
            ],
            'monthly' => collect($monthly)->map(fn (array $month, int $number) => [
                'label' => Carbon::create($year, $number, 1)->translatedFormat('M'),
                'income' => round($month['income'], 2),
                'expenses' => round($month['expenses'], 2),
            ])->values()->all(),
            'rows' => $rows,
            'nonDeductible' => round($nonDeductible, 2),
            'missingReceipts' => $missingReceipts,
            'openInvoiceCount' => (clone $open)->count(),
            'openInvoiceTotal' => round((float) (clone $open)->sum('gross_total'), 2),
        ];
    }

    /**
     * Semicolon CSV (Excel, German number format) of all bookings, e.g. for a tax advisor.
     */
    public static function csv(array $report): string
    {
        $lines = BookkeepingCategories::euerLines();
        $number = fn (float $value) => number_format($value, 2, ',', '');
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [__('Date'), __('Type'), __('Reference'), __('Description'), __('Counterparty'), __('Category'), __('EÜR position'), __('Net'), __('VAT'), __('Gross'), __('Receipt')], ';');

        foreach ($report['rows'] as $row) {
            fputcsv($out, [
                $row['date']->format('d.m.Y'),
                $row['kind'] === BookkeepingEntry::INCOME ? __('Income') : __('Expense'),
                $row['reference'], $row['description'], $row['counterparty'], $row['category'],
                $lines[$row['line']]['label'] ?? $row['line'],
                $number($row['net']), $number($row['vat']), $number($row['gross']),
                $row['receipt'] ? __('yes') : __('no'),
            ], ';');
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }
}
