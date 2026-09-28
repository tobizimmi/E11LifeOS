<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A planned cost of a property: a purchase item or a renovation trade with schedule.
 */
#[Fillable(['real_estate_id', 'phase', 'trade', 'title', 'planned_amount', 'status', 'start_on', 'end_on', 'contractor', 'energy_measure', 'notes', 'position'])]
class RealEstateBudgetItem extends Model
{
    public const PHASES = ['purchase', 'renovation', 'other'];

    public const STATUSES = ['planned', 'quoted', 'commissioned', 'in_progress', 'done'];

    public const TRADES = [
        'demolition', 'structure', 'roof', 'facade', 'insulation', 'windows', 'heating', 'plumbing', 'bathroom', 'electrical',
        'drywall', 'plaster', 'floors', 'painting', 'kitchen', 'carpentry', 'outdoor', 'energy_advice', 'planning', 'other',
    ];

    /**
     * Trades that usually count as energy-efficiency measures (BEG, § 35c EStG).
     */
    public const ENERGY_TRADES = ['roof', 'facade', 'insulation', 'windows', 'heating', 'energy_advice'];

    protected function casts(): array
    {
        return [
            'planned_amount' => 'float',
            'energy_measure' => 'boolean',
            'start_on' => 'date',
            'end_on' => 'date',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function tradeLabels(): array
    {
        return [
            'demolition' => __('Demolition & disposal'), 'structure' => __('Shell & structure'), 'roof' => __('Roof'), 'facade' => __('Facade'),
            'insulation' => __('Insulation'), 'windows' => __('Windows & doors'), 'heating' => __('Heating'), 'plumbing' => __('Plumbing'),
            'bathroom' => __('Bathroom'), 'electrical' => __('Electrics'), 'drywall' => __('Drywall'), 'plaster' => __('Plaster'),
            'floors' => __('Floors'), 'painting' => __('Painting'), 'kitchen' => __('Kitchen'), 'carpentry' => __('Carpentry'),
            'outdoor' => __('Garden & outdoor'), 'energy_advice' => __('Energy advice'), 'planning' => __('Architect & planning'), 'other' => __('Other'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function phaseLabels(): array
    {
        return ['purchase' => __('Purchase'), 'renovation' => __('Renovation'), 'other' => __('Other')];
    }

    /**
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return ['planned' => __('Planned'), 'quoted' => __('Quote received'), 'commissioned' => __('Commissioned'), 'in_progress' => __('In progress'), 'done' => __('Done')];
    }

    public function realEstate(): BelongsTo
    {
        return $this->belongsTo(RealEstate::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(RealEstateInvoice::class, 'budget_item_id');
    }
}
