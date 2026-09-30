<?php

namespace App\Support\Life;

use App\Models\User;
use App\Models\WishlistItem;
use App\Support\Finance\CashflowStatement;
use Illuminate\Support\Collection;

/**
 * Can I afford it? Compares a wish with the monthly surplus and the reserves from the cashflow
 * analysis; an emergency fund of three months of expenses stays untouched.
 */
class Affordability
{
    public const RESERVE_MONTHS = 3;

    /** "Nice to have" wishes from this price on wait 30 days before buying. */
    public const COOLING_OFF_FROM = 100.0;

    public const COOLING_OFF_DAYS = 30;

    /**
     * @return array{surplus: float, liquid: float, expenses: float, reserve: float, available: float, has_data: bool}
     */
    public static function budget(User $user): array
    {
        $statement = CashflowStatement::for($user);
        $expenses = round((float) $statement['expenses']['total'], 2);
        $reserve = round($expenses * self::RESERVE_MONTHS, 2);
        $liquid = round((float) $statement['liquid'], 2);

        return [
            'surplus' => round((float) $statement['cashflow'], 2),
            'liquid' => $liquid,
            'expenses' => $expenses,
            'reserve' => $reserve,
            'available' => round(max(0.0, $liquid - $reserve), 2),
            'has_data' => (bool) $statement['has_data'],
        ];
    }

    /**
     * Checks the open wishes in order of priority: money planned for a more important wish is no
     * longer available for the next one.
     *
     * @param  Collection<int, WishlistItem>  $wishes
     * @param  array{surplus: float, liquid: float, expenses: float, reserve: float, available: float, has_data: bool}  $budget
     * @return array<int, array{level: string, text: string, months: ?int, hints: list<string>}>
     */
    public static function checkAll(Collection $wishes, array $budget): array
    {
        $order = array_flip(WishlistItem::PRIORITIES);
        $planned = 0.0;
        $results = [];

        foreach ($wishes->sortBy([fn ($a, $b) => ($order[$a->priority] ?? 9) <=> ($order[$b->priority] ?? 9), ['position', 'asc'], ['id', 'asc']]) as $wish) {
            $results[$wish->id] = self::check($wish, $budget, $planned);
            $planned += (float) $wish->price;
        }

        return $results;
    }

    /**
     * @param  array{surplus: float, liquid: float, expenses: float, reserve: float, available: float, has_data: bool}  $budget
     * @return array{level: string, text: string, months: ?int, hints: list<string>}
     */
    public static function check(WishlistItem $wish, array $budget, float $alreadyPlanned = 0.0): array
    {
        $hints = self::hints($wish);
        $price = (float) $wish->price;
        $euro = fn (float $value) => number_format($value, 2, ',', '.').' €';

        if ($price <= 0) {
            return ['level' => 'unknown', 'text' => __('Enter a price to check whether it fits your budget.'), 'months' => null, 'hints' => $hints];
        }

        if (! $budget['has_data']) {
            return ['level' => 'unknown', 'text' => __('No cashflow data yet – sync your bank or enter income and expenses in the cashflow analysis.'), 'months' => null, 'hints' => $hints];
        }

        $surplus = max(0.0, $budget['surplus']);
        $available = max(0.0, $budget['available'] - $alreadyPlanned);

        if ($surplus > 0 && $price <= $surplus / 2) {
            return ['level' => 'green', 'text' => __('Affordable from this month\'s surplus (:share % of :surplus).', ['share' => (int) round($price / $surplus * 100), 'surplus' => $euro($surplus)]), 'months' => 0, 'hints' => $hints];
        }

        if ($price <= $available) {
            return ['level' => 'green', 'text' => __('Affordable from your reserves – the emergency fund of :reserve stays untouched.', ['reserve' => $euro($budget['reserve'])]), 'months' => 0, 'hints' => $hints];
        }

        if ($surplus <= 0) {
            return ['level' => 'red', 'text' => __('Not affordable right now: there is no monthly surplus. Lower expenses or raise income first.'), 'months' => null, 'hints' => $hints];
        }

        $months = (int) ceil(($price - $available) / $surplus);

        return $months <= 12
            ? ['level' => 'yellow', 'text' => trans_choice('Saved up in :count month with your surplus of :surplus.|Saved up in :count months with your surplus of :surplus.', $months, ['surplus' => $euro($surplus)]), 'months' => $months, 'hints' => $hints]
            : ['level' => 'red', 'text' => __('Would take :count months of saving – think about whether it is worth it or look for a cheaper option.', ['count' => $months]), 'months' => $months, 'hints' => $hints];
    }

    /**
     * @return list<string>
     */
    private static function hints(WishlistItem $wish): array
    {
        $hints = [];
        $age = (int) ($wish->created_at?->diffInDays(now()) ?? 0);

        if ($wish->priority === 'nice' && (float) $wish->price >= self::COOLING_OFF_FROM && $age < self::COOLING_OFF_DAYS) {
            $hints[] = trans_choice('30-day rule: wait :count more day – if you still want it then, buy it.|30-day rule: wait :count more days – if you still want it then, buy it.', self::COOLING_OFF_DAYS - $age);
        }

        if (trim((string) $wish->reason) === '' && (float) $wish->price >= self::COOLING_OFF_FROM) {
            $hints[] = __('Write down why you want it – a clear reason makes the decision easier.');
        }

        return $hints;
    }
}
