<?php

namespace App\Support\Finance;

use App\Models\Transaction;
use App\Models\User;

/**
 * How the finance categories count in the cashflow analysis: as salary, portfolio or passive
 * income, other income, an expense, a refund (lowers the expenses) or not at all. Each member can
 * change the role per category; with "manual only" just the categories chosen here are taken over.
 */
class CashflowCategories
{
    public const INCOME_ROLES = ['earned', 'portfolio', 'passive', 'other'];

    public const ROLES = ['earned', 'portfolio', 'passive', 'other', 'expense', 'refund', 'ignore'];

    /**
     * @return array<string, string>
     */
    public static function roles(): array
    {
        return [
            'earned' => __('Salary (earned income)'),
            'portfolio' => __('Portfolio income'),
            'passive' => __('Passive income'),
            'other' => __('Other income'),
            'expense' => __('Expense'),
            'refund' => __('Refund (lowers expenses)'),
            'ignore' => __('Not counted'),
        ];
    }

    public static function defaultRole(string $category): string
    {
        return match (true) {
            $category === 'salary' => 'earned',
            $category === 'refunds' => 'refund',
            in_array($category, ['savings', FinanceCategories::TRANSFER], true) => 'ignore',
            FinanceCategories::group($category) === 'income' => 'other',
            default => 'expense',
        };
    }

    /**
     * The member's own choices (category => role).
     *
     * @return array<string, string>
     */
    public static function overrides(User $user): array
    {
        $overrides = [];

        foreach ((array) ($user->cashflow_categories ?? []) as $category => $role) {
            if (FinanceCategories::exists((string) $category) && in_array($role, self::ROLES, true)) {
                $overrides[(string) $category] = (string) $role;
            }
        }

        return $overrides;
    }

    public static function roleFor(User $user, string $category): string
    {
        return self::overrides($user)[$category] ?? ($user->cashflow_manual_only ? 'ignore' : self::defaultRole($category));
    }

    /**
     * Categories that count at all (for the database query).
     *
     * @return list<string>
     */
    public static function counted(User $user): array
    {
        return array_values(array_filter(array_keys(FinanceCategories::all()), fn (string $category) => self::roleFor($user, $category) !== 'ignore'));
    }

    public static function set(User $user, string $category, string $role): void
    {
        abort_unless(FinanceCategories::exists($category) && in_array($role, self::ROLES, true), 422);
        $overrides = self::overrides($user);

        if ($role === ($user->cashflow_manual_only ? 'ignore' : self::defaultRole($category))) {
            unset($overrides[$category]);
        } else {
            $overrides[$category] = $role;
        }

        $user->update(['cashflow_categories' => $overrides ?: null]);
    }

    /**
     * Per category of the last months: average per month and the role it has.
     *
     * @param  iterable<Transaction>  $transactions
     * @return list<array{category: string, label: string, icon: string, monthly: float, role: string, changed: bool}>
     */
    public static function overview(User $user, iterable $transactions, int $months): array
    {
        $sums = [];

        foreach ($transactions as $transaction) {
            $sums[$transaction->category ?? FinanceCategories::OTHER] = ($sums[$transaction->category ?? FinanceCategories::OTHER] ?? 0) + $transaction->amount;
        }

        $overrides = self::overrides($user);
        $rows = [];

        foreach ($sums as $category => $sum) {
            $rows[] = [
                'category' => $category,
                'label' => FinanceCategories::label($category),
                'icon' => FinanceCategories::icon($category),
                'monthly' => round($sum / max(1, $months), 2),
                'role' => self::roleFor($user, $category),
                'changed' => array_key_exists($category, $overrides),
            ];
        }

        usort($rows, fn (array $a, array $b) => abs($b['monthly']) <=> abs($a['monthly']));

        return $rows;
    }
}
