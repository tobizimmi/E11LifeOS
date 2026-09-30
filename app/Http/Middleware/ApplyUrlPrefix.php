<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Behind the reverse proxy the app lives under a URL path (APP_URL, e.g. /E11LifeOS-staging) while
 * PHP sees unprefixed paths. URLs built from the request itself — the page to return to after
 * signing in or confirming the password, "back" after a form error — would then drop that path.
 * The prefix and scheme from APP_URL are passed as X-Forwarded-Prefix/-Proto (the only proxy headers
 * trusted, client values are dropped), so Symfony adds them to the request's URL; routing still uses
 * the unprefixed path.
 */
class ApplyUrlPrefix
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->remove('X-Forwarded-Prefix');
        $request->headers->remove('X-Forwarded-Proto');

        if (! app()->environment(['production', 'staging'])) {
            return $next($request);
        }

        $prefix = rtrim((string) parse_url((string) config('app.url'), PHP_URL_PATH), '/');

        if ($prefix !== '' && ! str_starts_with($request->getRequestUri(), $prefix.'/')) {
            $request->headers->set('X-Forwarded-Prefix', $prefix);
        }

        // TLS ends at the proxy; the scheme of APP_URL is the one visitors use.
        $request->headers->set('X-Forwarded-Proto', (string) parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https');

        return $next($request);
    }
}
