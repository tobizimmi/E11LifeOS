<?php

namespace App\Jobs;

use App\Models\Trip;
use App\Models\TripStop;
use App\Support\Life\PlaceFinder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Loads the sights, restaurants, beaches … of a trip's area from OpenStreetMap in the background and
 * keeps them in trip_places, so the trip map searches and shows them at once.
 * The area is the one chosen on the map, otherwise the destination plus the planned places.
 */
class LoadTripPlaces implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 2;

    /** Areas are kept between these sizes (degrees): a whole country would be too much. */
    private const MIN_SPAN = 0.08;

    private const MAX_SPAN = 1.5;

    public function __construct(public int $tripId) {}

    public static function lockKey(int $tripId): string
    {
        return 'trip-places:'.$tripId;
    }

    /**
     * Starts loading unless it is already running for this trip.
     */
    public static function start(Trip $trip): bool
    {
        if (! Cache::add(self::lockKey($trip->id), true, now()->addMinutes(10))) {
            return false;
        }

        self::dispatch($trip->id);

        return true;
    }

    public function handle(): void
    {
        $trip = Trip::find($this->tripId);

        if (! $trip) {
            return;
        }

        $area = self::areaFor($trip);
        $places = $area ? PlaceFinder::areaPlaces($area) : [];

        if ($places === null) {
            return; // Not reachable: the lock stays for ten minutes, then the next visit of the map tries again.
        }

        try {
            DB::transaction(function () use ($trip, $area, $places) {
                $trip->places()->delete();
                $now = now();

                foreach (array_chunk($places, 500) as $chunk) {
                    $trip->places()->insert(array_map(fn (array $place) => array_merge($place, [
                        'trip_id' => $trip->id,
                        'info' => $place['info'] ? json_encode($place['info']) : null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]), collect($chunk)->unique('osm_id')->values()->all()));
                }

                $trip->forceFill(['map_area' => $area, 'places_loaded_at' => $now])->save();
            });
        } finally {
            Cache::forget(self::lockKey($this->tripId));
        }
    }

    /**
     * The area of the trip (south, west, north, east) or null without destination and places.
     *
     * @return ?array{0: float, 1: float, 2: float, 3: float}
     */
    public static function areaFor(Trip $trip): ?array
    {
        if (is_array($trip->map_area) && count($trip->map_area) === 4) {
            return self::clamp(array_map('floatval', array_values($trip->map_area)));
        }

        $boxes = [];

        if ($trip->destination && ($box = PlaceFinder::area($trip->destination))) {
            $boxes[] = $box;
        }

        $positions = $trip->stops()->whereNotNull('lat')->get()->filter(fn (TripStop $stop) => $stop->hasPosition());

        if ($positions->isNotEmpty()) {
            $boxes[] = [(float) $positions->min('lat') - 0.02, (float) $positions->min('lng') - 0.02, (float) $positions->max('lat') + 0.02, (float) $positions->max('lng') + 0.02];
        }

        if ($boxes === []) {
            return null;
        }

        return self::clamp([min(array_column($boxes, 0)), min(array_column($boxes, 1)), max(array_column($boxes, 2)), max(array_column($boxes, 3))]);
    }

    /**
     * @param  array<int, float>  $box
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private static function clamp(array $box): array
    {
        [$south, $west, $north, $east] = $box;
        $centerLat = ($south + $north) / 2;
        $centerLng = ($west + $east) / 2;
        $halfLat = min(max(($north - $south) / 2, self::MIN_SPAN / 2), self::MAX_SPAN / 2);
        $halfLng = min(max(($east - $west) / 2, self::MIN_SPAN / 2), self::MAX_SPAN / 2);

        return [round($centerLat - $halfLat, 4), round($centerLng - $halfLng, 4), round($centerLat + $halfLat, 4), round($centerLng + $halfLng, 4)];
    }
}
