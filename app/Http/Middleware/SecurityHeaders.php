<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser protection for every response: no framing by other sites (clickjacking), no MIME
 * sniffing, no referrer (share links stay private), HTTPS only once visited, and camera,
 * microphone and location only for this app itself (barcode scanner, voice input, weather).
 *
 * The Content-Security-Policy only runs scripts from this server or with the nonce of the
 * current response, so injected <script> tags and inline event handlers (onclick=…) are blocked.
 * Alpine evaluates its x-… expressions with new Function, hence 'unsafe-eval'.
 */
class SecurityHeaders
{
    /** @var array<string, string> */
    public const HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Referrer-Policy' => 'same-origin',
        'Permissions-Policy' => 'camera=(self), microphone=(self), geolocation=(self), payment=(), usb=(), browsing-topics=()',
        'Cross-Origin-Opener-Policy' => 'same-origin',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();
        $response = $next($request);

        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', self::contentSecurityPolicy($nonce));
        }

        foreach (self::HEADERS as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }

    public static function contentSecurityPolicy(string $nonce): string
    {
        // Live updates (Reverb) run over a WebSocket, on its own configured host when one is
        // set (e.g. a dedicated subdomain), otherwise on this app's own host behind the proxy.
        // The port is left open ("*"): the public port (often 443 via a reverse proxy) can
        // differ from Reverb's internal listen port and is not reliably known here.
        // VITE_REVERB_HOST is what the BROWSER actually connects to (baked into the built JS,
        // see resources/js/echo.js); reverb.servers.reverb.hostname is only the server's own
        // listen config and config('app.url') a last-resort fallback for local setups without a
        // dedicated Reverb host — both can legitimately diverge from the browser-facing one.
        $reverbHost = (string) (config('reverb.browser_hostname') ?: config('reverb.servers.reverb.hostname') ?: parse_url((string) config('app.url'), PHP_URL_HOST));
        $connect = $reverbHost !== '' ? "connect-src 'self' wss://{$reverbHost}:* ws://{$reverbHost}:*" : "connect-src 'self'";

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net",
            "font-src 'self' data: https://fonts.bunny.net",
            // Recipe photos are stored locally, but bank logos (GoCardless), Instagram
            // thumbnails, shopping offer images and trip photos legitimately come from many
            // different external hosts, so this stays open to any https image.
            "img-src 'self' data: blob: https:",
            "media-src 'self' blob:",
            $connect,
            "worker-src 'self' blob:",
            "manifest-src 'self'",
            "frame-src 'self' blob:",
            "frame-ancestors 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ]);
    }
}
