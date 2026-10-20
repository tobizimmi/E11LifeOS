<?php

namespace App\Support\Finance;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Wealth plan ("FIRE" projection): takes the current net worth and the monthly savings rate from the
 * cash-flow statement and projects the fortune forward with a chosen return. The target is either an
 * amount the member sets, or the financial-independence number (annual expenses ÷ withdrawal rate),
 * so the member sees when their assets could cover their living costs.
 */
class WealthPlan
{
    public const HORIZON_YEARS = 50;

    /**
     * @param  array{monthly_savings?: mixed, return?: mixed, withdrawal?: mixed, monthly_expenses?: mixed, target?: mixed}  $inputs
     * @return array{start: float, monthly_savings: float, monthly_expenses: float, return: float, withdrawal: float, fire_number: float, target: float, years_to_target: ?int, target_year: ?int, passive_at_target: float, series: list<array{year: int, value: float}>, has_data: bool}
     */
    public static function for(User $user, array $inputs = []): array
    {
        $netWorth = NetWorth::for($user);
        $cashflow = CashflowStatement::for($user);

        $start = (float) $netWorth['net'];
        $monthlySavings = self::number($inputs['monthly_savings'] ?? null, max(0.0, (float) $cashflow['cashflow']));
        $monthlyExpenses = self::number($inputs['monthly_expenses'] ?? null, max(0.0, (float) $cashflow['expenses']['total']));
        $return = self::clamp(self::number($inputs['return'] ?? null, 5.0), 0, 20);
        $withdrawal = self::clamp(self::number($inputs['withdrawal'] ?? null, 4.0), 1, 10);

        $fireNumber = $withdrawal > 0 ? round($monthlyExpenses * 12 / ($withdrawal / 100), 2) : 0.0;
        $customTarget = self::number($inputs['target'] ?? null, 0.0);
        $target = $customTarget > 0 ? round($customTarget, 2) : $fireNumber;

        $monthlyRate = $return / 100 / 12;
        $value = $start;
        $series = [['year' => 0, 'value' => round($value, 2)]];
        $yearsToTarget = $target > 0 && $value >= $target ? 0 : null;

        for ($year = 1; $year <= self::HORIZON_YEARS; $year++) {
            for ($month = 0; $month < 12; $month++) {
                $value = $value * (1 + $monthlyRate) + $monthlySavings;
            }

            $series[] = ['year' => $year, 'value' => round($value, 2)];

            if ($yearsToTarget === null && $target > 0 && $value >= $target) {
                $yearsToTarget = $year;
            }
        }

        return [
            'start' => round($start, 2),
            'monthly_savings' => round($monthlySavings, 2),
            'monthly_expenses' => round($monthlyExpenses, 2),
            'return' => $return,
            'withdrawal' => $withdrawal,
            'fire_number' => $fireNumber,
            'target' => $target,
            'years_to_target' => $yearsToTarget,
            'target_year' => $yearsToTarget !== null ? Carbon::today()->year + $yearsToTarget : null,
            'passive_at_target' => round($target * ($withdrawal / 100) / 12, 2),
            'series' => $series,
            'has_data' => $cashflow['has_data'] || $start != 0.0,
        ];
    }

    private static function number(mixed $value, float $default): float
    {
        return $value === null || $value === '' || ! is_numeric($value) ? $default : (float) $value;
    }

    private static function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}
