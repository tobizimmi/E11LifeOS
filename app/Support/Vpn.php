<?php

namespace App\Support;

use Illuminate\Support\Carbon;
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
        return self::routes($url) ? 'socks5h://127.0.0.1:'.self::socksPort() : null;
    }

    /**
     * The local SOCKS port wireproxy listens on: the one "vpn:run" reported (it moves to the
     * installation's own port when the configured one is taken, e.g. by live and staging on one
     * server), otherwise the configured one.
     */
    public static function socksPort(): int
    {
        $status = self::status();

        return isset($status['port']) && now()->subMinutes(2)->lt($status['at']) ? (int) $status['port'] : (int) config('vpn.socks_port');
    }

    /**
     * A port of its own per installation folder, so live and staging on one server never collide.
     */
    public static function defaultSocksPort(): int
    {
        return 25000 + crc32(base_path()) % 1000;
    }

    /**
     * Whether something already listens on this local port.
     */
    public static function portInUse(int $port): bool
    {
        $socket = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.3);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
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
     * @return array{state: string, message: ?string, port?: ?int, at: string}|null
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
    public static function writeFiles(string $directory, ?int $port = null): array
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        $wireguard = $directory.'/wg.conf';
        $wireproxy = $directory.'/wireproxy.conf';
        file_put_contents($wireguard, self::withKeepalive(trim((string) config('vpn.wireguard_config')))."\n");
        file_put_contents($wireproxy, 'WGConfig = '.$wireguard."\n\n[Socks5]\nBindAddress = 127.0.0.1:".($port ?? (int) config('vpn.socks_port'))."\n");
        chmod($wireguard, 0600);
        chmod($wireproxy, 0600);

        return ['wireguard' => $wireguard, 'wireproxy' => $wireproxy];
    }

    /**
     * Adds "PersistentKeepalive = 25" to every [Peer] without it: the server sends a small packet
     * every 25 seconds, so routers and firewalls on the way keep the connection open.
     */
    public static function withKeepalive(string $config): string
    {
        $sections = preg_split('/(?=^\s*\[(?:interface|peer)\]\s*$)/mi', $config) ?: [$config];

        return implode('', array_map(function (string $section) {
            if (! preg_match('/^\s*\[peer\]/i', $section) || preg_match('/^\s*persistentkeepalive\s*=/mi', $section)) {
                return $section;
            }

            return rtrim($section)."\nPersistentKeepalive = 25\n\n";
        }, $sections));
    }

    /**
     * Calls an address through the VPN and describes the result, with likely causes on failure.
     *
     * @return array{ok: bool, message: string, hints?: list<string>}
     */
    public static function test(string $url): array
    {
        if (! self::isConfigured()) {
            return ['ok' => false, 'message' => __('The VPN is switched off or the WireGuard configuration is incomplete.')];
        }

        $proxy = 'socks5h://127.0.0.1:'.self::socksPort();

        try {
            $response = Http::withOptions(['proxy' => $proxy])->timeout(10)->withoutRedirecting()->get($url);

            return ['ok' => true, 'message' => __('Reached through the VPN (HTTP :status).', ['status' => $response->status()])];
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => __('Not reachable through the VPN: :message', ['message' => str($exception->getMessage())->limit(160)]), 'hints' => self::diagnose($url, self::tunnelState())];
        }
    }

    /**
     * State of the tunnel from wireproxy's status endpoint: whether and when the last WireGuard
     * handshake happened and how much came back. Null while the endpoint is not available.
     *
     * @return array{handshake_at: ?int, received: int, sent: int}|null
     */
    public static function tunnelState(): ?array
    {
        try {
            $response = Http::timeout(3)->get('http://127.0.0.1:'.(int) config('vpn.info_port').'/metrics');
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $handshake = 0;
        $received = 0;
        $sent = 0;

        foreach (preg_split('/\R/', $response->body()) as $line) {
            [$key, $value] = array_pad(explode('=', trim($line), 2), 2, '');

            match ($key) {
                'last_handshake_time_sec' => $handshake = max($handshake, (int) $value),
                'rx_bytes' => $received += (int) $value,
                'tx_bytes' => $sent += (int) $value,
                default => null,
            };
        }

        return ['handshake_at' => $handshake > 0 ? $handshake : null, 'received' => $received, 'sent' => $sent];
    }

    /**
     * Why an address in a private network may not answer, as far as the VPN is concerned
     * (null when the VPN is not the likely cause, e.g. for public addresses).
     */
    public static function reachabilityHint(string $url): ?string
    {
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
        $isPrivate = filter_var($host, FILTER_VALIDATE_IP)
            ? ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
            : (bool) preg_match('/\.(local|lan|home|fritz\.box|internal)$/', $host);

        if (! $isPrivate) {
            return null;
        }

        if (! self::routes($url)) {
            return __(':host lies in a private network but does not go through the VPN. Switch the VPN on in the server settings, or add the network under “Networks through the VPN”.', ['host' => $host]);
        }

        if (! self::isRunning()) {
            return __('The address goes through the VPN, but the VPN is not running – see server settings → VPN.');
        }

        $tunnel = self::tunnelState();

        if ($tunnel !== null && ($tunnel['handshake_at'] === null || $tunnel['handshake_at'] < now()->subMinutes(3)->getTimestamp())) {
            return __('The address goes through the VPN, but the VPN has no connection to your router yet (no handshake) – see server settings → VPN.');
        }

        return null;
    }

    /**
     * Likely causes when an address cannot be reached through the VPN, most specific first.
     *
     * @param  array{handshake_at: ?int, received: int, sent: int}|null  $tunnel  from tunnelState()
     * @return list<string>
     */
    public static function diagnose(string $url, ?array $tunnel = null): array
    {
        $hints = [];
        $parsed = self::parse((string) config('vpn.wireguard_config'));
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
        $allowed = collect($parsed['peers'] ?? [])->flatMap(fn (array $peer) => self::split($peer['allowedips'] ?? ''))->values();

        if (filter_var($host, FILTER_VALIDATE_IP) && $allowed->isNotEmpty() && ! $allowed->contains(fn (string $network) => IpUtils::checkIp($host, $network))) {
            $hints[] = __(':host is not covered by AllowedIPs in the [Peer] section (:allowed). Add the home network, e.g. :network.', [
                'host' => $host,
                'allowed' => $allowed->implode(', '),
                'network' => preg_replace('/\.\d+$/', '.0/24', $host),
            ]);
        }

        $brokenOff = $tunnel !== null && $tunnel['handshake_at'] !== null && $tunnel['handshake_at'] < now()->subMinutes(3)->getTimestamp();

        if ($tunnel !== null && $tunnel['handshake_at'] === null) {
            $hints[] = __('No WireGuard handshake yet – the tunnel is not up. Check the Endpoint (public address and port of your router), that the router forwards this UDP port, that the router knows this connection (its public key), and that the server may send UDP traffic.');
        } elseif ($brokenOff) {
            $hints[] = __('The last WireGuard handshake was :time – the connection has broken off. Check whether the router is reachable; the app reconnects by itself.', ['time' => Carbon::createFromTimestamp($tunnel['handshake_at'])->diffForHumans()]);
        } elseif ($tunnel === null) {
            $hints[] = __('The state of the tunnel is unknown. Check the Endpoint and keys of the WireGuard configuration, that the router forwards the UDP port and knows this connection, and that it lets the VPN connection into the home network.');
        } else {
            $hints[] = __('The tunnel is up (last handshake :time), but the address does not answer. Check that the router lets the VPN connection into the home network, that the service runs on this port and that the firewall of the target device allows access from the VPN network.', ['time' => Carbon::createFromTimestamp($tunnel['handshake_at'])->diffForHumans()]);
        }

        $fixedAddress = collect($parsed['peers'] ?? [])->first(fn (array $peer) => filter_var(preg_replace('/:\d+$/', '', trim($peer['endpoint'], '[]')), FILTER_VALIDATE_IP) !== false);

        if ($fixedAddress && ($tunnel === null || $tunnel['handshake_at'] === null || $brokenOff)) {
            $hints[] = __('The Endpoint is a fixed IP address (:endpoint). Home connections usually get a new address now and then – use the DynDNS name of your router instead (e.g. from MyFRITZ!).', ['endpoint' => $fixedAddress['endpoint']]);
        }

        return $hints;
    }

    /**
     * @return list<string>
     */
    private static function split(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $value))));
    }
}
