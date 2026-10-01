<?php

namespace App\Support;

/**
 * Combines ingredient lines of several recipes into one shopping list:
 * the same ingredient ("Zwiebel", "Zwiebeln (rot)") with units that can be converted is merged and
 * added up ("200 g" + "0,3 kg" = "500 g", "1 EL" + "1 TL" = "4 TL"), anything else is listed side by side
 * ("1 Prise + etwas").
 */
class ShoppingIngredients
{
    /**
     * @param  array<int, array{name: string, amount: string, unit: string, recipe: string}>  $lines
     * @return array<int, array{name: string, quantity: string, recipes: array<int, string>}>
     */
    public static function combine(array $lines): array
    {
        $groups = [];

        foreach ($lines as $line) {
            $name = trim($line['name']);

            if ($name === '') {
                continue;
            }

            $unit = IngredientUnits::normalize($line['unit']);
            $key = IngredientUnits::nameKey($name).'|'.IngredientUnits::family($unit);
            $groups[$key] ??= ['name' => $name, 'unit' => $unit, 'amounts' => [], 'recipes' => []];
            $groups[$key]['amounts'][] = ['amount' => trim($line['amount']), 'unit' => $unit];
            $groups[$key]['recipes'][] = $line['recipe'];
        }

        return collect($groups)
            ->map(fn (array $group) => [
                'name' => $group['name'],
                'quantity' => self::quantity($group['amounts'], $group['unit']),
                'recipes' => array_values(array_unique($group['recipes'])),
            ])
            ->sortBy(fn (array $item) => strtr(mb_strtolower($item['name']), ['ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss']))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{amount: string, unit: string}>  $amounts  all of one unit family
     */
    private static function quantity(array $amounts, string $unit): string
    {
        $present = array_values(array_filter($amounts, fn (array $amount) => $amount['amount'] !== ''));
        $values = array_map(fn (array $amount) => IngredientAmount::parse($amount['amount']), $present);

        if ($present === []) {
            return $unit;
        }

        if (! in_array(null, $values, true)) {
            $base = array_sum(array_map(fn (array $amount, float $value) => IngredientUnits::toBase($value, $amount['unit']), $present, $values));

            return IngredientUnits::format($base, IngredientUnits::family($unit), $unit);
        }

        // Not all numbers: listed side by side, the unit once when all share it ("2 + etwas Prise").
        if (count(array_unique(array_column($present, 'unit'))) === 1) {
            return trim(implode(' + ', array_unique(array_column($present, 'amount'))).' '.$present[0]['unit']);
        }

        return implode(' + ', array_unique(array_map(fn (array $amount) => trim($amount['amount'].' '.$amount['unit']), $present)));
    }
}
