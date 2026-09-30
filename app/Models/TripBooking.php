<?php

namespace App\Models;

use Database\Factories\TripBookingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A flight, train, accommodation, rental car, activity or expense of a trip.
 *
 * @property string $kind
 * @property string $title
 * @property ?string $provider
 * @property ?\Illuminate\Support\Carbon $starts_at
 * @property ?\Illuminate\Support\Carbon $ends_at
 * @property ?string $reference
 */
#[Fillable(['trip_id', 'kind', 'title', 'provider', 'starts_at', 'ends_at', 'reference', 'cost', 'paid', 'notes'])]
class TripBooking extends Model
{
    /** @use HasFactory<TripBookingFactory> */
    use HasFactory;

    public const KINDS = ['flight', 'train', 'bus', 'car', 'hotel', 'activity', 'insurance', 'expense', 'other'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'reference' => 'encrypted',
            'notes' => 'encrypted',
            'cost' => 'float',
            'paid' => 'boolean',
        ];
    }

    /**
     * @return array<string, array{label: string, icon: string}>
     */
    public static function kinds(): array
    {
        return [
            'flight' => ['label' => __('Flight'), 'icon' => '✈️'],
            'train' => ['label' => __('Train'), 'icon' => '🚆'],
            'bus' => ['label' => __('Bus / ferry'), 'icon' => '🚌'],
            'car' => ['label' => __('Rental car'), 'icon' => '🚗'],
            'hotel' => ['label' => __('Accommodation'), 'icon' => '🏨'],
            'activity' => ['label' => __('Activity / tickets'), 'icon' => '🎟️'],
            'insurance' => ['label' => __('Travel insurance'), 'icon' => '🛡️'],
            'expense' => ['label' => __('Expense on site'), 'icon' => '💶'],
            'other' => ['label' => __('Other'), 'icon' => '📌'],
        ];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }
}
