<?php

namespace App\Support\Portfolio;

use App\Models\PortfolioTransaction;
use Illuminate\Support\Collection;

/**
 * Realised gains of a year by the FIFO method (as German banks calculate them) plus dividends,
 * and an estimate of the capital gains tax (Abgeltungsteuer 25 % + Soli 5.5 %, optionally
 * church tax) after the tax-free allowance. Losses from shares are shown separately, because
 * they can only be offset against share gains (Aktienverlusttopf).
 */
class TaxReport
{
    public const CAPITAL_GAINS_TAX = 0.25;

    public const SOLIDARITY_SURCHARGE = 0.055;

    /**
     * @param  Collection<int, PortfolioTransaction>  $transactions  all transactions of the portfolios (any year)
     * @return array{events: list<array<string, mixed>>, share_gains: float, share_losses: float, other_gains: float, other_losses: float, dividends: float, taxes_paid: float, net: float, allowance: float, allowance_used: float, taxable: float, tax: float, church_tax_rate: float}
     */
    public static function build(Collection $transactions, int $year, float $allowance, float $churchTaxRate = 0.0, array $assetTypes = []): array
    {
        $lots = [];
        $events = [];

        foreach ($transactions->sortBy([['executed_on', 'asc'], ['id', 'asc']]) as $transaction) {
            $key = $transaction->portfolio_id.'|'.$transaction->symbol;
            $lots[$key] ??= [];
            $rate = $transaction->currency !== 'EUR' && $transaction->exchange_rate > 0 ? $transaction->exchange_rate : 1.0;
            $inYear = $transaction->executed_on->year === $year;

            switch ($transaction->type) {
                case 'buy':
                case 'transfer_in':
                    if ($transaction->quantity > 0) {
                        $lots[$key][] = ['quantity' => $transaction->quantity, 'unit_cost' => ($transaction->quantity * $transaction->price + $transaction->fees) / $rate / $transaction->quantity];
                    }
                    break;

                case 'split':
                    $factor = $transaction->price > 0 ? $transaction->price : 1;

                    foreach ($lots[$key] as &$lot) {
                        $lot['quantity'] *= $factor;
                        $lot['unit_cost'] /= $factor;
                    }

                    unset($lot);
                    break;

                case 'sell':
                case 'transfer_out':
                    $remaining = $transaction->quantity;
                    $cost = 0.0;

                    while ($remaining > 0.000001 && $lots[$key] !== []) {
                        $take = min($remaining, $lots[$key][0]['quantity']);
                        $cost += $take * $lots[$key][0]['unit_cost'];
                        $lots[$key][0]['quantity'] -= $take;
                        $remaining -= $take;

                        if ($lots[$key][0]['quantity'] <= 0.000001) {
                            array_shift($lots[$key]);
                        }
                    }

                    // Transfers out move the lots away without a taxable sale.
                    if ($inYear && $transaction->type === 'sell') {
                        $proceeds = ($transaction->quantity * $transaction->price - $transaction->fees) / $rate;
                        $events[] = [
                            'date' => $transaction->executed_on,
                            'symbol' => $transaction->symbol,
                            'name' => $transaction->name ?: $transaction->symbol,
                            'kind' => 'sale',
                            'is_share' => ($assetTypes[$transaction->symbol] ?? 'stock') === 'stock',
                            'quantity' => $transaction->quantity,
                            'proceeds' => round($proceeds, 2),
                            'cost' => round($cost, 2),
                            'gain' => round($proceeds - $cost, 2),
                            'taxes_paid' => round($transaction->taxes / $rate, 2),
                        ];
                    }
                    break;

                case 'dividend':
                    if ($inYear) {
                        $gross = ($transaction->quantity > 0 ? $transaction->quantity * $transaction->price : $transaction->price) / $rate;
                        $events[] = [
                            'date' => $transaction->executed_on,
                            'symbol' => $transaction->symbol,
                            'name' => $transaction->name ?: $transaction->symbol,
                            'kind' => 'dividend',
                            'is_share' => false,
                            'quantity' => $transaction->quantity,
                            'proceeds' => round($gross, 2),
                            'cost' => 0.0,
                            'gain' => round($gross - $transaction->fees / $rate, 2),
                            'taxes_paid' => round($transaction->taxes / $rate, 2),
                        ];
                    }
                    break;
            }
        }

        $sales = collect($events)->where('kind', 'sale');
        $shareGains = round($sales->where('is_share', true)->where('gain', '>', 0)->sum('gain'), 2);
        $shareLosses = round($sales->where('is_share', true)->where('gain', '<', 0)->sum('gain'), 2);
        $otherGains = round($sales->where('is_share', false)->where('gain', '>', 0)->sum('gain'), 2);
        $otherLosses = round($sales->where('is_share', false)->where('gain', '<', 0)->sum('gain'), 2);
        $dividends = round(collect($events)->where('kind', 'dividend')->sum('gain'), 2);

        // Share losses only offset share gains; other losses offset everything.
        $net = round(max(0, $shareGains + $shareLosses) + $otherGains + $otherLosses + $dividends, 2);
        $allowanceUsed = round(min(max(0, $net), $allowance), 2);
        $taxable = round(max(0, $net - $allowance), 2);
        // With church tax the capital gains tax is reduced: KapESt = 25 % / (1 + 0.25 × church rate).
        $baseRate = self::CAPITAL_GAINS_TAX / (1 + self::CAPITAL_GAINS_TAX * $churchTaxRate);
        $tax = round($taxable * $baseRate * (1 + self::SOLIDARITY_SURCHARGE + $churchTaxRate), 2);

        return [
            'events' => collect($events)->sortBy('date')->values()->all(),
            'share_gains' => $shareGains,
            'share_losses' => $shareLosses,
            'other_gains' => $otherGains,
            'other_losses' => $otherLosses,
            'dividends' => $dividends,
            'taxes_paid' => round(collect($events)->sum('taxes_paid'), 2),
            'net' => $net,
            'allowance' => $allowance,
            'allowance_used' => $allowanceUsed,
            'taxable' => $taxable,
            'tax' => $tax,
            'church_tax_rate' => $churchTaxRate,
        ];
    }
}
