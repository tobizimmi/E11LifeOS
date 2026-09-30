<?php

namespace App\Support\Business;

use App\Models\BookkeepingEntry;

/**
 * Receipt categories of the small business and the Anlage EÜR position each one counts towards.
 * Line numbers of the form change yearly, so positions are named, not numbered.
 */
class BookkeepingCategories
{
    public const VAT_REFUND = 'vat_refund';

    public const TAX_PAYMENT = 'tax_payment';

    public const ENTERTAINMENT = 'entertainment';

    /**
     * Share of entertainment costs (Bewirtung) that is deductible (§ 4 Abs. 5 Nr. 2 EStG).
     */
    public const ENTERTAINMENT_DEDUCTIBLE = 0.7;

    /**
     * @return array<string, array{label: string, icon: string, kind: string, line: string, hint?: string}>
     */
    public static function all(): array
    {
        return [
            'goods' => ['label' => __('Goods & materials'), 'icon' => '📦', 'kind' => BookkeepingEntry::EXPENSE, 'line' => 'goods'],
            'third_party' => ['label' => __('Subcontractors'), 'icon' => '🤝', 'kind' => BookkeepingEntry::EXPENSE, 'line' => 'third_party'],
            'personnel' => ['label' => __('Staff'), 'icon' => '👷', 'kind' => BookkeepingEntry::EXPENSE, 'line' => 'personnel'],
            'low_value_assets' => ['label' => __('Equipment up to 800 € net'), 'icon' => '💻', 'kind' => BookkeepingEntry::EXPENSE, 'line' => 'low_value_assets', 'hint' => __('Low-value assets (GWG) up to 800 € net are deducted in full in the year of purchase.')],
            'depreciation' => ['label' => __('Depreciation (AfA)'), 'icon' => '📉', 'kind' => BookkeepingEntry::EXPENSE, 'line' => 'depreciation', 'hint' => __('For assets over 800 € net only the yearly depreciation counts – enter the amount for this year.')],
            'rent' => ['label' => __('Rent for business premises'), 'icon' => '🏢', 'kind' => BookkeepingEntry::EXPENSE, 'line' => 'rent'],
            'home_office' => ['label' => __('Home office'), 'icon' => '🏠', 'kind' => BookkeepingEntry::EXPENSE, 'line' => 'home_office', 'hint' => __('Home office flat rate: 6 € per day, at most 1,260 € per year.')],
            'telecom' => ['label' => __('Phone & internet'), 'icon' => '📱', 'kind' => BookkeepingEntry::EXPENSE, 'line' => 'telecom', 'hint' => __('Only the business share counts, e.g. 50 % of a private contract.')],
            'travel' => ['label' => __('Travel & accommodation'), 'icon' => '🧳', 'kind' => BookkeepingEntry::EXPENSE, 'line' => 'travel'],
            'vehicle' => ['label' => __('Car & travel costs'), 'icon' => '🚗', 'kind' => BookkeepingEntry::EXPENSE, 'line' => 'vehicle', 'hint' => __('Business trips with a private car: 0.30 € per kilometre driven.')],
            'advertising' => ['label' => __('Advertising & marketing'), 'icon' => '📣', 'kind' => BookkeepingEntry::EXPENSE, 'line' => 'advertising'],
            'insurance' => ['label' => __('Insurance, fees & contributions'), 'icon' => '🛡️', 'kind' => BookkeepingEntry::EXPENSE, 'line' => 'insurance'],
            'training' => ['label' => __('Training & literature'), 'icon' => '📚', 'kind' => BookkeepingEntry::EXPENSE, 'line' => 'training'],
            'consulting' => ['label' => __('Tax advice & bookkeeping'), 'icon' => '🧾', 'kind' => BookkeepingEntry::EXPENSE, 'line' => 'consulting'],
            self::ENTERTAINMENT => ['label' => __('Business meals'), 'icon' => '🍽️', 'kind' => BookkeepingEntry::EXPENSE, 'line' => 'entertainment', 'hint' => __('Only 70 % are deductible; keep the receipt with occasion and participants.')],
            'gifts' => ['label' => __('Gifts to business partners'), 'icon' => '🎁', 'kind' => BookkeepingEntry::EXPENSE, 'line' => 'gifts', 'hint' => __('Deductible up to 50 € per recipient and year.')],
            'office' => ['label' => __('Office supplies & postage'), 'icon' => '✏️', 'kind' => BookkeepingEntry::EXPENSE, 'line' => 'other_expenses'],
            'software' => ['label' => __('Software & web services'), 'icon' => '🖥️', 'kind' => BookkeepingEntry::EXPENSE, 'line' => 'other_expenses'],
            'bank_fees' => ['label' => __('Bank fees'), 'icon' => '🏦', 'kind' => BookkeepingEntry::EXPENSE, 'line' => 'other_expenses'],
            'other_expense' => ['label' => __('Other business expenses'), 'icon' => '🧩', 'kind' => BookkeepingEntry::EXPENSE, 'line' => 'other_expenses'],
            self::TAX_PAYMENT => ['label' => __('VAT paid to the tax office'), 'icon' => '🏛️', 'kind' => BookkeepingEntry::EXPENSE, 'line' => 'vat_payments'],
            'sales' => ['label' => __('Sales without invoice'), 'icon' => '💶', 'kind' => BookkeepingEntry::INCOME, 'line' => 'revenue', 'hint' => __('e.g. cash sales at a market or platform payouts.')],
            'other_income' => ['label' => __('Other business income'), 'icon' => '➕', 'kind' => BookkeepingEntry::INCOME, 'line' => 'revenue'],
            self::VAT_REFUND => ['label' => __('VAT refunded by the tax office'), 'icon' => '🏛️', 'kind' => BookkeepingEntry::INCOME, 'line' => 'vat_refunded'],
        ];
    }

    /**
     * @return array<string, array{label: string, icon: string, kind: string, line: string, hint?: string}>
     */
    public static function forKind(string $kind): array
    {
        return array_filter(self::all(), fn (array $category) => $category['kind'] === $kind);
    }

    public static function exists(string $key, ?string $kind = null): bool
    {
        $category = self::all()[$key] ?? null;

        return $category !== null && ($kind === null || $category['kind'] === $kind);
    }

    public static function label(string $key): string
    {
        return self::all()[$key]['label'] ?? $key;
    }

    public static function icon(string $key): string
    {
        return self::all()[$key]['icon'] ?? '🧩';
    }

    public static function line(string $key): string
    {
        return self::all()[$key]['line'] ?? 'other_expenses';
    }

    /**
     * Positions of the Anlage EÜR in form order.
     *
     * @return array<string, array{label: string, section: string}>
     */
    public static function euerLines(): array
    {
        return [
            'small_business_revenue' => ['label' => __('Business income as a VAT small business (§ 19 UStG)'), 'section' => 'income'],
            'taxable_revenue' => ['label' => __('Business income subject to VAT (net)'), 'section' => 'income'],
            'tax_free_revenue' => ['label' => __('VAT-free or non-taxable business income'), 'section' => 'income'],
            'vat_received' => ['label' => __('VAT received'), 'section' => 'income'],
            'vat_refunded' => ['label' => __('VAT refunded by the tax office'), 'section' => 'income'],
            'goods' => ['label' => __('Goods, raw materials and supplies'), 'section' => 'expense'],
            'third_party' => ['label' => __('Services purchased from third parties'), 'section' => 'expense'],
            'personnel' => ['label' => __('Expenses for own staff'), 'section' => 'expense'],
            'depreciation' => ['label' => __('Depreciation (AfA)'), 'section' => 'expense'],
            'low_value_assets' => ['label' => __('Low-value assets (GWG)'), 'section' => 'expense'],
            'rent' => ['label' => __('Rent for business premises'), 'section' => 'expense'],
            'home_office' => ['label' => __('Home office / daily flat rate'), 'section' => 'expense'],
            'telecom' => ['label' => __('Telecommunication'), 'section' => 'expense'],
            'travel' => ['label' => __('Accommodation and travel expenses'), 'section' => 'expense'],
            'vehicle' => ['label' => __('Vehicle and travel costs'), 'section' => 'expense'],
            'advertising' => ['label' => __('Advertising'), 'section' => 'expense'],
            'insurance' => ['label' => __('Contributions, fees, levies and insurance'), 'section' => 'expense'],
            'training' => ['label' => __('Further training and specialist literature'), 'section' => 'expense'],
            'consulting' => ['label' => __('Legal and tax advice, bookkeeping'), 'section' => 'expense'],
            'entertainment' => ['label' => __('Business meals (deductible 70 %)'), 'section' => 'expense'],
            'gifts' => ['label' => __('Gifts'), 'section' => 'expense'],
            'other_expenses' => ['label' => __('Other fully deductible business expenses'), 'section' => 'expense'],
            'vat_paid' => ['label' => __('Input VAT paid'), 'section' => 'expense'],
            'vat_payments' => ['label' => __('VAT paid to the tax office'), 'section' => 'expense'],
        ];
    }
}
