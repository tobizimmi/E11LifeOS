<?php

namespace App\Support\Portfolio;

use App\Models\Portfolio;
use App\Models\PortfolioPosition;
use App\Models\PortfolioTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds the holdings of a portfolio from its transactions with the average cost method:
 * purchases add quantity and cost (incl. fees), sales remove quantity at the average cost,
 * splits multiply the quantity. Positions entered by hand and the labels, notes and stop/target
 * prices of existing positions are kept.
 */
class Positions
{
    public static function recalculate(Portfolio $portfolio): void
    {
        $holdings = [];

        $transactions = $portfolio->transactions()->orderBy('executed_on')->orderBy('id')->get();

        foreach ($transactions as $transaction) {
            $symbol = $transaction->symbol;
            $holdings[$symbol] ??= ['quantity' => 0.0, 'cost' => 0.0, 'name' => $transaction->name, 'isin' => $transaction->isin, 'currency' => 'EUR', 'estimated' => false];
            $holding = &$holdings[$symbol];
            $holding['name'] = $transaction->name ?: $holding['name'];
            $holding['isin'] = $transaction->isin ?: $holding['isin'];
            $rate = self::euroRate($transaction);

            if (in_array($transaction->type, ['buy', 'transfer_in'], true) && $transaction->cost_estimated) {
                $holding['estimated'] = true;
            }

            if ($rate === null) {
                $holding['currency'] = $transaction->currency;
                $rate = 1.0;
            }

            switch ($transaction->type) {
                case 'buy':
                case 'transfer_in':
                    $holding['cost'] += ($transaction->quantity * $transaction->price + $transaction->fees) / $rate;
                    $holding['quantity'] += $transaction->quantity;
                    break;
                case 'sell':
                case 'transfer_out':
                    if ($holding['quantity'] > 0) {
                        $sold = min($transaction->quantity, $holding['quantity']);
                        $holding['cost'] -= $holding['cost'] / $holding['quantity'] * $sold;
                        $holding['quantity'] -= $sold;
                    }
                    break;
                case 'split':
                    // The price field holds the split factor (e.g. 4 for a 4:1 split).
                    if ($transaction->price > 0) {
                        $holding['quantity'] *= $transaction->price;
                    }
                    break;
            }

            unset($holding);
        }

        DB::transaction(function () use ($portfolio, $holdings) {
            $existing = $portfolio->positions()->get()->keyBy('symbol');

            foreach ($holdings as $symbol => $holding) {
                $position = $existing->pull($symbol);

                if ($holding['quantity'] <= 0.000001) {
                    $position?->delete();

                    continue;
                }

                $position ??= new PortfolioPosition(['portfolio_id' => $portfolio->id, 'symbol' => $symbol]);
                $position->fill([
                    'name' => $holding['name'] ?: $position->name ?: $symbol,
                    'isin' => $holding['isin'] ?: $position->isin,
                    'quantity' => round($holding['quantity'], 6),
                    'avg_buy_price' => round($holding['cost'] / $holding['quantity'], 4),
                    'cost_basis_estimated' => $holding['estimated'],
                    'currency' => $holding['currency'],
                    'manual' => false,
                ])->save();
            }

            // Positions without transactions survive only if they were entered by hand.
            $existing->reject(fn (PortfolioPosition $position) => $position->manual)->each->delete();
        });
    }

    /**
     * Divisor from the transaction currency to euros, or null when the rate is unknown (the
     * position is then valued in its own currency).
     */
    private static function euroRate(PortfolioTransaction $transaction): ?float
    {
        if ($transaction->currency === 'EUR' || $transaction->currency === '') {
            return 1.0;
        }

        return $transaction->exchange_rate > 0 && abs($transaction->exchange_rate - 1) > 0.000001 ? $transaction->exchange_rate : null;
    }
}
