<?php

namespace App\Support\Life;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * HTTP client for the map services (OpenStreetMap search, routes, places) and the remembered IP
 * addresses of external hosts (used for every outgoing request, see AppServiceProvider): on a
 * server whose name lookup is slow (e.g. 5 s for an unreachable first DNS server) only the first
 * request every few hours waits, all others connect at once.
 */
class MapHttp
{
    public static function to(string $url): PendingRequest
    {
        // OpenStreetMap asks every app to identify itself; the remembered address is added for all requests.
        return Http::withHeaders(['User-Agent' => config('app.name').' trip planner ('.config('app.url').')']);
    }

    /**
     * The remembered IPv4 address of a host (looked up once, null if it cannot be found).
     */
    public static function address(string $host): ?string
    {
        $host = strtolower($host);

        // IP addresses, local names and the home network (reached through the VPN) are not remembered.
        if (! config('services.maps.remember_dns') || filter_var($host, FILTER_VALIDATE_IP) || ! str_contains($host, '.')
            || preg_match('/\.(local|lan|box|home|internal|intranet|localhost)$/', $host)) {
            return null;
        }

        $key = 'map-dns:'.$host;
        $cached = Cache::get($key);

        if (is_string($cached)) {
            return $cached !== '' ? $cached : null;
        }

        $addresses = array_values(array_filter(gethostbynamel($host) ?: [], fn (string $ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)));

        // Not found (or only a private address): asked again in ten minutes.
        Cache::put($key, $addresses[0] ?? '', $addresses !== [] ? now()->addHours(6) : now()->addMinutes(10));

        return $addresses[0] ?? null;
    }

    /**
     * Looks up all map services again (scheduled), so no visitor has to wait for the name lookup.
     */
    public static function warm(): void
    {
        foreach (['nominatim_url', 'overpass_url', 'photon_url', 'routing_url'] as $service) {
            $host = (string) parse_url((string) config('services.maps.'.$service), PHP_URL_HOST);

            if ($host !== '') {
                Cache::forget('map-dns:'.$host);
                self::address($host);
            }
        }
    }
}
