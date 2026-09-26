<?php

namespace App\Support\Portfolio;

use App\Models\PortfolioTransaction;
use App\Models\SavingsPlan;
use Illuminate\Support\Carbon;

/**
 * Executes savings plans: a purchase for the plan amount at the current price, then the next
 * date. Brokers usually buy fractional shares, so the quantity is amount ÷ price.
 */
class SavingsPlans
{
    public function __construct(private MarketData $market) {}

    public static function nextDate(SavingsPlan $plan, Carbon $after): Carbon
    {
        return match ($plan->frequency) {
            'weekly' => $after->copy()->addWeek(),
            'quarterly' => $after->copy()->startOfMonth()->addMonthsNoOverflow(3)->setDay(min($plan->day_of_month, 28)),
            default => $after->copy()->startOfMonth()->addMonthNoOverflow()->setDay(min($plan->day_of_month, 28)),
        };
    }

    /**
     * First execution date on or after today for a new plan.
     */
    public static function firstDate(string $frequency, int $dayOfMonth): Carbon
    {
        if ($frequency === 'weekly') {
            return today();
        }

        $date = today()->setDay(min(max(1, $dayOfMonth), 28));

        return $date->lt(today()) ? $date->addMonthNoOverflow() : $date;
    }

    /**
     * @return PortfolioTransaction|null null when no price is known
     */
    public function execute(SavingsPlan $plan, ?Carbon $on = null): ?PortfolioTransaction
    {
        $on ??= $plan->next_date;
        $security = $this->market->quote($plan->symbol);
        $price = $security?->last_price ? $this->market->toEuro($security->last_price, $security->currency) : null;

        if (! $price) {
            return null;
        }

        $transaction = $plan->portfolio->transactions()->create([
            'symbol' => $plan->symbol,
            'name' => $plan->name ?: $security->name,
            'isin' => $security->isin,
            'type' => 'buy',
            'quantity' => round($plan->amount / $price, 6),
            'price' => round($price, 4),
            'fees' => 0,
            'currency' => 'EUR',
            'executed_on' => $on->toDateString(),
            'notes' => __('Savings plan'),
            'import_source' => 'savings_plan',
        ]);

        $plan->update(['last_executed_on' => $on, 'next_date' => self::nextDate($plan, $on)]);
        Positions::recalculate($plan->portfolio);

        return $transaction;
    }
}
