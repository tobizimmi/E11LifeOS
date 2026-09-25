<?php

namespace App\Support;

/**
 * Combines ingredient lines of several recipes into one shopping list:
 * same name and unit are merged, numeric amounts are added up ("200 g" + "300 g" = "500 g"),
 * anything else is listed side by side ("1 Prise + etwas").
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

            $key = mb_strtolower($name).'|'.mb_strtolower(trim($line['unit']));
            $groups[$key] ??= ['name' => $name, 'unit' => trim($line['unit']), 'amounts' => [], 'recipes' => []];
            $groups[$key]['amounts'][] = trim($line['amount']);
            $groups[$key]['recipes'][] = $line['recipe'];
        }

        return collect($groups)
            ->map(fn (array $group) => [
                'name' => $group['name'],
                'quantity' => self::quantity($group['amounts'], $group['unit']),
                'recipes' => array_values(array_unique($group['recipes'])),
            ])
            ->sortBy(fn (array $item) => mb_strtolower($item['name']))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $amounts
     */
    private static function quantity(array $amounts, string $unit): string
    {
        $present = array_values(array_filter($amounts, fn (string $amount) => $amount !== ''));
        $values = array_map(IngredientAmount::parse(...), $present);

        $amount = match (true) {
            $present === [] => '',
            ! in_array(null, $values, true) => IngredientAmount::format(array_sum($values)),
            default => implode(' + ', array_unique($present)),
        };

        return trim($amount.' '.$unit);
    }
}
