<?php

namespace App\Support\Finance;

use App\Models\BankAccount;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Projects account balances for the coming weeks: the current balance, recurring income and
 * payments detected in the last months (salary, rent, subscriptions …) on their next due dates,
 * and the average of all other spending per day.
 */
class BalanceForecast
{
    /** Bookings of this many days are searched for recurring payments. */
    public const HISTORY_DAYS = 180;

    /** Other spending is averaged over at most this many days. */
    public const VARIABLE_DAYS = 90;

    public const HORIZONS = [30, 60, 90];

    /**
     * @param  Collection<int, BankAccount>  $accounts
     * @return array{
     *     accounts: list<array{account: BankAccount, start: float, end: float, lowest: array{date: string, value: float}, first_negative: ?string, variable_daily: float}>,
     *     events: list<array{date: string, name: string, amount: float, account: string, frequency: string}>,
     *     points: list<array{label: string, value: float}>,
     *     start: float,
     *     end: float,
     *     lowest: array{date: string, value: float},
     *     milestones: array<int, float>,
     *     variable_daily: float,
     *     first_negative: ?array{date: string, account: string}
     * }|null
     */
    public static function for(Collection $accounts, int $days = 90): ?array
    {
        $today = today();
        $accounts = $accounts->filter(fn (BankAccount $account) => $account->balance !== null)->values();

        if ($accounts->isEmpty()) {
            return null;
        }

        $totals = array_fill(0, $days + 1, 0.0);
        $events = [];
        $rows = [];

        foreach ($accounts as $account) {
            $projection = self::project($account, $today, $days);
            $events = [...$events, ...$projection['events']];

            foreach ($projection['daily'] as $index => $value) {
                $totals[$index] += $value;
            }

            $lowestIndex = array_keys($projection['daily'], min($projection['daily']))[0];
            $firstNegative = collect($projection['daily'])->search(fn (float $value) => $value < 0);
            $rows[] = [
                'account' => $account,
                'start' => $projection['daily'][0],
                'end' => $projection['daily'][$days],
                'lowest' => ['date' => $today->copy()->addDays($lowestIndex)->toDateString(), 'value' => $projection['daily'][$lowestIndex]],
                'first_negative' => $firstNegative === false ? null : $today->copy()->addDays($firstNegative)->toDateString(),
                'variable_daily' => $projection['variable_daily'],
            ];
        }

        usort($events, fn (array $a, array $b) => [$a['date'], $a['amount']] <=> [$b['date'], $b['amount']]);
        $totals = array_map(fn (float $value) => round($value, 2), $totals);
        $lowestIndex = array_keys($totals, min($totals))[0];
        $negative = collect($rows)->filter(fn (array $row) => $row['first_negative'] !== null)->sortBy('first_negative')->first();

        return [
            'accounts' => $rows,
            'events' => $events,
            'points' => collect($totals)->map(fn (float $value, int $index) => ['label' => $today->copy()->addDays($index)->translatedFormat('D, j. M'), 'value' => $value])->all(),
            'start' => $totals[0],
            'end' => $totals[$days],
            'lowest' => ['date' => $today->copy()->addDays($lowestIndex)->toDateString(), 'value' => $totals[$lowestIndex]],
            'milestones' => collect(self::HORIZONS)->filter(fn (int $horizon) => $horizon <= $days)->mapWithKeys(fn (int $horizon) => [$horizon => $totals[$horizon]])->all(),
            'variable_daily' => round(array_sum(array_column($rows, 'variable_daily')), 2),
            'first_negative' => $negative ? ['date' => $negative['first_negative'], 'account' => $negative['account']->name] : null,
        ];
    }

    /**
     * @return array{daily: list<float>, events: list<array{date: string, name: string, amount: float, account: string, frequency: string}>, variable_daily: float}
     */
    private static function project(BankAccount $account, Carbon $today, int $days): array
    {
        $transactions = $account->transactions()->where('booked_on', '>=', $today->copy()->subDays(self::HISTORY_DAYS)->toDateString())->get();

        // Income is detected like payments by looking at it with the opposite sign.
        $recurring = [
            ...array_map(fn (array $row) => ['sign' => -1] + $row, FinanceReport::recurring($transactions->where('amount', '<', 0))),
            ...array_map(fn (array $row) => ['sign' => 1] + $row, FinanceReport::recurring($transactions->where('amount', '>', 0)->map(fn (Transaction $transaction) => (clone $transaction)->setAttribute('amount', -$transaction->amount)))),
        ];
        $recurringKeys = array_column($recurring, 'key');

        $end = $today->copy()->addDays($days);
        $events = [];

        foreach ($recurring as $row) {
            $next = self::advance($row['lastDate']->copy(), $row['frequency']);

            // A payment that missed two of its dates has probably ended.
            if (self::advance($next->copy(), $row['frequency'])->lt($today)) {
                continue;
            }

            while ($next->lte($today)) {
                $next = self::advance($next, $row['frequency']);
            }

            while ($next->lte($end)) {
                $events[] = ['date' => $next->toDateString(), 'name' => $row['name'], 'amount' => round($row['sign'] * $row['amount'], 2), 'account' => $account->name, 'frequency' => $row['frequency']];
                $next = self::advance($next->copy(), $row['frequency']);
            }
        }

        $variableFrom = $today->copy()->subDays(self::VARIABLE_DAYS);
        $variable = $transactions->filter(fn (Transaction $transaction) => $transaction->amount < 0
            && $transaction->booked_on->gte($variableFrom)
            && $transaction->category !== FinanceCategories::TRANSFER
            && ! in_array(FinanceReport::payeeKey($transaction->counterparty, $transaction->purpose), $recurringKeys, true));
        $first = $transactions->min('booked_on');
        $span = $first ? max(30, min(self::VARIABLE_DAYS, (int) Carbon::parse($first)->diffInDays($today))) : self::VARIABLE_DAYS;
        $variableDaily = round(-$variable->sum('amount') / $span, 2);

        $byDate = collect($events)->groupBy('date')->map(fn (Collection $group) => $group->sum('amount'));
        $balance = (float) $account->currentBalance();
        $daily = [round($balance, 2)];

        for ($index = 1; $index <= $days; $index++) {
            $balance += (float) ($byDate[$today->copy()->addDays($index)->toDateString()] ?? 0) - $variableDaily;
            $daily[] = round($balance, 2);
        }

        return ['daily' => $daily, 'events' => $events, 'variable_daily' => $variableDaily];
    }

    private static function advance(Carbon $date, string $frequency): Carbon
    {
        return match ($frequency) {
            'weekly' => $date->addWeek(),
            'bimonthly' => $date->addMonthsNoOverflow(2),
            'quarterly' => $date->addMonthsNoOverflow(3),
            'halfyearly' => $date->addMonthsNoOverflow(6),
            'yearly' => $date->addYearNoOverflow(),
            default => $date->addMonthNoOverflow(),
        };
    }
}
