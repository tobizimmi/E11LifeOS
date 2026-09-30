<?php

namespace App\Models;

use Database\Factories\RealEstateAnalysisFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved profitability calculation (purchase, financing, rent, costs, tax) of a property.
 *
 * @property int $id
 * @property int $household_id
 * @property ?int $created_by
 * @property ?int $real_estate_id
 * @property string $title
 * @property array<string, float|int|string> $inputs
 */
#[Fillable(['household_id', 'created_by', 'real_estate_id', 'title', 'inputs'])]
class RealEstateAnalysis extends Model
{
    /** @use HasFactory<RealEstateAnalysisFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'inputs' => 'encrypted:array',
        ];
    }

    /**
     * @return BelongsTo<RealEstate, $this>
     */
    public function realEstate(): BelongsTo
    {
        return $this->belongsTo(RealEstate::class);
    }
}
