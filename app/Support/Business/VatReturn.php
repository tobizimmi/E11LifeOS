<?php

namespace App\Support\Business;

use App\Models\BookkeepingEntry;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Figures for the VAT advance return (Umsatzsteuer-Voranmeldung) per month or quarter, on a
 * cash basis (Ist-Versteuerung): Kz 81 (net at 19 %), Kz 86 (net at 7 %), Kz 66 (input VAT)
 * and Kz 83 (amount to pay, negative = refund).
 */
class VatReturn
{
    /**
     * @return list<array{label: string, from: Carbon, to: Carbon, kz81: float, kz81_vat: float, kz86: float, kz86_vat: float, other_net: float, other_vat: float, kz66: float, kz83: float}>
     */
    public static function periods(User $user, int $householdId, int $year, string $interval = 'quarter'): array
    {
        $months = $interval === 'month' ? 1 : 3;
        $periods = [];

        for ($start = 1; $start <= 12; $start += $months) {
            $from = Carbon::create($year, $start, 1)->startOfDay();
            $to = $from->copy()->addMonths($months)->subDay()->endOfDay();
            $periods[] = ['label' => $months === 1 ? $from->translatedFormat('F') : 'Q'.intdiv($start + 2, 3), 'from' => $from, 'to' => $to]
                + array_fill_keys(['kz81', 'kz81_vat', 'kz86', 'kz86_vat', 'other_net', 'other_vat', 'kz66'], 0.0);
        }

        $index = fn (Carbon $date) => intdiv($date->month - 1, $months);

        $payments = InvoicePayment::query()
            ->whereYear('paid_on', $year)
            ->whereHas('invoice', fn ($query) => $query->where('user_id', $user->id)->where('household_id', $householdId)->where('type', '!=', Invoice::QUOTE)->where('small_business', false))
            ->with('invoice.items')->get();

        foreach ($payments as $payment) {
            $invoice = $payment->invoice;
            $share = abs($invoice->gross_total) > 0 ? $payment->amount / $invoice->gross_total : 0;

            foreach ($invoice->vatBreakdown() as $row) {
                self::addRevenue($periods[$index($payment->paid_on)], $row['rate'], $row['net'] * $share, $row['vat'] * $share);
            }
        }

        $entries = BookkeepingEntry::where('user_id', $user->id)->where('household_id', $householdId)
            ->whereYear('booked_on', $year)->where('category', '!=', BookkeepingCategories::TAX_PAYMENT)->get();

        foreach ($entries as $entry) {
            $period = &$periods[$index($entry->booked_on)];

            if ($entry->isExpense()) {
                $period['kz66'] += $entry->vat_amount;
            } elseif ($entry->vat_amount > 0 && BookkeepingCategories::line($entry->category) !== 'vat_refunded') {
                $net = $entry->netAmount();
                $rate = $net > 0 ? $entry->vat_amount / $net * 100 : 0;
                self::addRevenue($period, abs($rate - 19) < 1 ? 19.0 : (abs($rate - 7) < 1 ? 7.0 : $rate), $net, $entry->vat_amount);
            }

            unset($period);
        }

        return array_map(function (array $period) {
            foreach (['kz81', 'kz81_vat', 'kz86', 'kz86_vat', 'other_net', 'other_vat', 'kz66'] as $key) {
                $period[$key] = round($period[$key], 2);
            }

            // In the form Kz 81/86 are whole euros; the tax is calculated from them.
            $period['kz83'] = round($period['kz81_vat'] + $period['kz86_vat'] + $period['other_vat'] - $period['kz66'], 2);

            return $period;
        }, $periods);
    }

    /**
     * @param  array<string, mixed>  $period
     */
    private static function addRevenue(array &$period, float $rate, float $net, float $vat): void
    {
        $key = match (true) {
            abs($rate - 19) < 0.01 => 'kz81',
            abs($rate - 7) < 0.01 => 'kz86',
            default => 'other_net',
        };

        $period[$key] += $net;
        $period[$key === 'other_net' ? 'other_vat' : $key.'_vat'] += $vat;
    }
}
