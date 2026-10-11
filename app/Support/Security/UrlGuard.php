<?php

namespace App\Support\Security;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Shared address check against server-side request forgery (SSRF). A host that cannot be
 * resolved at all is always rejected – it is never treated as safe just because nothing could
 * be checked.
 *
 * Residual risk: this checks the name at validation/save time, not at connection time (DNS
 * rebinding) – a host that resolves to a public IP here could, with a very short TTL, resolve to
 * a loopback address by the time the client actually connects. Closing that fully needs pinning
 * the checked IP onto the outgoing connection (e.g. CURLOPT_RESOLVE), which the FinTS library in
 * this project does not expose a hook for.
 */
class UrlGuard
{
    /**
     * Loopback, link-local and "no address" ranges – never a legitimate remote server, on any
     * network (VPN or private LAN included). The IPv4-mapped IPv6 form ("::ffff:0:0/96") is
     * blocked outright rather than unwrapped and re-checked: no legitimate address is ever
     * entered that way, and Symfony's IpUtils does not match an embedded IPv4 against the plain
     * IPv4 ranges above (e.g. "::ffff:127.0.0.1" would otherwise slip past "127.0.0.0/8").
     * "0.0.0.0/8" and the IPv6 unspecified address ("::/128", distinct from "::1") both resolve
     * to localhost on Linux/macOS; "64:ff9b::/96" is the NAT64 well-known prefix, which can also
     * reach loopback on a NAT64-enabled network.
     *
     * @var list<string>
     */
    private const LOCAL_RANGES = [
        '127.0.0.0/8', '0.0.0.0/8', '169.254.0.0/16', '::1/128', '::/128',
        'fe80::/10', '::ffff:0:0/96', '64:ff9b::/96',
    ];

    /**
     * The URL's shape: http(s) scheme, a host, and no inline user name or password. Does not
     * check the host itself – combine with isPublicHost() or isNotLocalHost() for that.
     */
    public static function hasValidShape(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts) && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && filled($parts['host'] ?? null) && ! isset($parts['user']) && ! isset($parts['pass']);
    }

    /**
     * Whether a host (literal IP or name) resolves only to public IP addresses: no loopback,
     * link-local, private or reserved range. For addresses that must never point into any
     * private network (e.g. a bank's FinTS server).
     */
    public static function isPublicHost(string $host): bool
    {
        $ips = self::resolve($host);

        return $ips !== [] && collect($ips)->every(
            fn (string $ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
        );
    }

    /**
     * Whether a host (literal IP or name) resolves only to addresses outside loopback,
     * link-local and "no address" ranges – private LAN addresses (e.g. a home server reachable
     * through a VPN) are still allowed. For addresses that may legitimately sit on a private
     * network, but must never point back at the server itself.
     */
    public static function isNotLocalHost(string $host): bool
    {
        $ips = self::resolve($host);

        return $ips !== [] && collect($ips)->every(fn (string $ip) => ! IpUtils::checkIp($ip, self::LOCAL_RANGES));
    }

    /**
     * Resolves both A and AAAA records: gethostbynamel() alone only ever asks for A records, so
     * a dual-stack host whose AAAA record points at a loopback/link-local address would pass
     * every check here and then have curl (which prefers IPv6) connect to that address anyway.
     *
     * @return list<string>
     */
    private static function resolve(string $host): array
    {
        $host = trim($host, '[]');

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_unique(array_filter(array_map(
            fn (array $record) => $record['ip'] ?? $record['ipv6'] ?? null, $records
        ))));
    }
}
