<?php

namespace App\Support\Finance;

use App\Models\FinanceBudget;
use App\Models\Transaction;
use App\Models\User;
use App\Support\WebPush;
use Illuminate\Support\Carbon;

/**
 * Monthly budgets per category: spending of the current month compared to the limit, and
 * one push warning at 80 % and one at 100 % per month. Budgets with rollover are pots: the
 * amount left over from earlier months (or overspent) is added to this month's limit.
 */
class Budgets
{
    public const WARN_LEVELS = [100, 80];

    /**
     * @return list<array{budget: FinanceBudget, label: string, icon: string, spent: float, limit: float, carry: float, available: float, percent: float, remaining: float}>
     */
    public static function status(User $user, int $householdId, ?Carbon $month = null): array
    {
        $month ??= now();
        $budgets = FinanceBudget::where('user_id', $user->id)->where('household_id', $householdId)->get();

        if ($budgets->isEmpty()) {
            return [];
        }

        $spent = Transaction::where('user_id', $user->id)->where('household_id', $householdId)
            ->whereBetween('booked_on', [$month->copy()->startOfMonth()->toDateString(), $month->copy()->endOfMonth()->toDateString()])
            ->whereIn('category', $budgets->pluck('category'))
            ->where('amount', '<', 0)
            ->selectRaw('category, SUM(amount) as total')->groupBy('category')->pluck('total', 'category');

        return $budgets->map(function (FinanceBudget $budget) use ($spent, $user, $householdId, $month) {
            $amount = round(-(float) ($spent[$budget->category] ?? 0), 2);
            $carry = self::carry($budget, $user, $householdId, $month);
            $available = round($budget->monthly_limit + $carry, 2);

            return [
                'budget' => $budget,
                'label' => FinanceCategories::label($budget->category),
                'icon' => FinanceCategories::icon($budget->category),
                'spent' => $amount,
                'limit' => $budget->monthly_limit,
                'carry' => $carry,
                'available' => $available,
                'percent' => $available > 0 ? round($amount / $available * 100, 1) : ($amount > 0 ? 100.0 : 0.0),
                'remaining' => round($available - $amount, 2),
            ];
        })->sortByDesc('percent')->values()->all();
    }

    /**
     * What a pot brings into the month: the limits of the months since the rollover started
     * minus what was spent in them (negative when overspent). 0 without rollover.
     */
    public static function carry(FinanceBudget $budget, User $user, int $householdId, Carbon $month): float
    {
        $start = $budget->rollover_from?->copy()->startOfMonth();
        $monthStart = $month->copy()->startOfMonth();

        if (! $start || $start->gte($monthStart)) {
            return 0.0;
        }

        $months = (int) $start->diffInMonths($monthStart);
        $spentBefore = -(float) Transaction::where('user_id', $user->id)->where('household_id', $householdId)
            ->where('category', $budget->category)->where('amount', '<', 0)
            ->whereBetween('booked_on', [$start->toDateString(), $monthStart->copy()->subDay()->toDateString()])
            ->sum('amount');

        return round($budget->monthly_limit * $months - $spentBefore, 2);
    }

    /**
     * Sends the due warnings of all users; returns the number of warnings.
     */
    public static function warn(WebPush $webPush): int
    {
        $sent = 0;
        $month = now()->format('Y-m');

        FinanceBudget::with('user')->get()->groupBy(fn (FinanceBudget $budget) => $budget->user_id.'-'.$budget->household_id)
            ->each(function ($budgets) use ($webPush, $month, &$sent) {
                $first = $budgets->first();

                if (! $first->user) {
                    return;
                }

                foreach (self::status($first->user, $first->household_id) as $row) {
                    $level = collect(self::WARN_LEVELS)->first(fn (int $level) => $row['percent'] >= $level);
                    $budget = $row['budget'];
                    $alreadyWarned = $budget->warned_month === $month ? (int) $budget->warned_level : 0;

                    if (! $level || $level <= $alreadyWarned) {
                        continue;
                    }

                    $webPush->send(
                        $first->user,
                        ($level >= 100 ? '🚨 ' : '⚠️ ').__('Budget :category: :percent %', ['category' => $row['label'], 'percent' => number_format($row['percent'], 0, ',', '.')]),
                        __(':spent of :limit spent this month.', ['spent' => number_format($row['spent'], 2, ',', '.').' €', 'limit' => number_format($row['available'], 2, ',', '.').' €']),
                        route('finance.index', ['tab' => 'budgets']),
                    );
                    $budget->update(['warned_month' => $month, 'warned_level' => $level]);
                    $sent++;
                }
            });

        return $sent;
    }
}
