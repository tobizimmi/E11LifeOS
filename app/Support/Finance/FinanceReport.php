<?php

namespace App\Support\Finance;

use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Analysis of a person's bookings for a period: totals and savings rate, month by month,
 * categories, biggest payees, recurring payments (subscriptions, contracts, price
 * increases), the 50/30/20 split and concrete savings suggestions with yearly estimates.
 */
class FinanceReport
{
    private const STREAMING = ['netflix', 'disney', 'prime video', 'amazon prime', 'dazn', 'sky ', 'wow ', 'apple tv', 'paramount', 'rtl+', 'joyn', 'spotify', 'deezer', 'tidal', 'youtube premium', 'audible'];

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @param  array{includeTransfers?: bool, includeSavings?: bool}  $options
     * @return array<string, mixed>
     */
    public static function build(Collection $transactions, Carbon $from, Carbon $to, array $options = []): array
    {
        $includeTransfers = (bool) ($options['includeTransfers'] ?? false);
        $includeSavings = (bool) ($options['includeSavings'] ?? false);

        $bookings = ($includeTransfers
            ? $transactions
            : $transactions->filter(fn (Transaction $transaction) => $transaction->category !== FinanceCategories::TRANSFER))->values();
        $months = self::months($from, $to);
        $monthCount = max(1, count($months));

        $income = (float) $bookings->where('amount', '>', 0)->sum('amount');
        $savingsOut = $includeSavings ? 0.0 : (float) -$bookings->where('amount', '<', 0)->where('category', 'savings')->sum('amount');
        $expenseBookings = $bookings->filter(fn (Transaction $transaction) => $transaction->amount < 0 && ($includeSavings || $transaction->category !== 'savings'));
        $expenses = (float) -$expenseBookings->sum('amount');

        $categories = $expenseBookings->groupBy('category')
            ->map(fn (Collection $group, string $category) => [
                'key' => $category,
                'label' => FinanceCategories::label($category),
                'icon' => FinanceCategories::icon($category),
                'amount' => round((float) -$group->sum('amount'), 2),
                'count' => $group->count(),
                'perMonth' => round((float) -$group->sum('amount') / $monthCount, 2),
                'share' => $expenses > 0 ? round(-$group->sum('amount') / $expenses * 100, 1) : 0.0,
            ])
            ->sortByDesc('amount')->values()->all();

        $byAccount = $expenseBookings->groupBy('bank_account_id')
            ->map(fn (Collection $group) => [
                'label' => (string) (data_get($group->first(), 'account.name') ?: __('Account')),
                'amount' => round((float) -$group->sum('amount'), 2),
                'count' => $group->count(),
            ])
            ->sortByDesc('amount')->values()
            ->map(fn (array $row) => $row + ['share' => $expenses > 0 ? round($row['amount'] / $expenses * 100, 1) : 0.0])
            ->all();

        $monthly = collect($months)->map(function (string $month) use ($bookings, $includeSavings) {
            $inMonth = $bookings->filter(fn (Transaction $transaction) => $transaction->booked_on->format('Y-m') === $month);

            return [
                'month' => $month,
                'label' => Carbon::createFromFormat('!Y-m', $month)->translatedFormat('M y'),
                'income' => round((float) $inMonth->where('amount', '>', 0)->sum('amount'), 2),
                'expenses' => round((float) -$inMonth->filter(fn (Transaction $transaction) => $transaction->amount < 0 && ($includeSavings || $transaction->category !== 'savings'))->sum('amount'), 2),
            ];
        })->all();

        $recurring = self::recurring($includeSavings ? $expenseBookings : $expenseBookings->concat($bookings->where('category', 'savings')));
        $groups = ['needs' => 0.0, 'wants' => 0.0, 'savings' => $savingsOut];

        foreach ($categories as $category) {
            $group = FinanceCategories::group($category['key']);
            $groups[$group === 'needs' ? 'needs' : 'wants'] += $category['amount'];
        }

        $report = [
            'from' => $from,
            'to' => $to,
            'monthCount' => $monthCount,
            'count' => $bookings->count(),
            'income' => round($income, 2),
            'expenses' => round($expenses, 2),
            'savingsTransfers' => round($savingsOut, 2),
            'balance' => round($income - $expenses, 2),
            'savingsRate' => $income > 0 ? round(($income - $expenses) / $income * 100, 1) : null,
            'avgExpenses' => round($expenses / $monthCount, 2),
            'avgIncome' => round($income / $monthCount, 2),
            'categories' => $categories,
            'byAccount' => $byAccount,
            'monthly' => $monthly,
            'payees' => self::payees($expenseBookings),
            'largest' => $expenseBookings->sortBy('amount')->take(5)->values()->all(),
            'recurring' => $recurring,
            'recurringMonthly' => round(array_sum(array_column($recurring, 'monthly')), 2),
            'split' => [
                'needs' => $income > 0 ? round($groups['needs'] / $income * 100, 1) : null,
                'wants' => $income > 0 ? round($groups['wants'] / $income * 100, 1) : null,
                'savings' => $income > 0 ? round(max(0, $income - $expenses) / $income * 100, 1) : null,
                'amounts' => array_map(fn ($value) => round($value, 2), $groups),
            ],
        ];

        $report['suggestions'] = self::suggestions($report, $expenseBookings);
        $report['potential'] = round(array_sum(array_column($report['suggestions'], 'yearly')), 2);

        return $report;
    }

    /**
     * @return array<int, string> "Y-m" of every month in the period.
     */
    private static function months(Carbon $from, Carbon $to): array
    {
        $months = [];

        for ($month = $from->copy()->startOfMonth(); $month->lte($to); $month->addMonth()) {
            $months[] = $month->format('Y-m');
        }

        return $months;
    }

    /**
     * "NETFLIX INTERNATIONAL B.V." and "Netflix.com" → "netflix".
     */
    public static function payeeKey(?string $counterparty, ?string $purpose): string
    {
        $name = mb_strtolower($counterparty ?: (string) $purpose);
        $name = preg_replace('/\b(gmbh|ag|se|kg|b\.?v\.?|ltd|inc|sarl|s\.a\.|co|und|u\.|sagt danke|europe|deutschland|international|com|de|www|online|services?|payments?)\b/u', ' ', $name);
        $words = preg_split('/[^\p{L}]+/u', $name, -1, PREG_SPLIT_NO_EMPTY);

        return implode(' ', array_slice($words, 0, 2)) ?: '—';
    }

    /**
     * @param  Collection<int, Transaction>  $expenses
     * @return array<int, array{name: string, amount: float, count: int}>
     */
    private static function payees(Collection $expenses): array
    {
        return $expenses->groupBy(fn (Transaction $transaction) => self::payeeKey($transaction->counterparty, $transaction->purpose))
            ->map(fn (Collection $group) => [
                'name' => (string) ($group->first()->counterparty ?: $group->first()->purpose ?: '—'),
                'amount' => round((float) -$group->sum('amount'), 2),
                'count' => $group->count(),
            ])
            ->sortByDesc('amount')->take(10)->values()->all();
    }

    /**
     * Payments to the same payee with a similar amount at regular intervals.
     *
     * @param  Collection<int, Transaction>  $expenses
     * @return array<int, array<string, mixed>>
     */
    public static function recurring(Collection $expenses): array
    {
        $rows = [];

        $expenses->reject(fn (Transaction $transaction) => in_array($transaction->category, ['cash', 'groceries', 'restaurants', 'shopping'], true))
            ->groupBy(fn (Transaction $transaction) => self::payeeKey($transaction->counterparty, $transaction->purpose))
            ->each(function (Collection $group, string $key) use (&$rows) {
                $group = $group->sortBy('booked_on')->values();

                if ($group->count() < 2 || $group->pluck('booked_on')->map->format('Y-m')->unique()->count() < 2) {
                    return;
                }

                // The latest payment may carry a price increase, so it only needs to be roughly similar.
                $latest = -$group->last()->amount;
                $amounts = ($group->count() >= 3 ? $group->slice(0, -1) : $group)->map(fn (Transaction $transaction) => -$transaction->amount)->sort()->values();
                $median = $amounts[intdiv($amounts->count(), 2)];
                $similar = $amounts->filter(fn (float $amount) => abs($amount - $median) <= max(1.0, $median * 0.2))->count();

                if ($similar / $amounts->count() < 0.75 || abs($latest - $median) > max(1.0, $median * 0.5)) {
                    return;
                }

                $gaps = [];

                for ($index = 1; $index < $group->count(); $index++) {
                    $gaps[] = $group[$index - 1]->booked_on->diffInDays($group[$index]->booked_on);
                }

                sort($gaps);
                $gap = $gaps[intdiv(count($gaps), 2)];
                [$frequency, $perMonth] = match (true) {
                    $gap >= 5 && $gap <= 9 => ['weekly', 52 / 12],
                    $gap >= 25 && $gap <= 36 => ['monthly', 1],
                    $gap >= 55 && $gap <= 65 => ['bimonthly', 0.5],
                    $gap >= 80 && $gap <= 100 => ['quarterly', 1 / 3],
                    $gap >= 170 && $gap <= 195 => ['halfyearly', 1 / 6],
                    $gap >= 340 && $gap <= 390 => ['yearly', 1 / 12],
                    default => [null, 0],
                };

                if ($frequency === null) {
                    return;
                }

                $last = $group->last();
                $previous = $group[$group->count() - 2];

                $rows[] = [
                    'key' => $key,
                    'name' => (string) ($last->counterparty ?: $last->purpose ?: $key),
                    'category' => $last->category,
                    'frequency' => $frequency,
                    'amount' => round(-$last->amount, 2),
                    'monthly' => round(-$last->amount * $perMonth, 2),
                    'yearly' => round(-$last->amount * $perMonth * 12, 2),
                    'lastDate' => $last->booked_on,
                    'count' => $group->count(),
                    'increase' => -$last->amount > -$previous->amount * 1.03 ? round(-$last->amount + $previous->amount, 2) : null,
                ];
            });

        usort($rows, fn (array $a, array $b) => $b['monthly'] <=> $a['monthly']);

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  Collection<int, Transaction>  $expenses
     * @return array<int, array{icon: string, title: string, text: string, yearly: float}>
     */
    private static function suggestions(array $report, Collection $expenses): array
    {
        $suggestions = [];
        $months = $report['monthCount'];
        $perMonth = fn (string $category) => (float) (collect($report['categories'])->firstWhere('key', $category)['perMonth'] ?? 0);
        $euro = fn (float $value) => number_format($value, 2, ',', '.').' €';

        $subscriptions = array_values(array_filter($report['recurring'], fn (array $row) => $row['category'] === 'subscriptions'));
        $streaming = array_values(array_filter($subscriptions, fn (array $row) => collect(self::STREAMING)->contains(fn (string $name) => str_contains(mb_strtolower($row['name'].' '), $name))));

        if (count($streaming) >= 2) {
            usort($streaming, fn (array $a, array $b) => $a['monthly'] <=> $b['monthly']);
            $rotate = array_slice($streaming, 0, count($streaming) - 1);
            $suggestions[] = [
                'icon' => '📺',
                'title' => __(':count streaming services at the same time', ['count' => count($streaming)]),
                'text' => __('You pay for :names. Rotating – keeping one at a time and switching every few months – saves the others.', ['names' => implode(', ', array_column($streaming, 'name'))]),
                'yearly' => round(array_sum(array_column($rotate, 'yearly')), 2),
            ];
        } elseif ($subscriptions !== []) {
            $total = array_sum(array_column($subscriptions, 'yearly'));
            $suggestions[] = [
                'icon' => '🔁',
                'title' => __('Check your subscriptions'),
                'text' => __(':count subscriptions cost you :amount a year. Cancelling the ones you rarely use saves roughly a third.', ['count' => count($subscriptions), 'amount' => $euro($total)]),
                'yearly' => round($total / 3, 2),
            ];
        }

        foreach (array_filter($report['recurring'], fn (array $row) => $row['increase'] !== null) as $row) {
            $suggestions[] = [
                'icon' => '📈',
                'title' => __('Price increase: :name', ['name' => $row['name']]),
                'text' => __('The last payment was :increase higher than before (now :amount). A good moment to compare offers or to cancel.', ['increase' => $euro($row['increase']), 'amount' => $euro($row['amount'])]),
                'yearly' => round($row['increase'] * ($row['monthly'] / max(0.01, $row['amount'])) * 12, 2),
            ];
        }

        if (($restaurants = $perMonth('restaurants')) > 120 || ($report['expenses'] > 0 && $restaurants * $months / $report['expenses'] > 0.1)) {
            $suggestions[] = [
                'icon' => '🍕',
                'title' => __('Eating out and delivery'),
                'text' => __('On average :amount per month go to restaurants and delivery services. Cooking at home twice more a week (e.g. with the meal plan) saves about 40%.', ['amount' => $euro($restaurants)]),
                'yearly' => round($restaurants * 12 * 0.4, 2),
            ];
        }

        if (($fees = $perMonth('bank_fees')) > 0) {
            $suggestions[] = [
                'icon' => '🏦',
                'title' => __('Bank fees and interest'),
                'text' => __('You pay :amount per month in account fees or overdraft interest. Free current accounts are widely available; overdraft interest is often avoidable with a small buffer.', ['amount' => $euro($fees)]),
                'yearly' => round($fees * 12, 2),
            ];
        }

        foreach ([['energy', 120, 0.15, '⚡', __('Electricity and heating'), __('Energy costs :amount per month. Comparing tariffs regularly and switching after the minimum term typically saves 10–20%.')],
            ['telecom', 40, 0.3, '📱', __('Phone and internet'), __('Phone and internet cost :amount per month. Current SIM-only and DSL offers are often much cheaper than older contracts.')],
            ['insurance', 150, 0.1, '🛡️', __('Insurance'), __('Insurance costs :amount per month. Check for double cover and ask for a yearly instead of monthly payment (often 3–5% cheaper).')]] as [$category, $threshold, $rate, $icon, $title, $text]) {
            if (($value = $perMonth($category)) > $threshold) {
                $suggestions[] = ['icon' => $icon, 'title' => $title, 'text' => str_replace(':amount', $euro($value), $text), 'yearly' => round($value * 12 * $rate, 2)];
            }
        }

        if ($report['income'] > 0 && $report['split']['wants'] !== null && $report['split']['wants'] > 30) {
            $excess = ($report['split']['wants'] - 30) / 100 * $report['income'] / $months;
            $suggestions[] = [
                'icon' => '⚖️',
                'title' => __('50/30/20 rule'),
                'text' => __(':share% of your income goes to wishes and lifestyle (guideline: 30%). Bringing it down to 30% frees about :amount per month.', ['share' => number_format($report['split']['wants'], 0, ',', '.'), 'amount' => $euro($excess)]),
                'yearly' => round($excess * 12, 2),
            ];
        }

        if ($report['savingsRate'] !== null && $report['savingsRate'] < 10) {
            $suggestions[] = [
                'icon' => '🐷',
                'title' => __('Save automatically'),
                'text' => __('Your savings rate is :rate%. A standing order of 10% of your income right after payday (:amount per month) builds a buffer without noticing.', ['rate' => number_format($report['savingsRate'], 1, ',', '.'), 'amount' => $euro($report['avgIncome'] * 0.1)]),
                'yearly' => 0.0,
            ];
        }

        if ($report['expenses'] > 0 && ($cash = $perMonth('cash')) * $months / $report['expenses'] > 0.15) {
            $suggestions[] = [
                'icon' => '💵',
                'title' => __('A lot of cash'),
                'text' => __(':amount per month are withdrawn in cash – where it goes stays invisible. Paying by card for a while shows what the money is spent on.', ['amount' => $euro($cash)]),
                'yearly' => 0.0,
            ];
        }

        usort($suggestions, fn (array $a, array $b) => $b['yearly'] <=> $a['yearly']);

        return $suggestions;
    }
}
