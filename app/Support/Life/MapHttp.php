<?php

namespace App\Support\Life;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * HTTP client for the map services (OpenStreetMap search, routes, places). Remembers the IP address
 * of each service for a day: on a server whose name lookup is slow (e.g. 5 s for an unreachable
 * first DNS server) only the first request of the day waits, all others connect at once.
 */
class MapHttp
{
    public static function to(string $url): PendingRequest
    {
        $request = Http::withHeaders(['User-Agent' => config('app.name').' trip planner ('.config('app.url').')']);
        $host = (string) parse_url($url, PHP_URL_HOST);
        $ip = $host !== '' ? self::address($host) : null;

        if ($ip !== null) {
            $port = parse_url($url, PHP_URL_PORT) ?: (parse_url($url, PHP_URL_SCHEME) === 'http' ? 80 : 443);
            $request = $request->withOptions(['curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]]]);
        }

        return $request;
    }

    /**
     * The remembered IPv4 address of a host (looked up once, null if it cannot be found).
     */
    public static function address(string $host): ?string
    {
        if (! config('services.maps.remember_dns') || filter_var($host, FILTER_VALIDATE_IP) || $host === 'localhost') {
            return null;
        }

        $key = 'map-dns:'.$host;
        $cached = Cache::get($key);

        if (is_string($cached)) {
            return $cached;
        }

        $addresses = gethostbynamel($host) ?: [];

        if ($addresses === []) {
            return null;
        }

        Cache::put($key, $addresses[0], now()->addDay());

        return $addresses[0];
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
