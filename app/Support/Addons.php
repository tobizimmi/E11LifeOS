<?php

namespace App\Support;

use App\Models\Household;

/**
 * Optional modules a household can switch on or off (Household page).
 */
class Addons
{
    public const RECIPES = 'recipes';

    public const PORTFOLIO = 'portfolio';

    /**
     * @return array<string, array{label: string, icon: string, description: string, default: bool}>
     */
    public static function all(): array
    {
        return [
            self::RECIPES => [
                'label' => __('Recipes'),
                'icon' => '🍳',
                'description' => __('Recipe collection with portions, cooking steps and a shopping list button.'),
                'default' => true,
            ],
            self::PORTFOLIO => [
                'label' => __('Portfolio'),
                'icon' => '📈',
                'description' => __('Personal portfolio manager: securities accounts, live prices, broker imports and watchlist. Every member only sees their own portfolios.'),
                'default' => true,
            ],
        ];
    }

    public static function enabled(?Household $household, string $addon): bool
    {
        if (! $household || ! array_key_exists($addon, self::all())) {
            return false;
        }

        return (bool) ($household->addons[$addon] ?? self::all()[$addon]['default']);
    }
}
