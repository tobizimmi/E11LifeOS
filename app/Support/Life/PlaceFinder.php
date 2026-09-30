<?php

namespace App\Support\Life;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Places for the trip map from OpenStreetMap: search (Nominatim), the address of a point on the map
 * and sights, restaurants … nearby (Overpass). Called from the server, cached, without API key.
 */
class PlaceFinder
{
    private const TIMEOUT = 8;

    /**
     * @param  ?array{0: float, 1: float, 2: float, 3: float}  $bounds  south, west, north, east of the visible map (results there first)
     * @return list<array{name: string, address: string, lat: float, lng: float, kind: string}>
     */
    public static function search(string $query, ?array $bounds = null): array
    {
        $query = trim(mb_substr($query, 0, 150));

        if (mb_strlen($query) < 2) {
            return [];
        }

        $parameters = ['format' => 'jsonv2', 'q' => $query, 'limit' => 8, 'accept-language' => 'de'];

        if ($bounds !== null) {
            [$south, $west, $north, $east] = array_map(fn ($value) => round((float) $value, 3), $bounds);
            $parameters['viewbox'] = "{$west},{$north},{$east},{$south}";
        }

        return Cache::remember('places:search:'.md5(json_encode($parameters)), now()->addDays(7), function () use ($parameters) {
            $results = self::get(config('services.maps.nominatim_url').'/search', $parameters) ?? [];

            return array_values(array_filter(array_map(fn (array $result) => self::fromNominatim($result), $results)));
        });
    }

    /**
     * Name and address of a point on the map; null if nothing is known there.
     *
     * @return ?array{name: string, address: string, lat: float, lng: float, kind: string}
     */
    public static function reverse(float $lat, float $lng): ?array
    {
        $parameters = ['format' => 'jsonv2', 'lat' => round($lat, 5), 'lon' => round($lng, 5), 'zoom' => 18, 'accept-language' => 'de'];

        return Cache::remember('places:reverse:'.md5(json_encode($parameters)), now()->addDays(30), function () use ($parameters, $lat, $lng) {
            $result = self::get(config('services.maps.nominatim_url').'/reverse', $parameters);
            $place = is_array($result) && isset($result['lat']) ? self::fromNominatim($result) : null;

            return $place ? ['lat' => $lat, 'lng' => $lng] + $place : null;
        });
    }

    /**
     * Coordinates for an address typed into an entry (best match); null if not found.
     *
     * @return ?array{lat: float, lng: float}
     */
    public static function locate(string $address): ?array
    {
        $first = self::search($address)[0] ?? null;

        return $first ? ['lat' => $first['lat'], 'lng' => $first['lng']] : null;
    }

    /**
     * Sights, museums, viewpoints, restaurants, cafés and bars in the visible map area (at most 60).
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $bounds  south, west, north, east
     * @return list<array{name: string, address: string, lat: float, lng: float, kind: string}>
     */
    public static function nearby(array $bounds): array
    {
        [$south, $west, $north, $east] = array_map(fn ($value) => round((float) $value, 3), $bounds);

        // Only for a city-sized area: a whole country would be slow and useless.
        if (abs($north - $south) > 0.25 || abs($east - $west) > 0.4) {
            return [];
        }

        $box = "{$south},{$west},{$north},{$east}";
        $query = "[out:json][timeout:15];(node[\"tourism\"~\"^(attraction|museum|viewpoint|gallery|zoo|theme_park)$\"][\"name\"]({$box});node[\"historic\"~\"^(monument|castle|memorial|ruins)$\"][\"name\"]({$box});node[\"amenity\"~\"^(restaurant|cafe|bar|pub|ice_cream)$\"][\"name\"]({$box}););out 60;";

        return Cache::remember('places:nearby:'.md5($box), now()->addDay(), function () use ($query) {
            try {
                $response = Http::withHeaders(self::headers())->timeout(self::TIMEOUT + 10)->asForm()->post(config('services.maps.overpass_url'), ['data' => $query]);
            } catch (Throwable) {
                return [];
            }

            $elements = $response->successful() ? (array) $response->json('elements', []) : [];

            return array_values(array_filter(array_map(function (array $element): ?array {
                $tags = (array) ($element['tags'] ?? []);

                if (! isset($element['lat'], $element['lon'], $tags['name'])) {
                    return null;
                }

                return [
                    'name' => mb_substr((string) $tags['name'], 0, 150),
                    'address' => trim(implode(' ', array_filter([$tags['addr:street'] ?? null, $tags['addr:housenumber'] ?? null]))),
                    'lat' => (float) $element['lat'],
                    'lng' => (float) $element['lon'],
                    'kind' => self::kindFor((string) ($tags['tourism'] ?? $tags['historic'] ?? ''), (string) ($tags['amenity'] ?? '')),
                ];
            }, $elements)));
        });
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private static function get(string $url, array $parameters): ?array
    {
        try {
            $response = Http::withHeaders(self::headers())->timeout(self::TIMEOUT)->get($url, $parameters);
        } catch (Throwable) {
            return null;
        }

        return $response->successful() ? (array) $response->json() : null;
    }

    /**
     * OpenStreetMap asks every app to identify itself.
     *
     * @return array<string, string>
     */
    private static function headers(): array
    {
        return ['User-Agent' => config('app.name').' trip planner ('.config('app.url').')'];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return ?array{name: string, address: string, lat: float, lng: float, kind: string}
     */
    private static function fromNominatim(array $result): ?array
    {
        if (! isset($result['lat'], $result['lon'])) {
            return null;
        }

        $parts = array_map('trim', explode(',', (string) ($result['display_name'] ?? '')));
        $name = trim((string) ($result['name'] ?? '')) ?: $parts[0];

        if ($name === '') {
            return null;
        }

        $address = implode(', ', array_slice(array_values(array_filter($parts, fn (string $part) => $part !== $name)), 0, 3));

        return [
            'name' => mb_substr($name, 0, 150),
            'address' => mb_substr($address, 0, 255),
            'lat' => (float) $result['lat'],
            'lng' => (float) $result['lon'],
            'kind' => self::kindFor((string) ($result['category'] ?? ''), (string) ($result['type'] ?? '')),
        ];
    }

    /**
     * Type of a trip entry for an OpenStreetMap category/type.
     */
    public static function kindFor(string $category, string $type): string
    {
        return match (true) {
            in_array($type, ['restaurant', 'cafe', 'bar', 'pub', 'fast_food', 'ice_cream', 'biergarten'], true) => 'food',
            in_array($type, ['hotel', 'hostel', 'guest_house', 'apartment', 'motel', 'camp_site'], true) => 'accommodation',
            in_array($category, ['railway', 'aeroway', 'public_transport'], true) || in_array($type, ['station', 'aerodrome', 'ferry_terminal', 'bus_station'], true) => 'transport',
            $category === 'shop' || in_array($type, ['marketplace', 'mall'], true) => 'shopping',
            in_array($category, ['leisure', 'natural'], true) || in_array($type, ['zoo', 'theme_park', 'beach', 'park'], true) => 'activity',
            default => 'sight',
        };
    }
}
