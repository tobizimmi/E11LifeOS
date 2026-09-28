<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A value of the property at a date (own estimate, appraisal, offer) for the value development.
 */
#[Fillable(['real_estate_id', 'valued_on', 'value', 'source', 'notes'])]
class RealEstateValuation extends Model
{
    public const SOURCES = ['estimate', 'appraisal', 'offer', 'portal'];

    protected function casts(): array
    {
        return [
            'valued_on' => 'date',
            'value' => 'float',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function sourceLabels(): array
    {
        return [
            'estimate' => __('Own estimate'),
            'appraisal' => __('Appraisal'),
            'offer' => __('Purchase offer'),
            'portal' => __('Online valuation'),
        ];
    }

    public function realEstate(): BelongsTo
    {
        return $this->belongsTo(RealEstate::class);
    }
}
