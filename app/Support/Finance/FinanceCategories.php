<?php

namespace App\Support\Finance;

/**
 * Spending and income categories. "group" sorts them for the 50/30/20 check:
 * needs (fixed necessities), wants (lifestyle), savings, income, transfer (ignored).
 */
class FinanceCategories
{
    public const OTHER = 'other';

    public const OTHER_INCOME = 'other_income';

    public const TRANSFER = 'transfer';

    /**
     * @return array<string, array{label: string, icon: string, group: string}>
     */
    public static function all(): array
    {
        return [
            'housing' => ['label' => __('Housing & rent'), 'icon' => '🏠', 'group' => 'needs'],
            'energy' => ['label' => __('Electricity & heating'), 'icon' => '⚡', 'group' => 'needs'],
            'groceries' => ['label' => __('Groceries'), 'icon' => '🛒', 'group' => 'needs'],
            'insurance' => ['label' => __('Insurance'), 'icon' => '🛡️', 'group' => 'needs'],
            'mobility' => ['label' => __('Mobility & fuel'), 'icon' => '🚗', 'group' => 'needs'],
            'telecom' => ['label' => __('Phone & internet'), 'icon' => '📱', 'group' => 'needs'],
            'health' => ['label' => __('Health & pharmacy'), 'icon' => '💊', 'group' => 'needs'],
            'children' => ['label' => __('Children & family'), 'icon' => '🧸', 'group' => 'needs'],
            'taxes' => ['label' => __('Taxes & fees'), 'icon' => '🏛️', 'group' => 'needs'],
            'bank_fees' => ['label' => __('Bank fees & interest'), 'icon' => '🏦', 'group' => 'needs'],
            'restaurants' => ['label' => __('Restaurants & delivery'), 'icon' => '🍕', 'group' => 'wants'],
            'subscriptions' => ['label' => __('Subscriptions & streaming'), 'icon' => '📺', 'group' => 'wants'],
            'shopping' => ['label' => __('Shopping'), 'icon' => '🛍️', 'group' => 'wants'],
            'leisure' => ['label' => __('Leisure & hobbies'), 'icon' => '🎾', 'group' => 'wants'],
            'travel' => ['label' => __('Travel'), 'icon' => '✈️', 'group' => 'wants'],
            'pets' => ['label' => __('Pets'), 'icon' => '🐾', 'group' => 'wants'],
            'donations' => ['label' => __('Gifts & donations'), 'icon' => '🎁', 'group' => 'wants'],
            'cash' => ['label' => __('Cash withdrawals'), 'icon' => '💵', 'group' => 'wants'],
            'savings' => ['label' => __('Saving & investing'), 'icon' => '📈', 'group' => 'savings'],
            self::OTHER => ['label' => __('Other expenses'), 'icon' => '📦', 'group' => 'wants'],
            'salary' => ['label' => __('Salary & wages'), 'icon' => '💼', 'group' => 'income'],
            'refunds' => ['label' => __('Refunds'), 'icon' => '↩️', 'group' => 'income'],
            self::OTHER_INCOME => ['label' => __('Other income'), 'icon' => '💰', 'group' => 'income'],
            self::TRANSFER => ['label' => __('Own transfers'), 'icon' => '🔁', 'group' => 'transfer'],
        ];
    }

    public static function label(string $key): string
    {
        return self::all()[$key]['label'] ?? $key;
    }

    public static function icon(string $key): string
    {
        return self::all()[$key]['icon'] ?? '•';
    }

    public static function group(string $key): string
    {
        return self::all()[$key]['group'] ?? 'wants';
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::all());
    }
}
