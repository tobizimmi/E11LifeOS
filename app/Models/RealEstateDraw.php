<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A draw of funds for a property: equity put in or a loan tranche called off, with the planned
 * ("requested") amount against the amount actually disbursed – for the financing controlling.
 */
#[Fillable(['real_estate_id', 'loan_id', 'source', 'label', 'planned_amount', 'planned_on', 'actual_amount', 'actual_on', 'notes', 'position'])]
class RealEstateDraw extends Model
{
    public const SOURCES = ['equity', 'loan'];

    protected function casts(): array
    {
        return [
            'planned_amount' => 'float',
            'actual_amount' => 'float',
            'planned_on' => 'date',
            'actual_on' => 'date',
            'position' => 'integer',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function sourceLabels(): array
    {
        return ['equity' => __('Equity'), 'loan' => __('Loan draw')];
    }

    public function realEstate(): BelongsTo
    {
        return $this->belongsTo(RealEstate::class);
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(RealEstateLoan::class, 'loan_id');
    }

    /**
     * How much of the planned amount has already been disbursed (0–100, 0 when nothing is planned).
     */
    public function utilisation(): int
    {
        if ($this->planned_amount <= 0) {
            return $this->actual_amount > 0 ? 100 : 0;
        }

        return (int) min(100, round($this->actual_amount / $this->planned_amount * 100));
    }
}
