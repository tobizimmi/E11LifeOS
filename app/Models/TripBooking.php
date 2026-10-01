<?php

namespace App\Models;

use Database\Factories\TripBookingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A flight, train, accommodation, rental car, activity or expense of a trip.
 *
 * @property string $kind
 * @property string $title
 * @property ?string $provider
 * @property ?Carbon $starts_at
 * @property ?Carbon $ends_at
 * @property ?string $reference
 * @property ?float $cost in euros
 * @property ?string $currency original currency (null = euros)
 * @property ?float $foreign_amount amount in the original currency
 * @property ?float $exchange_rate units of the currency per euro
 */
#[Fillable(['trip_id', 'kind', 'title', 'provider', 'starts_at', 'ends_at', 'reference', 'cost', 'currency', 'foreign_amount', 'exchange_rate', 'paid', 'notes'])]
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
            'foreign_amount' => 'float',
            'exchange_rate' => 'float',
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
