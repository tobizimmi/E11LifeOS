<?php

namespace App\Support;

use App\Support\Security\UrlGuard;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Reads the public metadata of a web page (OpenGraph / Twitter cards / <title> and meta
 * description) so a saved link shows a title, description, site name and candidate preview images.
 * Fetching is SSRF-guarded via {@see UrlGuard}; it only reads meta tags, never executes anything.
 */
class LinkPreview
{
    /**
     * @return array{url: string, title: ?string, description: ?string, site_name: ?string, favicon: ?string, images: list<string>}
     */
    public static function fetch(string $url): array
    {
        if (! UrlGuard::hasValidShape($url) || ! UrlGuard::isPublicHost((string) parse_url($url, PHP_URL_HOST))) {
            throw new RuntimeException(__('This address cannot be loaded.'));
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; E11LifeOS link preview)',
                    'Accept' => 'text/html,application/xhtml+xml',
                    'Accept-Language' => 'de,en;q=0.8',
                ])
                ->withOptions(['allow_redirects' => [
                    'max' => 5,
                    'on_redirect' => function ($request, $response, $uri): void {
                        if (! UrlGuard::isPublicHost((string) parse_url((string) $uri, PHP_URL_HOST))) {
                            throw new RuntimeException('redirect to a non-public host');
                        }
                    },
                ]])
                ->get($url)
                ->throw();
        } catch (Throwable) {
            throw new RuntimeException(__('The web page could not be loaded. You can enter the details by hand.'));
        }

        return self::parse(mb_substr($response->body(), 0, 600000), $url);
    }

    /**
     * The readable text of the page (for an AI summary), SSRF-guarded; empty on any problem.
     */
    public static function fetchText(string $url, int $maxChars = 12000): string
    {
        if (! UrlGuard::hasValidShape($url) || ! UrlGuard::isPublicHost((string) parse_url($url, PHP_URL_HOST))) {
            return '';
        }

        try {
            $html = Http::timeout(15)->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; E11LifeOS link preview)'])->get($url)->throw()->body();
        } catch (Throwable) {
            return '';
        }

        return self::text($html, $maxChars);
    }

    /**
     * Strips scripts/markup from HTML and returns the collapsed visible text.
     */
    public static function text(string $html, int $maxChars = 12000): string
    {
        $html = preg_replace('#<(script|style|noscript|svg|nav|footer|header)\b[^>]*>.*?</\1>#si', ' ', $html) ?? $html;

        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html)))), 0, $maxChars);
    }

    /**
     * Extracts the preview metadata from already-fetched HTML (no network – testable on its own).
     *
     * @return array{url: string, title: ?string, description: ?string, site_name: ?string, favicon: ?string, images: list<string>}
     */
    public static function parse(string $html, string $url): array
    {
        return [
            'url' => $url,
            'title' => self::meta($html, ['og:title', 'twitter:title']) ?? self::title($html),
            'description' => self::meta($html, ['og:description', 'twitter:description', 'description']),
            'site_name' => self::meta($html, ['og:site_name']) ?? (parse_url($url, PHP_URL_HOST) ?: null),
            'favicon' => self::favicon($html, $url),
            'images' => self::images($html, $url),
        ];
    }

    /**
     * The first content of any of the given OpenGraph/meta property names.
     *
     * @param  list<string>  $properties
     */
    private static function meta(string $html, array $properties): ?string
    {
        foreach ($properties as $property) {
            $quoted = preg_quote($property, '#');

            foreach ([
                '#<meta[^>]*(?:name|property)=["\']'.$quoted.'["\'][^>]*content=["\']([^"\']+)["\']#i',
                '#<meta[^>]*content=["\']([^"\']+)["\'][^>]*(?:name|property)=["\']'.$quoted.'["\']#i',
            ] as $pattern) {
                if (preg_match($pattern, $html, $match) && trim($match[1]) !== '') {
                    return trim(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5));
                }
            }
        }

        return null;
    }

    private static function title(string $html): ?string
    {
        return preg_match('#<title[^>]*>(.*?)</title>#si', $html, $match)
            ? trim(html_entity_decode(preg_replace('/\s+/', ' ', $match[1]), ENT_QUOTES | ENT_HTML5))
            : null;
    }

    /**
     * Candidate preview images from the page's meta tags (OpenGraph/Twitter/link image_src),
     * de-duplicated and made absolute.
     *
     * @return list<string>
     */
    private static function images(string $html, string $base): array
    {
        $urls = [];

        preg_match_all('#<meta[^>]*(?:name|property)=["\'](?:og:image(?::url)?|twitter:image(?::src)?)["\'][^>]*content=["\']([^"\']+)["\']#i', $html, $matches);
        $urls = array_merge($urls, $matches[1]);

        if (preg_match('#<link[^>]*rel=["\']image_src["\'][^>]*href=["\']([^"\']+)["\']#i', $html, $m)) {
            $urls[] = $m[1];
        }

        return collect($urls)
            ->map(fn (string $u) => self::absolute(trim(html_entity_decode($u, ENT_QUOTES | ENT_HTML5)), $base))
            ->filter(fn (string $u) => UrlGuard::hasValidShape($u))
            ->unique()
            ->take(8)
            ->values()
            ->all();
    }

    private static function favicon(string $html, string $base): ?string
    {
        if (preg_match('#<link[^>]*rel=["\'][^"\']*icon[^"\']*["\'][^>]*href=["\']([^"\']+)["\']#i', $html, $m)) {
            $icon = self::absolute(trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5)), $base);

            return UrlGuard::hasValidShape($icon) ? $icon : null;
        }

        $parts = parse_url($base);

        return isset($parts['scheme'], $parts['host']) ? $parts['scheme'].'://'.$parts['host'].'/favicon.ico' : null;
    }

    private static function absolute(string $url, string $base): string
    {
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';

        if (str_starts_with($url, '//')) {
            return $scheme.':'.$url;
        }

        return $scheme.'://'.($parts['host'] ?? '').'/'.ltrim($url, '/');
    }
}
