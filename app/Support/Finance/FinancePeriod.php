<?php

namespace App\Support\Finance;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Report periods ("3m", "6m", "12m", "ytd", "last_year") and the owner's bookings in them.
 */
class FinancePeriod
{
    public const DEFAULT = '6m';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            '3m' => __('Last 3 months'),
            '6m' => __('Last 6 months'),
            '12m' => __('Last 12 months'),
            'ytd' => __('This year'),
            'last_year' => __('Last year'),
        ];
    }

    /**
     * Full months up to today (the current month included).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function range(string $period): array
    {
        $today = now()->endOfDay();

        return match ($period) {
            '3m' => [$today->copy()->startOfMonth()->subMonths(2), $today],
            '12m' => [$today->copy()->startOfMonth()->subMonths(11), $today],
            'ytd' => [$today->copy()->startOfYear(), $today],
            'last_year' => [$today->copy()->subYear()->startOfYear(), $today->copy()->subYear()->endOfYear()],
            default => [$today->copy()->startOfMonth()->subMonths(5), $today],
        };
    }

    /**
     * @return Builder<Transaction>
     */
    public static function query(User $user, int $householdId, string $period, ?int $accountId = null): Builder
    {
        [$from, $to] = self::range($period);

        return Transaction::query()
            ->where('user_id', $user->id)
            ->where('household_id', $householdId)
            ->when($accountId, fn (Builder $query) => $query->where('bank_account_id', $accountId))
            ->whereBetween('booked_on', [$from->toDateString(), $to->toDateString()]);
    }
}
