<?php

namespace App\Support\Life;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Routes through the places of a day on foot, by bike or by car (OSRM on OpenStreetMap data):
 * the line along the streets and how long each way from one place to the next takes. Cached.
 */
class TripRoutes
{
    /** OSRM allows only a limited number of stops per route. */
    private const MAX_POINTS = 25;

    /**
     * @return array<string, array{icon: string, label: string, profile: string}>
     */
    public static function modes(): array
    {
        return [
            'foot' => ['icon' => '🚶', 'label' => __('On foot'), 'profile' => 'routed-foot'],
            'bike' => ['icon' => '🚲', 'label' => __('By bike'), 'profile' => 'routed-bike'],
            'car' => ['icon' => '🚗', 'label' => __('By car'), 'profile' => 'routed-car'],
        ];
    }

    /**
     * The route through the points in this order. Without $fetch only a remembered one is returned;
     * false means "tried, but no route" (not reachable, no way …).
     *
     * @param  list<array{lat: float, lng: float}>  $points
     * @return array{duration: float, distance: float, legs: list<array{duration: float, distance: float}>, line: list<array{0: float, 1: float}>}|false|null
     */
    public static function route(array $points, string $mode, bool $fetch = true): array|false|null
    {
        $points = array_slice($points, 0, self::MAX_POINTS);

        if (count($points) < 2 || ! isset(self::modes()[$mode])) {
            return null;
        }

        $coordinates = implode(';', array_map(fn (array $point) => round($point['lng'], 5).','.round($point['lat'], 5), $points));
        $key = 'places:route:'.$mode.':'.md5($coordinates);
        $cached = Cache::get($key);

        if ($cached !== null || ! $fetch) {
            return $cached;
        }

        $route = self::request($coordinates, $mode, count($points) - 1);
        Cache::put($key, $route ?? false, $route ? now()->addDays(30) : now()->addMinutes(10));

        return $route ?? false;
    }

    /**
     * @return ?array{duration: float, distance: float, legs: list<array{duration: float, distance: float}>, line: list<array{0: float, 1: float}>}
     */
    private static function request(string $coordinates, string $mode, int $legCount): ?array
    {
        $url = rtrim((string) config('services.maps.routing_url'), '/').'/'.self::modes()[$mode]['profile'].'/route/v1/driving/'.$coordinates;

        try {
            $response = MapHttp::to($url)
                ->timeout(10)->get($url, ['overview' => 'full', 'geometries' => 'geojson', 'steps' => 'false']);
        } catch (Throwable $exception) {
            Log::warning('Route not reachable: '.$exception->getMessage());

            return null;
        }

        $route = $response->successful() ? $response->json('routes.0') : null;

        if (! is_array($route) || ! isset($route['legs'], $route['geometry']['coordinates']) || count((array) $route['legs']) !== $legCount || count((array) $route['geometry']['coordinates']) < 2) {
            if (! $response->successful()) {
                Log::warning('Route failed with HTTP '.$response->status());
            }

            return null;
        }

        $line = array_map(fn (array $point) => [round((float) $point[1], 5), round((float) $point[0], 5)], (array) $route['geometry']['coordinates']);

        // Long car routes have thousands of points; every n-th one is enough for the map.
        if (count($line) > 1500) {
            $step = (int) ceil(count($line) / 1500);
            $line = array_values(array_filter($line, fn ($point, int $index) => $index % $step === 0 || $index === count($line) - 1, ARRAY_FILTER_USE_BOTH));
        }

        return [
            'duration' => (float) ($route['duration'] ?? 0),
            'distance' => (float) ($route['distance'] ?? 0),
            'legs' => array_map(fn (array $leg) => ['duration' => (float) ($leg['duration'] ?? 0), 'distance' => (float) ($leg['distance'] ?? 0)], (array) $route['legs']),
            'line' => $line,
        ];
    }

    /**
     * "8 min", "1 h 25 min".
     */
    public static function duration(float $seconds): string
    {
        $minutes = max(1, (int) round($seconds / 60));

        if ($minutes < 60) {
            return __(':minutes min', ['minutes' => $minutes]);
        }

        return intdiv($minutes, 60).' '.__('h').($minutes % 60 ? ' '.__(':minutes min', ['minutes' => $minutes % 60]) : '');
    }

    /**
     * "650 m", "4.2 km".
     */
    public static function distance(float $meters): string
    {
        return $meters < 1000 ? (int) (round($meters / 10) * 10).' m' : number_format($meters / 1000, $meters < 10000 ? 1 : 0, ',', '.').' km';
    }
}
