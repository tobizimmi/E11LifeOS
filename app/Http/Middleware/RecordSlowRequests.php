<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Measures every page and Livewire request: slow ones (over a second) land with their route,
 * duration and number of database queries in a short list on the admin page, together with
 * the slowest routes of the last week.
 */
class RecordSlowRequests
{
    public const SLOW_KEY = 'perf:slow';

    public const ROUTES_KEY = 'perf:routes';

    public const THRESHOLD_MS = 1000;

    private static int $queries = 0;

    private static bool $listening = false;

    public function handle(Request $request, Closure $next): Response
    {
        if (! self::$listening) {
            DB::listen(fn () => self::$queries++);
            self::$listening = true;
        }

        $queriesBefore = self::$queries;
        $start = hrtime(true);
        $response = $next($request);
        $milliseconds = (int) ((hrtime(true) - $start) / 1_000_000);

        try {
            $this->record($request, $milliseconds, self::$queries - $queriesBefore);
        } catch (\Throwable) {
            // Measuring must never break a request.
        }

        return $response;
    }

    private function record(Request $request, int $milliseconds, int $queries): void
    {
        $route = $request->route()?->getName() ?? $request->path();

        if ($route === 'livewire.update') {
            $component = data_get($request->input('components'), '0.snapshot');
            $route = 'livewire:'.(is_string($component) ? (string) data_get(json_decode($component, true), 'memo.name', '?') : '?');
        }

        if ($milliseconds >= self::THRESHOLD_MS) {
            $entry = ['at' => now()->toIso8601String(), 'route' => $route, 'ms' => $milliseconds, 'queries' => $queries];
            Cache::put(self::SLOW_KEY, array_slice([$entry, ...Cache::get(self::SLOW_KEY, [])], 0, 30), now()->addDays(7));
        }

        // Per route: requests, total and slowest time of the current week (one small cache entry).
        $routes = Cache::get(self::ROUTES_KEY, []);
        $stats = $routes[$route] ?? ['count' => 0, 'total' => 0, 'max' => 0];
        $routes[$route] = ['count' => $stats['count'] + 1, 'total' => $stats['total'] + $milliseconds, 'max' => max($stats['max'], $milliseconds)];

        if (count($routes) > 300) {
            uasort($routes, fn (array $a, array $b) => $b['count'] <=> $a['count']);
            $routes = array_slice($routes, 0, 200, true);
        }

        Cache::put(self::ROUTES_KEY, $routes, now()->endOfWeek());
    }

    /**
     * The slowest routes by average time (at least three requests).
     *
     * @return list<array{route: string, count: int, average: int, max: int}>
     */
    public static function slowestRoutes(int $limit = 8): array
    {
        return collect(Cache::get(self::ROUTES_KEY, []))
            ->filter(fn (array $stats) => $stats['count'] >= 3)
            ->map(fn (array $stats, string $route) => ['route' => $route, 'count' => $stats['count'], 'average' => intdiv($stats['total'], $stats['count']), 'max' => $stats['max']])
            ->sortByDesc('average')->take($limit)->values()->all();
    }
}
