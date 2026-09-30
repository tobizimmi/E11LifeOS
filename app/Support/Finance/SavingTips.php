<?php

namespace App\Support\Finance;

use App\Models\SavingTipState;
use App\Models\User;
use App\Support\Addons;

/**
 * Personal savings and cashflow tips: the savings suggestions of the last 12 months of bank
 * data plus checks of emergency fund and savings rate – each with a yearly potential.
 */
class SavingTips
{
    /** Assumed yearly return for the compound interest outlook. */
    public const GROWTH_RATE = 0.06;

    /**
     * @param  array<string, mixed>  $statement  see CashflowStatement::for()
     * @return list<array{key: string, icon: string, title: string, text: string, yearly: float, url: ?string, status: ?string}>
     */
    public static function for(User $user, array $statement): array
    {
        $tips = [];
        $household = $user->currentHousehold();
        $euro = fn (float $value) => number_format($value, 0, ',', '.').' €';

        if ($household && $user->canUseAddon(Addons::FINANCE)) {
            [$from, $to] = FinancePeriod::range('12m');
            $report = FinanceReport::build(FinancePeriod::query($user, $household->id, '12m')->get(), $from, $to);

            foreach ($report['suggestions'] ?? [] as $suggestion) {
                $tips[] = [
                    'key' => 'report:'.substr(md5($suggestion['title']), 0, 16),
                    'icon' => $suggestion['icon'],
                    'title' => $suggestion['title'],
                    'text' => $suggestion['text'],
                    'yearly' => (float) $suggestion['yearly'],
                    'url' => route('finance.index', ['tab' => 'report']),
                ];
            }
        }

        $expenses = (float) $statement['expenses']['total'];
        $income = (float) $statement['income']['total'];
        $reserveMonths = $statement['reserve_months'] ?? null;

        if ($expenses > 0 && $reserveMonths !== null && $reserveMonths < 3) {
            $tips[] = [
                'key' => 'emergency-fund',
                'icon' => '🛟',
                'title' => __('Build an emergency fund'),
                'text' => __('Your available money covers :months months of expenses. Three to six months (:target) on a call money account protect you from having to sell investments or take out loans.', ['months' => number_format((float) $reserveMonths, 1, ',', '.'), 'target' => $euro($expenses * 3)]),
                'yearly' => 0.0,
                'url' => route('net-worth.index'),
            ];
        }

        if ($income > 0) {
            $rate = (float) $statement['cashflow'] / $income;

            if ($rate < 0.1) {
                $tips[] = [
                    'key' => 'savings-rate',
                    'icon' => '🐷',
                    'title' => __('Raise your savings rate'),
                    'text' => __('You keep :rate % of your income. Aim for at least 10–20 %: a standing order right after payday of :amount per month is a good start.', ['rate' => number_format(max(0, $rate) * 100, 0, ',', '.'), 'amount' => $euro($income * 0.1)]),
                    'yearly' => 0.0,
                    'url' => route('knowledge.index', ['category' => 'strategies']),
                ];
            }
        }

        $states = SavingTipState::where('user_id', $user->id)->pluck('status', 'key');

        return collect($tips)->unique('key')->map(fn (array $tip) => $tip + ['status' => $states[$tip['key']] ?? null])
            ->sortByDesc('yearly')->values()->all();
    }

    /**
     * What a monthly amount grows to with compound interest (monthly contributions).
     */
    public static function compound(float $monthly, int $years, float $rate = self::GROWTH_RATE): float
    {
        if ($monthly <= 0) {
            return 0.0;
        }

        $monthlyRate = $rate / 12;
        $months = $years * 12;

        return round($monthlyRate > 0 ? $monthly * (((1 + $monthlyRate) ** $months) - 1) / $monthlyRate : $monthly * $months, 2);
    }
}
