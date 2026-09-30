<?php

namespace App\Support\Finance;

use App\Models\FinanceBudget;
use App\Models\Transaction;
use App\Models\User;
use App\Support\WebPush;
use Illuminate\Support\Carbon;

/**
 * Monthly budgets per category: spending of the current month compared to the limit, and
 * one push warning at 80 % and one at 100 % per month.
 */
class Budgets
{
    public const WARN_LEVELS = [100, 80];

    /**
     * @return list<array{budget: FinanceBudget, label: string, icon: string, spent: float, limit: float, percent: float, remaining: float}>
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

        return $budgets->map(function (FinanceBudget $budget) use ($spent) {
            $amount = round(-(float) ($spent[$budget->category] ?? 0), 2);

            return [
                'budget' => $budget,
                'label' => FinanceCategories::label($budget->category),
                'icon' => FinanceCategories::icon($budget->category),
                'spent' => $amount,
                'limit' => $budget->monthly_limit,
                'percent' => $budget->monthly_limit > 0 ? round($amount / $budget->monthly_limit * 100, 1) : 0.0,
                'remaining' => round($budget->monthly_limit - $amount, 2),
            ];
        })->sortByDesc('percent')->values()->all();
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
                        __(':spent of :limit spent this month.', ['spent' => number_format($row['spent'], 2, ',', '.').' €', 'limit' => number_format($row['limit'], 2, ',', '.').' €']),
                        route('finance.index', ['tab' => 'budgets']),
                    );
                    $budget->update(['warned_month' => $month, 'warned_level' => $level]);
                    $sent++;
                }
            });

        return $sent;
    }
}
