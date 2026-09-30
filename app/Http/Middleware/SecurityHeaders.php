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
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net",
            "font-src 'self' data: https://fonts.bunny.net",
            // Recipe photos and logos may come from other sites; camera pictures are blob: URLs.
            "img-src 'self' data: blob: https:",
            "media-src 'self' blob:",
            // Live updates (Reverb) run over a WebSocket, possibly on another host behind the proxy.
            "connect-src 'self' ws: wss:",
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
