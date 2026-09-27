<?php

namespace App\Support;

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;

/**
 * Optional modules a household can switch on or off, and switch off for single members
 * (Household page).
 */
class Addons
{
    public const RECIPES = 'recipes';

    public const PORTFOLIO = 'portfolio';

    public const FINANCE = 'finance';

    public const BUSINESS = 'business';

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
            self::FINANCE => [
                'label' => __('Finances'),
                'icon' => '💶',
                'description' => __('Bank accounts, statement import, categories, reports and savings analysis for the household.'),
                'default' => true,
            ],
            self::BUSINESS => [
                'label' => __('Business'),
                'icon' => '💼',
                'description' => __('Quotes, invoices, customers, receipts, time tracking and tax reports for a small business.'),
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

    /**
     * Whether the user may use the module: switched on for their household and not switched
     * off for them by the owner.
     */
    public static function allowed(?User $user, string $addon): bool
    {
        $household = $user?->currentHousehold();

        if (! self::enabled($household, $addon)) {
            return false;
        }

        $membership = HouseholdMember::where('household_id', $household->id)->where('user_id', $user->id)->first();

        return (bool) $membership?->mayUse($addon);
    }

    public static function enabled(?Household $household, string $addon): bool
    {
        if (! $household || ! array_key_exists($addon, self::all())) {
            return false;
        }

        return (bool) ($household->addons[$addon] ?? self::all()[$addon]['default']);
    }
}
