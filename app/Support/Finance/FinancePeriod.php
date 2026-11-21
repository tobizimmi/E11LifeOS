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

    public const CUSTOM = 'custom';

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
            self::CUSTOM => __('Custom range'),
        ];
    }

    /**
     * Full months up to today (the current month included), or a free custom range.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function range(string $period, ?string $customFrom = null, ?string $customTo = null): array
    {
        if ($period === self::CUSTOM) {
            return self::customRange($customFrom, $customTo);
        }

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
     * A free from–to range (dates swapped if given the wrong way round; sensible defaults otherwise).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function customRange(?string $customFrom, ?string $customTo): array
    {
        $to = self::parse($customTo) ?? now();
        $from = self::parse($customFrom) ?? $to->copy()->startOfMonth()->subMonths(5);

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return [$from->copy()->startOfDay(), $to->copy()->endOfDay()];
    }

    /**
     * The equally long period immediately before the given one, for comparisons.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function previousRange(Carbon $from, Carbon $to): array
    {
        $days = (int) $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;
        $previousTo = $from->copy()->subDay()->endOfDay();

        return [$previousTo->copy()->subDays($days - 1)->startOfDay(), $previousTo];
    }

    private static function parse(?string $value): ?Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return Builder<Transaction>
     */
    public static function query(User $user, int $householdId, string $period, ?int $accountId = null, ?string $customFrom = null, ?string $customTo = null): Builder
    {
        [$from, $to] = self::range($period, $customFrom, $customTo);

        return self::queryFor($user, $householdId, $from, $to, $accountId);
    }

    /**
     * @return Builder<Transaction>
     */
    public static function queryFor(User $user, int $householdId, Carbon $from, Carbon $to, ?int $accountId = null): Builder
    {
        return Transaction::query()
            ->where('user_id', $user->id)
            ->where('household_id', $householdId)
            ->whereNull('duplicate_of_id') // confirmed duplicates never count in reports or totals
            ->when($accountId, fn (Builder $query) => $query->where('bank_account_id', $accountId))
            ->whereBetween('booked_on', [$from->toDateString(), $to->toDateString()]);
    }
}
