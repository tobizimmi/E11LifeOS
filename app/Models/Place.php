<?php

namespace App\Models;

use Database\Factories\PlaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A place to remember – a restaurant to try, a favourite café, a sight for the next trip.
 *
 * @property int $id
 * @property int $household_id
 * @property ?int $created_by
 * @property ?int $trip_id
 * @property string $visibility
 * @property string $name
 * @property string $category
 * @property ?string $cuisine
 * @property ?string $address
 * @property ?string $city
 * @property ?string $url
 * @property ?string $phone
 * @property ?int $price_level
 * @property ?int $rating
 * @property ?string $recommended_by
 * @property ?string $notes
 * @property bool $visited
 * @property ?Carbon $last_visited_on
 * @property int $visit_count
 */
#[Fillable(['household_id', 'created_by', 'trip_id', 'visibility', 'name', 'category', 'cuisine', 'address', 'city', 'url', 'phone', 'price_level', 'rating', 'recommended_by', 'notes', 'visited', 'last_visited_on', 'visit_count'])]
class Place extends Model
{
    /** @use HasFactory<PlaceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'visited' => 'boolean',
            'last_visited_on' => 'date',
            'price_level' => 'integer',
            'rating' => 'integer',
            'visit_count' => 'integer',
        ];
    }

    /**
     * @return array<string, array{label: string, icon: string}>
     */
    public static function categories(): array
    {
        return [
            'restaurant' => ['label' => __('Restaurant'), 'icon' => '🍽️'],
            'cafe' => ['label' => __('Café & bakery'), 'icon' => '☕'],
            'bar' => ['label' => __('Bar & nightlife'), 'icon' => '🍸'],
            'sight' => ['label' => __('Sight & excursion'), 'icon' => '🏛️'],
            'nature' => ['label' => __('Nature & hiking'), 'icon' => '🌲'],
            'activity' => ['label' => __('Activity & leisure'), 'icon' => '🎳'],
            'shop' => ['label' => __('Shop'), 'icon' => '🛍️'],
            'hotel' => ['label' => __('Hotel & accommodation'), 'icon' => '🏨'],
            'other' => ['label' => __('Other'), 'icon' => '📍'],
        ];
    }

    /**
     * @return BelongsTo<Trip, $this>
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function isPrivate(): bool
    {
        return $this->visibility === Item::VISIBILITY_PRIVATE;
    }

    /**
     * Opens the place in the map app of the device (search by name and address, no API key).
     */
    public function mapsUrl(): string
    {
        return 'https://www.google.com/maps/search/?api=1&query='.rawurlencode(trim($this->name.' '.$this->address.' '.$this->city));
    }

    /**
     * Shared places of the household and the member's private ones.
     *
     * @param  Builder<Place>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user, int $householdId): void
    {
        $query->where('household_id', $householdId)
            ->where(fn (Builder $query) => $query->where('visibility', Item::VISIBILITY_SHARED)->orWhere('created_by', $user->id));
    }
}
