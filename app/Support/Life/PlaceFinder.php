<?php

namespace App\Support\Life;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Places for the trip map from OpenStreetMap: search as you type (Photon, Nominatim as fallback), addresses
 * of entries and of a point on the map (Nominatim) and sights, restaurants … nearby (Overpass).
 * Called from the server, cached, without API key.
 */
class PlaceFinder
{
    private const TIMEOUT = 8;

    /** Whether the last request to OpenStreetMap failed (not reachable, blocked …). */
    public static bool $failed = false;

    /**
     * Search as you type: places near the visible map first. Falls back to Nominatim if Photon fails.
     *
     * @param  ?array{0: float, 1: float, 2: float, 3: float}  $bounds  south, west, north, east of the visible map
     * @return list<array{name: string, address: string, lat: float, lng: float, kind: string}>
     */
    public static function search(string $query, ?array $bounds = null): array
    {
        $query = trim(mb_substr($query, 0, 150));

        if (mb_strlen($query) < 2) {
            return [];
        }

        $parameters = ['q' => $query, 'limit' => 8, 'lang' => self::photonLanguage()];

        if ($bounds !== null) {
            [$south, $west, $north, $east] = array_map(fn ($value) => (float) $value, $bounds);
            $parameters += ['lat' => round(($south + $north) / 2, 2), 'lon' => round(($west + $east) / 2, 2), 'zoom' => 12];
        }

        $key = 'places:photon:'.md5(json_encode($parameters));
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $response = self::get(rtrim((string) config('services.maps.photon_url'), '/').'/api/', $parameters);

        if ($response === null) {
            return self::geocode($query, $bounds);
        }

        $places = [];

        foreach ((array) ($response['features'] ?? []) as $feature) {
            $place = self::fromPhoton((array) $feature);

            if ($place && ! collect($places)->contains(fn (array $other) => $other['name'] === $place['name'] && abs($other['lat'] - $place['lat']) < 0.001)) {
                $places[] = $place;
            }
        }

        Cache::put($key, $places, now()->addDays(7));

        return $places;
    }

    /**
     * Address search (Nominatim): best for full addresses, one request per second.
     *
     * @param  ?array{0: float, 1: float, 2: float, 3: float}  $bounds  south, west, north, east (results there first)
     * @return list<array{name: string, address: string, lat: float, lng: float, kind: string}>
     */
    public static function geocode(string $query, ?array $bounds = null): array
    {
        $query = trim(mb_substr($query, 0, 150));

        if (mb_strlen($query) < 2) {
            return [];
        }

        $parameters = ['format' => 'jsonv2', 'q' => $query, 'limit' => 8, 'accept-language' => self::acceptLanguage()];

        if ($bounds !== null) {
            [$south, $west, $north, $east] = array_map(fn ($value) => round((float) $value, 3), $bounds);
            $parameters['viewbox'] = "{$west},{$north},{$east},{$south}";
        }

        $key = 'places:search:'.md5(json_encode($parameters));
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $results = self::get(config('services.maps.nominatim_url').'/search', $parameters);

        if ($results === null) {
            return [];
        }

        // Failed requests are not remembered, so the next try asks again.
        $places = array_values(array_filter(array_map(fn (array $result) => self::fromNominatim($result), $results)));
        Cache::put($key, $places, $places === [] ? now()->addHour() : now()->addDays(7));

        return $places;
    }

    /**
     * Name and address of a point on the map; null if nothing is known there.
     *
     * @return ?array{name: string, address: string, lat: float, lng: float, kind: string}
     */
    public static function reverse(float $lat, float $lng): ?array
    {
        $parameters = ['format' => 'jsonv2', 'lat' => round($lat, 5), 'lon' => round($lng, 5), 'zoom' => 18, 'accept-language' => self::acceptLanguage()];

        return Cache::remember('places:reverse:'.md5(json_encode($parameters)), now()->addDays(30), function () use ($parameters, $lat, $lng) {
            $result = self::get(config('services.maps.nominatim_url').'/reverse', $parameters);
            $place = is_array($result) && isset($result['lat']) ? self::fromNominatim($result) : null;

            return $place ? ['lat' => $lat, 'lng' => $lng] + $place : null;
        });
    }

    /**
     * Coordinates for an address typed into an entry (best match); null if not found.
     * Without a result the trip destination is added (e.g. "Sagrada Família" + "Barcelona").
     *
     * @return ?array{lat: float, lng: float}
     */
    public static function locate(string $address, ?string $destination = null): ?array
    {
        $first = self::geocode($address)[0] ?? null;
        $destination = trim((string) $destination);

        if ($first === null && ! self::$failed && $destination !== '' && ! str_contains(mb_strtolower($address), mb_strtolower($destination))) {
            $first = self::geocode($address.', '.$destination)[0] ?? null;
        }

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

        // A failed request returns null and is therefore not remembered.
        return Cache::remember('places:nearby:'.md5($box), now()->addDay(), function () use ($query): ?array {
            try {
                $response = MapHttp::to((string) config('services.maps.overpass_url'))->timeout(self::TIMEOUT + 10)->asForm()->post(config('services.maps.overpass_url'), ['data' => $query]);
            } catch (Throwable $exception) {
                Log::warning('Places nearby not reachable: '.$exception->getMessage());

                return null;
            }

            if (! $response->successful()) {
                Log::warning('Places nearby failed with HTTP '.$response->status());

                return null;
            }

            $elements = (array) $response->json('elements', []);

            return array_values(array_filter(array_map(function (array $element): ?array {
                $tags = (array) ($element['tags'] ?? []);

                if (! isset($element['lat'], $element['lon'], $tags['name'])) {
                    return null;
                }

                return [
                    'name' => mb_substr(self::localName($tags), 0, 150),
                    'address' => self::readable(trim(implode(' ', array_filter([$tags['addr:street'] ?? null, $tags['addr:housenumber'] ?? null])))),
                    'lat' => (float) $element['lat'],
                    'lng' => (float) $element['lon'],
                    'kind' => self::kindFor((string) ($tags['tourism'] ?? $tags['historic'] ?? ''), (string) ($tags['amenity'] ?? '')),
                ];
            }, $elements)));
        }) ?? [];
    }

    /**
     * Bounding box of a town, island or region (south, west, north, east); null if not found.
     *
     * @return ?array{0: float, 1: float, 2: float, 3: float}
     */
    public static function area(string $query): ?array
    {
        $result = self::get(rtrim((string) config('services.maps.nominatim_url'), '/').'/search', ['format' => 'jsonv2', 'q' => trim(mb_substr($query, 0, 150)), 'limit' => 1, 'accept-language' => self::acceptLanguage()])[0] ?? null;
        $box = is_array($result) ? ($result['boundingbox'] ?? null) : null;

        if (! is_array($box) || count($box) !== 4) {
            return null;
        }

        // Nominatim: south, north, west, east.
        return [(float) $box[0], (float) $box[2], (float) $box[1], (float) $box[3]];
    }

    /**
     * Everything worth a visit in an area: sights, museums, viewpoints, historic places, restaurants,
     * cafés, bars, parks, beaches … with name, address and opening hours / website when known.
     * Null if Overpass could not be reached.
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $bounds  south, west, north, east
     * @return ?list<array{osm_id: string, name: string, kind: string, category: string, address: ?string, lat: float, lng: float, info: ?array<string, string>}>
     */
    public static function areaPlaces(array $bounds, int $limit = 5000): ?array
    {
        [$south, $west, $north, $east] = array_map(fn ($value) => round((float) $value, 4), $bounds);
        $box = "({$south},{$west},{$north},{$east})";
        $query = '[out:json][timeout:120];('
            .'nwr["tourism"~"^(attraction|museum|viewpoint|gallery|zoo|theme_park|aquarium|artwork)$"]["name"]'.$box.';'
            .'nwr["historic"~"^(monument|castle|memorial|ruins|archaeological_site|fort|palace|manor|city_gate|tower|church|monastery)$"]["name"]'.$box.';'
            .'nwr["amenity"~"^(restaurant|cafe|bar|pub|ice_cream|biergarten|marketplace|theatre)$"]["name"]'.$box.';'
            .'nwr["leisure"~"^(park|garden|beach_resort|water_park|nature_reserve)$"]["name"]'.$box.';'
            .'nwr["natural"~"^(beach|peak|cave_entrance|waterfall|bay|cape)$"]["name"]'.$box.';'
            .'nwr["shop"~"^(mall|department_store)$"]["name"]'.$box.';'
            .");out center tags {$limit};";

        try {
            $response = MapHttp::to((string) config('services.maps.overpass_url'))->timeout(150)->asForm()->post(config('services.maps.overpass_url'), ['data' => $query]);
        } catch (Throwable $exception) {
            Log::warning('Places nearby not reachable: '.$exception->getMessage());

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Places nearby failed with HTTP '.$response->status());

            return null;
        }

        $places = [];

        foreach ((array) $response->json('elements', []) as $element) {
            $tags = (array) ($element['tags'] ?? []);
            $lat = $element['lat'] ?? $element['center']['lat'] ?? null;
            $lng = $element['lon'] ?? $element['center']['lon'] ?? null;

            if ($lat === null || $lng === null || ! isset($tags['name'], $element['type'], $element['id'])) {
                continue;
            }

            [$key, $value] = collect(['tourism', 'historic', 'amenity', 'leisure', 'natural', 'shop'])
                ->map(fn (string $key) => [$key, (string) ($tags[$key] ?? '')])->first(fn (array $pair) => $pair[1] !== '');
            $info = array_filter([
                'opening_hours' => isset($tags['opening_hours']) ? mb_substr((string) $tags['opening_hours'], 0, 200) : null,
                'website' => isset($tags['website']) && str_starts_with((string) $tags['website'], 'http') ? mb_substr((string) $tags['website'], 0, 255) : null,
                'cuisine' => isset($tags['cuisine']) ? str_replace(['_', ';'], [' ', ', '], mb_substr((string) $tags['cuisine'], 0, 80)) : null,
                'wikipedia' => isset($tags['wikipedia']) ? mb_substr((string) $tags['wikipedia'], 0, 150) : null,
            ]);

            $places[] = [
                'osm_id' => $element['type'].'/'.$element['id'],
                'name' => mb_substr(self::localName($tags), 0, 150),
                'kind' => self::kindFor($key === 'shop' ? 'shop' : ($key === 'leisure' || $key === 'natural' ? $key : ''), $value),
                'category' => mb_substr($value, 0, 40),
                'address' => self::readable(trim(implode(' ', array_filter([$tags['addr:street'] ?? null, $tags['addr:housenumber'] ?? null])).(isset($tags['addr:city']) ? ', '.$tags['addr:city'] : ''), ', ')) ?: null,
                'lat' => (float) $lat,
                'lng' => (float) $lng,
                'info' => $info ?: null,
            ];
        }

        return $places;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private static function get(string $url, array $parameters): ?array
    {
        self::$failed = false;

        try {
            $response = MapHttp::to($url)->timeout(self::TIMEOUT)->get($url, $parameters);
        } catch (Throwable $exception) {
            self::$failed = true;
            Log::warning('Place search not reachable: '.$exception->getMessage());

            return null;
        }

        if (! $response->successful()) {
            self::$failed = true;
            Log::warning('Place search failed with HTTP '.$response->status());

            return null;
        }

        return (array) $response->json();
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
            'name' => mb_substr(self::readable($name), 0, 150),
            'address' => mb_substr(self::readable($address), 0, 255),
            'lat' => (float) $result['lat'],
            'lng' => (float) $result['lon'],
            'kind' => self::kindFor((string) ($result['category'] ?? ''), (string) ($result['type'] ?? '')),
        ];
    }

    /**
     * @param  array<string, mixed>  $feature  GeoJSON feature from Photon
     * @return ?array{name: string, address: string, lat: float, lng: float, kind: string}
     */
    private static function fromPhoton(array $feature): ?array
    {
        $coordinates = $feature['geometry']['coordinates'] ?? null;
        $properties = (array) ($feature['properties'] ?? []);

        if (! is_array($coordinates) || count($coordinates) < 2) {
            return null;
        }

        $street = trim(($properties['street'] ?? '').' '.($properties['housenumber'] ?? ''));
        $name = trim((string) ($properties['name'] ?? '')) ?: $street;

        if ($name === '') {
            return null;
        }

        $address = collect([$street, trim(($properties['postcode'] ?? '').' '.($properties['city'] ?? $properties['district'] ?? '')), $properties['country'] ?? null])
            ->map(fn ($part) => trim((string) $part))->filter(fn (string $part) => $part !== '' && $part !== $name)->unique()->implode(', ');

        return [
            'name' => mb_substr(self::readable($name), 0, 150),
            'address' => mb_substr(self::readable($address), 0, 255),
            'lat' => (float) $coordinates[1],
            'lng' => (float) $coordinates[0],
            'kind' => self::kindFor((string) ($properties['osm_key'] ?? ''), (string) ($properties['osm_value'] ?? '')),
        ];
    }

    /**
     * The name of an OpenStreetMap object in the app language: "name:de", then "name:en" and the
     * international name, otherwise the local name written in Latin letters.
     *
     * @param  array<string, mixed>  $tags
     */
    public static function localName(array $tags): string
    {
        foreach (['name:'.self::language(), 'name:en', 'int_name', 'name:latin'] as $key) {
            if (filled($tags[$key] ?? null)) {
                return trim((string) $tags[$key]);
            }
        }

        return self::readable(trim((string) ($tags['name'] ?? '')));
    }

    /**
     * Text in Cyrillic, Greek, Arabic … letters written in Latin letters ("Витоша" → "Vitosha");
     * Latin text (with umlauts) stays as it is.
     */
    public static function readable(string $text): string
    {
        if (! preg_match('/[\p{Cyrillic}\p{Greek}\p{Arabic}\p{Hebrew}\p{Georgian}\p{Armenian}]/u', $text)) {
            return $text;
        }

        $latin = function_exists('transliterator_transliterate')
            ? (string) transliterator_transliterate('Any-Latin; Latin-ASCII', $text)
            : Str::ascii($text);

        return trim($latin) !== '' ? trim($latin) : $text;
    }

    private static function language(): string
    {
        return substr(app()->getLocale(), 0, 2) ?: 'de';
    }

    /** Photon knows only a few languages, others fall back to English. */
    private static function photonLanguage(): string
    {
        return in_array(self::language(), ['de', 'en', 'fr', 'it'], true) ? self::language() : 'en';
    }

    private static function acceptLanguage(): string
    {
        return self::language() === 'en' ? 'en' : self::language().',en;q=0.8';
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
