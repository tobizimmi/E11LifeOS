<?php

namespace App\Models;

use Database\Factories\MeterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A meter at home (electricity, gas, water, heat, PV feed-in) with its tariff and monthly advance payment.
 */
#[Fillable(['household_id', 'created_by', 'name', 'kind', 'number', 'price_per_unit', 'base_fee_monthly', 'monthly_advance'])]
class Meter extends Model
{
    /** @use HasFactory<MeterFactory> */
    use HasFactory;

    public const KINDS = ['electricity', 'gas', 'water', 'heat', 'pv', 'other'];

    protected function casts(): array
    {
        return [
            'price_per_unit' => 'float',
            'base_fee_monthly' => 'float',
            'monthly_advance' => 'float',
        ];
    }

    /**
     * @return array<string, array{label: string, icon: string, unit: string}>
     */
    public static function kindLabels(): array
    {
        return [
            'electricity' => ['label' => __('Electricity'), 'icon' => '⚡', 'unit' => 'kWh'],
            'gas' => ['label' => __('Gas'), 'icon' => '🔥', 'unit' => 'm³'],
            'water' => ['label' => __('Water'), 'icon' => '💧', 'unit' => 'm³'],
            'heat' => ['label' => __('Heat'), 'icon' => '♨️', 'unit' => 'kWh'],
            'pv' => ['label' => __('PV feed-in'), 'icon' => '☀️', 'unit' => 'kWh'],
            'other' => ['label' => __('Other meter'), 'icon' => '🔢', 'unit' => ''],
        ];
    }

    public function unit(): string
    {
        return self::kindLabels()[$this->kind]['unit'] ?? '';
    }

    public function readings(): HasMany
    {
        return $this->hasMany(MeterReading::class)->orderBy('read_on')->orderBy('id');
    }
}
