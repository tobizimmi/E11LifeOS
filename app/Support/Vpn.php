<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/**
 * WireGuard connection into a private network (e.g. the home network with paperless),
 * provided by wireproxy as a local SOCKS5 proxy. Requests to addresses inside the configured
 * networks go through it, everything else goes out directly as before.
 */
class Vpn
{
    public const STATUS_KEY = 'vpn:status';

    /**
     * Whether the VPN is switched on and has a usable configuration.
     */
    public static function isConfigured(): bool
    {
        return (bool) config('vpn.enabled') && self::parse((string) config('vpn.wireguard_config')) !== null;
    }

    /**
     * Checks a WireGuard configuration: one [Interface] with PrivateKey and Address and at least
     * one [Peer] with PublicKey and Endpoint. Returns the sections or null.
     *
     * @return array{interface: array<string, string>, peers: list<array<string, string>>}|null
     */
    public static function parse(string $config): ?array
    {
        $interface = [];
        $peers = [];
        $section = null;

        foreach (preg_split('/\R/', $config) as $line) {
            $line = trim(preg_replace('/\s*#.*$/', '', $line));

            if ($line === '') {
                continue;
            }

            if (preg_match('/^\[(interface|peer)\]$/i', $line, $match)) {
                $section = strtolower($match[1]);

                if ($section === 'peer') {
                    $peers[] = [];
                }

                continue;
            }

            if ($section === null || ! str_contains($line, '=')) {
                return null;
            }

            [$key, $value] = array_map('trim', explode('=', $line, 2));

            if ($section === 'interface') {
                $interface[strtolower($key)] = $value;
            } else {
                $peers[count($peers) - 1][strtolower($key)] = $value;
            }
        }

        $valid = filled($interface['privatekey'] ?? null) && filled($interface['address'] ?? null) && $peers !== []
            && collect($peers)->every(fn (array $peer) => filled($peer['publickey'] ?? null) && filled($peer['endpoint'] ?? null));

        return $valid ? ['interface' => $interface, 'peers' => $peers] : null;
    }

    /**
     * Networks, hosts and domains reached through the VPN.
     *
     * @return list<string>
     */
    public static function networks(): array
    {
        $configured = trim((string) config('vpn.networks'));

        if ($configured !== '') {
            return self::split($configured);
        }

        $parsed = self::parse((string) config('vpn.wireguard_config'));

        return collect($parsed['peers'] ?? [])
            ->flatMap(fn (array $peer) => self::split($peer['allowedips'] ?? ''))
            // A default route would send everything through the home network – only private ranges count.
            ->reject(fn (string $network) => in_array($network, ['0.0.0.0/0', '::/0'], true))
            ->unique()->values()->all();
    }

    /**
     * Whether requests to this address go through the VPN.
     */
    public static function routes(string $url): bool
    {
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));

        if ($host === '' || ! self::isConfigured()) {
            return false;
        }

        foreach (self::networks() as $network) {
            $network = strtolower($network);

            if (filter_var($host, FILTER_VALIDATE_IP)) {
                if (str_contains($network, '/') || filter_var($network, FILTER_VALIDATE_IP)) {
                    if (IpUtils::checkIp($host, $network)) {
                        return true;
                    }
                }

                continue;
            }

            $domain = ltrim($network, '.*');

            if ($domain !== '' && ! str_contains($domain, '/') && ($host === $domain || str_ends_with($host, '.'.$domain))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Proxy for an address inside the VPN (DNS is resolved in the private network), else null.
     */
    public static function proxyFor(string $url): ?string
    {
        return self::routes($url) ? 'socks5h://127.0.0.1:'.(int) config('vpn.socks_port') : null;
    }

    /**
     * Guzzle options for an address: through the VPN if it lies inside it.
     *
     * @return array<string, mixed>
     */
    public static function httpOptions(string $url): array
    {
        $proxy = self::proxyFor($url);

        return $proxy ? ['proxy' => $proxy] : [];
    }

    /**
     * Last state reported by "php artisan vpn:run".
     *
     * @return array{state: string, message: ?string, at: string}|null
     */
    public static function status(): ?array
    {
        return Cache::get(self::STATUS_KEY);
    }

    public static function isRunning(): bool
    {
        $status = self::status();

        return ($status['state'] ?? null) === 'running' && now()->subMinutes(2)->lt($status['at']);
    }

    /**
     * The files wireproxy reads: the WireGuard configuration and its own settings.
     *
     * @return array{wireguard: string, wireproxy: string}
     */
    public static function writeFiles(string $directory): array
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        $wireguard = $directory.'/wg.conf';
        $wireproxy = $directory.'/wireproxy.conf';
        file_put_contents($wireguard, trim((string) config('vpn.wireguard_config'))."\n");
        file_put_contents($wireproxy, 'WGConfig = '.$wireguard."\n\n[Socks5]\nBindAddress = 127.0.0.1:".(int) config('vpn.socks_port')."\n");
        chmod($wireguard, 0600);
        chmod($wireproxy, 0600);

        return ['wireguard' => $wireguard, 'wireproxy' => $wireproxy];
    }

    /**
     * Calls an address through the VPN and describes the result.
     *
     * @return array{ok: bool, message: string}
     */
    public static function test(string $url): array
    {
        if (! self::isConfigured()) {
            return ['ok' => false, 'message' => __('The VPN is switched off or the WireGuard configuration is incomplete.')];
        }

        $proxy = 'socks5h://127.0.0.1:'.(int) config('vpn.socks_port');

        try {
            $response = Http::withOptions(['proxy' => $proxy])->timeout(10)->withoutRedirecting()->get($url);

            return ['ok' => true, 'message' => __('Reached through the VPN (HTTP :status).', ['status' => $response->status()])];
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => __('Not reachable through the VPN: :message', ['message' => str($exception->getMessage())->limit(160)])];
        }
    }

    /**
     * @return list<string>
     */
    private static function split(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $value))));
    }
}
