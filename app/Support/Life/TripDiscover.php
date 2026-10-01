<?php

namespace App\Support\Life;

use App\Models\Trip;
use App\Models\TripPlace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * "Discover" of a trip: the places of the trip area by category (sights, restaurants, cafés …)
 * with picture, menu and links to photos and reviews, and the places already visited.
 */
class TripDiscover
{
    /**
     * @return array<string, array{label: string, icon: string, types: list<string>}>
     */
    public static function categories(): array
    {
        return [
            'sights' => ['label' => __('Sights'), 'icon' => '🏛️', 'types' => ['attraction', 'monument', 'castle', 'memorial', 'ruins', 'archaeological_site', 'fort', 'palace', 'manor', 'city_gate', 'tower', 'church', 'monastery', 'artwork']],
            'museums' => ['label' => __('Museums & culture'), 'icon' => '🖼️', 'types' => ['museum', 'gallery', 'theatre']],
            'restaurants' => ['label' => __('Restaurants'), 'icon' => '🍽️', 'types' => ['restaurant', 'biergarten']],
            'cafes' => ['label' => __('Cafés & ice cream'), 'icon' => '☕', 'types' => ['cafe', 'ice_cream']],
            'bars' => ['label' => __('Bars & pubs'), 'icon' => '🍸', 'types' => ['bar', 'pub']],
            'nature' => ['label' => __('Nature & views'), 'icon' => '🌳', 'types' => ['park', 'garden', 'nature_reserve', 'beach', 'peak', 'cave_entrance', 'waterfall', 'bay', 'cape', 'viewpoint']],
            'activities' => ['label' => __('Activities'), 'icon' => '🎡', 'types' => ['zoo', 'theme_park', 'aquarium', 'water_park', 'beach_resort']],
            'shopping' => ['label' => __('Shopping & markets'), 'icon' => '🛍️', 'types' => ['mall', 'department_store', 'marketplace']],
        ];
    }

    public static function categoryOf(TripPlace $place): string
    {
        foreach (self::categories() as $key => $category) {
            if (in_array($place->category, $category['types'], true)) {
                return $key;
            }
        }

        return $place->kind === 'food' ? 'restaurants' : 'sights';
    }

    public static function isVisited(Trip $trip, TripPlace $place): bool
    {
        return in_array($place->osm_id, $trip->visited_places ?? [], true);
    }

    /**
     * Marks a place as visited (it then disappears from the lists) or as not visited again.
     */
    public static function toggleVisited(Trip $trip, string $osmId): bool
    {
        $visited = $trip->visited_places ?? [];
        $isVisited = in_array($osmId, $visited, true);
        $trip->forceFill(['visited_places' => $isVisited ? array_values(array_diff($visited, [$osmId])) : [...$visited, $osmId]])->save();

        return ! $isVisited;
    }

    /**
     * How much there is to know about a place – places with picture, menu and opening hours first.
     */
    public static function richness(TripPlace $place): int
    {
        $info = $place->info ?? [];

        return (isset($info['image']) || isset($info['commons']) || isset($info['wikipedia']) ? 3 : 0)
            + (isset($info['menu']) ? 2 : 0) + (isset($info['website']) ? 1 : 0) + (isset($info['opening_hours']) ? 1 : 0) + (isset($info['cuisine']) ? 1 : 0);
    }

    /**
     * A picture that can be shown right away, or the route that looks one up on Wikipedia.
     */
    public static function imageUrl(TripPlace $place): ?string
    {
        $info = $place->info ?? [];

        return match (true) {
            isset($info['image']) => $info['image'],
            isset($info['commons']) => 'https://commons.wikimedia.org/wiki/Special:FilePath/'.rawurlencode(substr($info['commons'], 5)).'?width=480',
            isset($info['wikipedia']) => route('trips.place-image', [$place->trip_id, $place->id]),
            default => null,
        };
    }

    /**
     * The thumbnail of the Wikipedia article of a place ("de:Kölner Dom"), cached for a month.
     */
    public static function wikipediaThumbnail(string $article): ?string
    {
        [$language, $title] = str_contains($article, ':') ? explode(':', $article, 2) : ['de', $article];

        if (! preg_match('/^[a-z\-]{2,12}$/', $language) || trim($title) === '') {
            return null;
        }

        return Cache::remember('wiki-thumb:'.md5($article), now()->addMonth(), function () use ($language, $title): string {
            try {
                $response = Http::timeout(6)->withHeaders(['User-Agent' => 'E11LifeOS (trip planner)'])
                    ->get("https://{$language}.wikipedia.org/api/rest_v1/page/summary/".rawurlencode(str_replace(' ', '_', trim($title))));
                $source = (string) ($response->json('thumbnail.source') ?? '');

                return str_starts_with($source, 'https://') ? $source : '';
            } catch (Throwable) {
                return '';
            }
        }) ?: null;
    }

    /**
     * Links for choosing: menu (or a search for it), photos and reviews on Google Maps.
     *
     * @return array{menu: ?string, menu_search: ?string, photos: string, website: ?string}
     */
    public static function links(TripPlace $place, ?string $destination = null): array
    {
        $info = $place->info ?? [];
        $where = trim($place->name.' '.($place->address ?: (string) $destination));

        return [
            'menu' => $info['menu'] ?? null,
            'menu_search' => in_array(self::categoryOf($place), ['restaurants', 'cafes', 'bars'], true) && ! isset($info['menu'])
                ? 'https://www.google.com/search?'.http_build_query(['q' => $where.' '.__('menu')])
                : null,
            'photos' => 'https://www.google.com/maps/search/?'.http_build_query(['api' => 1, 'query' => $where]),
            'website' => $info['website'] ?? null,
        ];
    }
}
