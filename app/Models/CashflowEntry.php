<?php

namespace App\Models;

use Database\Factories\CashflowEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line of the member's own Rich Dad financial statement: an income or expense per month, or an
 * asset or liability with its value and the money it brings in or costs per month ("amount").
 */
#[Fillable(['user_id', 'kind', 'category', 'label', 'amount', 'value', 'productive', 'notes', 'position'])]
class CashflowEntry extends Model
{
    /** @use HasFactory<CashflowEntryFactory> */
    use HasFactory;

    public const INCOME = 'income';

    public const EXPENSE = 'expense';

    public const ASSET = 'asset';

    public const LIABILITY = 'liability';

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'value' => 'float',
            'productive' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * Categories per kind with their label (and for income the cashflow quadrant).
     *
     * @return array<string, array<string, string>>
     */
    public static function categories(): array
    {
        return [
            self::INCOME => [
                'earned' => __('Salary (employee)'),
                'self_employed' => __('Self-employed work'),
                'business' => __('Business that runs without you'),
                'portfolio' => __('Portfolio (dividends, interest)'),
                'passive' => __('Passive (rent, royalties, licences)'),
                'other' => __('Other income'),
            ],
            self::EXPENSE => [
                'housing' => __('Housing'),
                'living' => __('Cost of living'),
                'insurance' => __('Insurance'),
                'mobility' => __('Mobility'),
                'taxes' => __('Taxes & fees'),
                'children' => __('Children'),
                'leisure' => __('Leisure & holidays'),
                'other' => __('Other expenses'),
            ],
            self::ASSET => [
                'stocks' => __('Shares, ETFs, bonds'),
                'real_estate' => __('Rental property'),
                'business' => __('Business'),
                'cash' => __('Savings & cash'),
                'other' => __('Other asset'),
            ],
            self::LIABILITY => [
                'mortgage' => __('Mortgage'),
                'consumer' => __('Consumer loan'),
                'car' => __('Car loan / leasing'),
                'student' => __('Student loan'),
                'other' => __('Other debt'),
            ],
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function categoryLabel(): string
    {
        return self::categories()[$this->kind][$this->category] ?? $this->category;
    }
}
