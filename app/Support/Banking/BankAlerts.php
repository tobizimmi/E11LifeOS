<?php

namespace App\Support\Banking;

use App\Models\BankAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Finance\BalanceForecast;
use App\Support\WebPush;
use Illuminate\Support\Carbon;

/**
 * Push messages (and bell entries) after a bank sync: salary received, large debits, a balance
 * below the user's limit and a forecast that goes below zero within 30 days.
 */
class BankAlerts
{
    /** @var array{salary: bool, large_debit: ?float, low_balance: ?float, forecast: bool} */
    public const DEFAULTS = ['salary' => true, 'large_debit' => 500.0, 'low_balance' => null, 'forecast' => true];

    /** Only bookings of the last days trigger a push – the first sync imports months. */
    public const RECENT_DAYS = 3;

    /** The forecast warning is repeated at most this often. */
    public const FORECAST_REPEAT_DAYS = 7;

    /**
     * @return array{salary: bool, large_debit: ?float, low_balance: ?float, forecast: bool}
     */
    public static function settings(User $user): array
    {
        return array_merge(self::DEFAULTS, array_intersect_key($user->bank_alerts ?? [], self::DEFAULTS));
    }

    /**
     * @param  list<Transaction>  $transactions  bookings imported by this sync
     * @param  list<array{account: BankAccount, before: ?float, after: float}>  $balances  balances updated by this sync
     */
    public static function afterSync(User $user, int $householdId, array $transactions, array $balances): int
    {
        $settings = self::settings($user);
        $euro = fn (float $value) => number_format($value, 2, ',', '.').' €';
        $url = route('finance.index', ['tab' => 'transactions']);
        $recent = today()->subDays(self::RECENT_DAYS);
        $messages = [];

        foreach ($transactions as $transaction) {
            if ($transaction->booked_on->lt($recent)) {
                continue;
            }

            $who = $transaction->counterparty ?: $transaction->purpose ?: '';

            if ($settings['salary'] && $transaction->category === 'salary' && $transaction->amount > 0) {
                $messages[] = ['💼 '.__('Salary received: :amount', ['amount' => '+'.$euro($transaction->amount)]), $who, $url];
            } elseif ($settings['large_debit'] && $transaction->amount <= -$settings['large_debit']) {
                $messages[] = ['💸 '.__('Large debit: :amount', ['amount' => $euro($transaction->amount)]), $who, $url];
            }
        }

        if ($settings['low_balance'] !== null) {
            foreach ($balances as $balance) {
                if ($balance['after'] < $settings['low_balance'] && ($balance['before'] === null || $balance['before'] >= $settings['low_balance'])) {
                    $messages[] = ['🔻 '.__('Low balance: :amount', ['amount' => $euro($balance['after'])]), $balance['account']->name, route('finance.index', ['tab' => 'forecast'])];
                }
            }
        }

        $alertedOn = $user->bank_alerts['forecast_alerted_on'] ?? null;

        if ($settings['forecast'] && (! $alertedOn || $alertedOn <= today()->subDays(self::FORECAST_REPEAT_DAYS)->toDateString())) {
            $forecast = BalanceForecast::for(BankAccount::where('user_id', $user->id)->where('household_id', $householdId)->get(), 30);

            if ($forecast['first_negative'] ?? null) {
                $messages[] = [
                    '⚠️ '.__(':account goes below zero', ['account' => $forecast['first_negative']['account']]),
                    __('Expected on :date according to the balance forecast.', ['date' => Carbon::parse($forecast['first_negative']['date'])->translatedFormat('D, j. M')]),
                    route('finance.index', ['tab' => 'forecast']),
                ];
                $user->update(['bank_alerts' => ['forecast_alerted_on' => today()->toDateString()] + ($user->bank_alerts ?? [])]);
            }
        }

        $webPush = app(WebPush::class);

        foreach ($messages as [$title, $body, $link]) {
            $webPush->send($user, $title, $body, $link);
        }

        return count($messages);
    }
}
