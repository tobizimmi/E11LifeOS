<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Behind the Apache reverse proxy PHP sees neither the public host nor the URL path of the app
 * (requests arrive as http://127.0.0.1:3020/finance, APP_URL is https://zimmimail.de/E11LifeOS-staging).
 * Links built with route()/url() use APP_URL, but the page to return to after signing in or
 * confirming the password (url.intended) and "back" after a form error (_previous.url) are taken
 * from the request itself. Apache only fixes such URLs in Location headers, not in Livewire
 * responses – so these stored URLs are rewritten onto APP_URL before and after every request.
 */
class ApplyUrlPrefix
{
    private const SESSION_KEYS = ['url.intended', '_previous.url'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->environment(['production', 'staging']) || ! $request->hasSession()) {
            return $next($request);
        }

        $this->normalizeSession($request);
        $response = $next($request);
        $this->normalizeSession($request);

        return $response;
    }

    /**
     * Rewrites a URL of this app onto the public root, e.g. http://127.0.0.1:3020/finance?tab=1
     * becomes https://zimmimail.de/E11LifeOS-staging/finance?tab=1.
     */
    public static function publicUrl(string $url): string
    {
        $root = rtrim((string) config('app.url'), '/');

        if ($url === '' || $url === $root || str_starts_with($url, $root.'/') || str_starts_with($url, $root.'?')) {
            return $url;
        }

        $prefix = rtrim((string) parse_url($root, PHP_URL_PATH), '/');
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '/');
        $query = parse_url($url, PHP_URL_QUERY);

        if ($prefix !== '' && ($path === $prefix || str_starts_with($path, $prefix.'/'))) {
            $path = substr($path, strlen($prefix));
        }

        return $root.'/'.ltrim($path, '/').($query ? '?'.$query : '');
    }

    private function normalizeSession(Request $request): void
    {
        $session = $request->session();

        foreach (self::SESSION_KEYS as $key) {
            $url = $session->get($key);

            if (is_string($url) && ($public = self::publicUrl($url)) !== $url) {
                $session->put($key, $public);
            }
        }
    }
}
