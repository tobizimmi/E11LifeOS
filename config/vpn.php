<?php

/*
 * WireGuard connection of the server into a private network (e.g. the home network with
 * paperless). It runs without root rights: "wireproxy" connects via WireGuard in user space
 * and offers a local SOCKS5 proxy that the app uses for addresses inside that network.
 * Usually entered on the admin page (Server settings → VPN) instead of here.
 */
return [

    'enabled' => (bool) env('VPN_ENABLED', false),

    // Content of the WireGuard configuration file ([Interface] + [Peer]), e.g. from the FritzBox.
    'wireguard_config' => env('VPN_WIREGUARD_CONFIG'),

    // Addresses reached through the VPN: networks (192.168.178.0/24), hosts or domains
    // (paperless.fritz.box, .home). Empty: the AllowedIPs of the configuration.
    'networks' => env('VPN_NETWORKS'),

    // Local SOCKS5 port. The default depends on the installation folder, so staging and live on
    // one server get different ports.
    'socks_port' => (int) env('VPN_SOCKS_PORT', 25000 + crc32(base_path()) % 1000),

    // The wireproxy program (installed with "php artisan vpn:install").
    /*
     * Local status endpoint of wireproxy ("-i"): tells whether the WireGuard handshake works.
     */
    'info_port' => (int) env('VPN_INFO_PORT', 26000 + crc32(base_path()) % 1000),

    'binary' => env('VPN_WIREPROXY_BINARY', storage_path('app/vpn/wireproxy')),

    'download_url' => env('VPN_WIREPROXY_URL', 'https://github.com/whyvl/wireproxy/releases/latest/download/wireproxy_linux_{arch}.tar.gz'),
];
