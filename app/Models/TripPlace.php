<?php

namespace App\Models;

use Database\Factories\TripPlaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A sight, restaurant, viewpoint … in the area of a trip, loaded in the background from OpenStreetMap
 * so the trip map finds it at once. "info" holds opening hours, website, cuisine … when known.
 *
 * @property int $id
 * @property int $trip_id
 * @property string $osm_id
 * @property string $name
 * @property string $kind
 * @property ?string $category
 * @property ?string $address
 * @property float $lat
 * @property float $lng
 * @property ?array<string, string> $info
 */
#[Fillable(['trip_id', 'osm_id', 'name', 'kind', 'category', 'address', 'lat', 'lng', 'info'])]
class TripPlace extends Model
{
    /** @use HasFactory<TripPlaceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'info' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Trip, $this>
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }
}
