<?php

namespace App\Console\Commands;

use App\Models\SavingsPlan;
use App\Support\Portfolio\Notifier;
use App\Support\Portfolio\SavingsPlans;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('portfolio:execute-savings-plans')]
#[Description('Book the purchases of due savings plans at the current price and notify the owners (runs daily via the scheduler)')]
class ExecuteSavingsPlans extends Command
{
    public function handle(SavingsPlans $savingsPlans, Notifier $notifier): int
    {
        $executed = 0;

        SavingsPlan::where('active', true)->whereDate('next_date', '<=', today())->with('portfolio.user')
            ->each(function (SavingsPlan $plan) use ($savingsPlans, $notifier, &$executed) {
                // Catch up missed dates, at most a year's worth.
                for ($i = 0; $i < 53 && $plan->next_date->lte(today()); $i++) {
                    $transaction = $savingsPlans->execute($plan);

                    if (! $transaction) {
                        return;
                    }

                    $executed++;
                    $notifier->send(
                        $plan->portfolio->user,
                        '💶 '.__('Savings plan executed'),
                        __(':amount in :name (:quantity shares at :price)', [
                            'amount' => number_format($plan->amount, 2, ',', '.').' €',
                            'name' => $transaction->name,
                            'quantity' => rtrim(rtrim(number_format($transaction->quantity, 4, ',', '.'), '0'), ','),
                            'price' => number_format($transaction->price, 2, ',', '.').' €',
                        ]),
                        route('portfolio.savings'),
                    );
                }
            });

        $this->info("Savings plans executed: {$executed}");

        return self::SUCCESS;
    }
}
