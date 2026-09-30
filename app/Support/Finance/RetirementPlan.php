<?php

namespace App\Support\Finance;

use App\Models\User;

/**
 * Pension gap and financial independence: from age, desired income, expected pensions, savings
 * and assets it projects the capital (in today's money, real return after inflation) and compares
 * it with the capital needed under the 4 % rule – for the pension gap at retirement age and for
 * full financial freedom without any pension.
 */
class RetirementPlan
{
    /**
     * @return array{birth_year: int, retirement_age: int, desired_income: float, statutory_pension: float, other_pension: float, assets: float, monthly_savings: float, return: float, inflation: float, withdrawal: float}
     */
    public static function defaults(User $user): array
    {
        $statement = CashflowStatement::for($user);
        $liquid = collect($statement['assets']['rows'])->filter(fn (array $row) => in_array($row['label'], [__('Bank accounts'), __('Securities')], true))->sum('value');

        return [
            'birth_year' => (int) now()->year - 35,
            'retirement_age' => 67,
            'desired_income' => $statement['expenses']['total'] > 0 ? round($statement['expenses']['total'], -1) : 2500.0,
            'statutory_pension' => 0.0,
            'other_pension' => 0.0,
            'assets' => round((float) $liquid, -2),
            'monthly_savings' => max(0.0, round($statement['cashflow'], -1)),
            'return' => 5.0,
            'inflation' => 2.0,
            'withdrawal' => 4.0,
        ];
    }

    /**
     * The member's saved inputs merged over the defaults.
     *
     * @return array{birth_year: int, retirement_age: int, desired_income: float, statutory_pension: float, other_pension: float, assets: float, monthly_savings: float, return: float, inflation: float, withdrawal: float}
     */
    public static function inputs(User $user): array
    {
        $saved = is_array($user->retirement_plan) ? $user->retirement_plan : [];
        $defaults = self::defaults($user);

        foreach ($defaults as $key => $value) {
            if (isset($saved[$key]) && is_numeric($saved[$key])) {
                $defaults[$key] = is_int($value) ? (int) $saved[$key] : (float) $saved[$key];
            }
        }

        return $defaults;
    }

    /**
     * @param  array{birth_year: int, retirement_age: int, desired_income: float, statutory_pension: float, other_pension: float, assets: float, monthly_savings: float, return: float, inflation: float, withdrawal: float}  $inputs
     * @return array{age: int, years: int, gap: float, capital_needed: float, capital_projected: float, coverage: int, savings_needed: float, freedom_capital: float, freedom_age: ?int, real_return: float, points: list<array{label: string, value: float, compare: float}>}
     */
    public static function calculate(array $inputs): array
    {
        $age = max(0, (int) now()->year - $inputs['birth_year']);
        $years = max(0, $inputs['retirement_age'] - $age);
        $real = (1 + $inputs['return'] / 100) / (1 + $inputs['inflation'] / 100) - 1;
        $withdrawal = max(0.5, $inputs['withdrawal']) / 100;

        $gap = max(0.0, $inputs['desired_income'] - $inputs['statutory_pension'] - $inputs['other_pension']);
        $needed = $gap * 12 / $withdrawal;
        $freedomCapital = $inputs['desired_income'] * 12 / $withdrawal;
        $projected = self::futureValue($inputs['assets'], $inputs['monthly_savings'], $real, $years);

        $savingsNeeded = 0.0;

        if ($needed > self::futureValue($inputs['assets'], 0, $real, $years)) {
            $shortfall = $needed - self::futureValue($inputs['assets'], 0, $real, $years);
            $factor = $years > 0 ? (abs($real) < 1e-9 ? $years : (((1 + $real) ** $years) - 1) / $real) : 0;
            $savingsNeeded = $factor > 0 ? $shortfall / $factor / 12 : $shortfall;
        }

        $freedomAge = null;
        $points = [];

        for ($year = 0; $age + $year <= 100; $year++) {
            $capital = self::futureValue($inputs['assets'], $inputs['monthly_savings'], $real, $year);

            if ($freedomAge === null && $capital >= $freedomCapital) {
                $freedomAge = $age + $year;
            }

            if ($year <= max($years, 1) + 15 && $year % max(1, (int) ceil(($years + 15) / 20)) === 0) {
                $points[] = ['label' => __('Age :age', ['age' => $age + $year]), 'value' => round($capital, 2), 'compare' => round($freedomCapital, 2)];
            }
        }

        return [
            'age' => $age,
            'years' => $years,
            'gap' => round($gap, 2),
            'capital_needed' => round($needed, 2),
            'capital_projected' => round($projected, 2),
            'coverage' => $needed > 0 ? (int) min(999, round($projected / $needed * 100)) : 100,
            'savings_needed' => round($savingsNeeded, 2),
            'freedom_capital' => round($freedomCapital, 2),
            'freedom_age' => $freedomAge,
            'real_return' => round($real * 100, 2),
            'points' => $points,
        ];
    }

    /**
     * Capital after some years with a starting amount, monthly savings (paid yearly) and a yearly return.
     */
    public static function futureValue(float $start, float $monthly, float $rate, int $years): float
    {
        if ($years <= 0) {
            return $start;
        }

        $growth = (1 + $rate) ** $years;
        $annuity = abs($rate) < 1e-9 ? $years : ($growth - 1) / $rate;

        return $start * $growth + $monthly * 12 * $annuity;
    }
}
