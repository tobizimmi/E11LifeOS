<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Yearly utility statement (Nebenkostenabrechnung): the allocatable operating costs of the
 * property (§ 2 BetrKV) with their allocation key.
 */
#[Fillable(['real_estate_id', 'year', 'period_start', 'period_end', 'costs', 'total_area', 'total_units', 'sent_on', 'notes'])]
class RealEstateUtilityStatement extends Model
{
    /**
     * Allocation keys: living area, persons (person days) or units.
     */
    public const KEYS = ['area', 'persons', 'units'];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'costs' => 'array',
            'total_area' => 'float',
            'total_units' => 'integer',
            'sent_on' => 'date',
        ];
    }

    /**
     * Operating cost types of § 2 BetrKV with their usual allocation key.
     *
     * @return array<string, array{label: string, key: string}>
     */
    public static function costTypes(): array
    {
        return [
            'property_tax' => ['label' => __('Property tax'), 'key' => 'area'],
            'water' => ['label' => __('Water & sewage'), 'key' => 'persons'],
            'heating' => ['label' => __('Heating & hot water'), 'key' => 'area'],
            'elevator' => ['label' => __('Elevator'), 'key' => 'units'],
            'street_cleaning' => ['label' => __('Street cleaning & waste'), 'key' => 'persons'],
            'cleaning' => ['label' => __('Building cleaning & pest control'), 'key' => 'area'],
            'garden' => ['label' => __('Garden maintenance'), 'key' => 'area'],
            'lighting' => ['label' => __('Lighting (common areas)'), 'key' => 'area'],
            'chimney' => ['label' => __('Chimney sweep'), 'key' => 'units'],
            'insurance' => ['label' => __('Building insurance'), 'key' => 'area'],
            'caretaker' => ['label' => __('Caretaker'), 'key' => 'area'],
            'cable' => ['label' => __('Cable TV / antenna'), 'key' => 'units'],
            'other' => ['label' => __('Other operating costs'), 'key' => 'area'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function keyLabels(): array
    {
        return ['area' => __('Living area'), 'persons' => __('Persons'), 'units' => __('Units')];
    }

    public function totalCosts(): float
    {
        return round(collect($this->costs ?? [])->sum(fn (array $cost) => (float) ($cost['amount'] ?? 0)), 2);
    }

    public function realEstate(): BelongsTo
    {
        return $this->belongsTo(RealEstate::class);
    }
}
