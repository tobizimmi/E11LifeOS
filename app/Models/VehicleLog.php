<?php

namespace App\Models;

use Database\Factories\VehicleLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a vehicle's log: refuelling or charging, workshop, tyres, inspection, other costs or a trip.
 */
#[Fillable(['vehicle_id', 'created_by', 'kind', 'date', 'odometer', 'amount', 'quantity', 'distance_km', 'purpose', 'destination', 'notes'])]
class VehicleLog extends Model
{
    /** @use HasFactory<VehicleLogFactory> */
    use HasFactory;

    public const KINDS = ['fuel', 'service', 'repair', 'tyres', 'inspection', 'other', 'trip'];

    public const PURPOSES = ['business', 'commute', 'private'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'odometer' => 'integer',
            'amount' => 'float',
            'quantity' => 'float',
            'distance_km' => 'integer',
        ];
    }

    /**
     * @return array<string, array{label: string, icon: string}>
     */
    public static function kindLabels(bool $electric = false): array
    {
        return [
            'fuel' => ['label' => $electric ? __('Charging') : __('Refuelling'), 'icon' => $electric ? '🔌' : '⛽'],
            'service' => ['label' => __('Service'), 'icon' => '🔧'],
            'repair' => ['label' => __('Repair'), 'icon' => '🛠️'],
            'tyres' => ['label' => __('Tyres'), 'icon' => '🛞'],
            'inspection' => ['label' => __('Inspection (TÜV)'), 'icon' => '✅'],
            'other' => ['label' => __('Other costs'), 'icon' => '🧾'],
            'trip' => ['label' => __('Trip'), 'icon' => '🗺️'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function purposeLabels(): array
    {
        return ['business' => __('Business trip'), 'commute' => __('Commute'), 'private' => __('Private trip')];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
