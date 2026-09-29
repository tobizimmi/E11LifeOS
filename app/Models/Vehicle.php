<?php

namespace App\Models;

use Database\Factories\VehicleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A car, motorbike or other vehicle of the household with its inspection and service dates,
 * fixed costs and a log of fuel, workshop visits and trips.
 */
#[Fillable([
    'household_id', 'created_by', 'visibility', 'name', 'make_model', 'plate', 'fuel', 'first_registration', 'purchase_date', 'purchase_price',
    'current_value', 'odometer_start', 'inspection_due', 'service_due', 'service_due_km', 'tyre_reminders', 'tax_yearly', 'insurance_yearly', 'notes',
])]
class Vehicle extends Model
{
    /** @use HasFactory<VehicleFactory> */
    use HasFactory;

    public const FUELS = ['petrol', 'diesel', 'electric', 'hybrid', 'lpg'];

    protected function casts(): array
    {
        return [
            'first_registration' => 'date',
            'purchase_date' => 'date',
            'purchase_price' => 'float',
            'current_value' => 'float',
            'odometer_start' => 'integer',
            'inspection_due' => 'date',
            'service_due' => 'date',
            'service_due_km' => 'integer',
            'tyre_reminders' => 'boolean',
            'tax_yearly' => 'float',
            'insurance_yearly' => 'float',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function fuelLabels(): array
    {
        return ['petrol' => __('Petrol'), 'diesel' => __('Diesel'), 'electric' => __('Electric'), 'hybrid' => __('Hybrid'), 'lpg' => __('LPG / CNG')];
    }

    public function logs(): HasMany
    {
        return $this->hasMany(VehicleLog::class)->orderByDesc('date')->orderByDesc('id');
    }

    /**
     * Shared vehicles of the household and the member's private ones.
     */
    public function scopeVisibleTo(Builder $query, User $user, int $householdId): void
    {
        $query->where('household_id', $householdId)
            ->where(fn (Builder $query) => $query->where('visibility', Item::VISIBILITY_SHARED)->orWhere('created_by', $user->id));
    }

    public function isElectric(): bool
    {
        return $this->fuel === 'electric';
    }
}
