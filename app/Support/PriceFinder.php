<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Reads the current price from a product page (the link saved on a wish). It looks at the machine
 * readable data most shops embed – schema.org JSON-LD offers, OpenGraph/product meta tags and
 * microdata – rather than scraping visible text, so it stays robust across shops. It tracks the
 * price at that one link; it does not search the whole web for a cheaper offer.
 */
class PriceFinder
{
    /**
     * Fetches the page and returns the price found, or null when nothing readable is there.
     *
     * @return array{price: float, currency: ?string}|null
     */
    public static function fetch(string $url): ?array
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; E11LifeOS price watch)',
                'Accept' => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'de,en;q=0.8',
            ])->timeout(15)->retry(1, 500)->get($url);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        return self::fromHtml($response->body());
    }

    /**
     * Extracts a price from HTML: JSON-LD offers first, then OpenGraph/product and microdata meta.
     *
     * @return array{price: float, currency: ?string}|null
     */
    public static function fromHtml(string $html): ?array
    {
        return self::fromJsonLd($html) ?? self::fromMeta($html);
    }

    /**
     * @return array{price: float, currency: ?string}|null
     */
    private static function fromJsonLd(string $html): ?array
    {
        if (! preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches)) {
            return null;
        }

        foreach ($matches[1] as $block) {
            $data = json_decode(trim($block), true);

            if (! is_array($data)) {
                continue;
            }

            // A block can be a single node, a @graph, or a list of nodes.
            $nodes = isset($data['@graph']) && is_array($data['@graph']) ? $data['@graph'] : [$data];

            foreach ($nodes as $node) {
                if (($found = self::offerPrice($node)) !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * @param  mixed  $node
     * @return array{price: float, currency: ?string}|null
     */
    private static function offerPrice($node): ?array
    {
        if (! is_array($node)) {
            return null;
        }

        // Offers may be a single object or a list; the node itself may also be an Offer or a
        // Product carrying a direct price.
        $candidates = [];

        if (isset($node['offers'])) {
            $offers = $node['offers'];
            $candidates = is_array($offers) && array_is_list($offers) ? $offers : [$offers];
        }

        $candidates[] = $node;

        foreach ($candidates as $offer) {
            if (! is_array($offer)) {
                continue;
            }

            $raw = $offer['price'] ?? $offer['lowPrice'] ?? null;

            if ($raw === null || ($price = self::parsePrice((string) $raw)) === null) {
                continue;
            }

            $currency = $offer['priceCurrency'] ?? $node['priceCurrency'] ?? null;

            return ['price' => $price, 'currency' => is_string($currency) ? mb_strtoupper(mb_substr($currency, 0, 3)) : null];
        }

        return null;
    }

    /**
     * @return array{price: float, currency: ?string}|null
     */
    private static function fromMeta(string $html): ?array
    {
        $amount = self::metaContent($html, ['product:price:amount', 'og:price:amount', 'twitter:data1'])
            ?? self::itempropContent($html, 'price');

        if ($amount === null) {
            return null;
        }

        $price = self::parsePrice($amount);

        if ($price === null) {
            return null;
        }

        $currency = self::metaContent($html, ['product:price:currency', 'og:price:currency']) ?? self::itempropContent($html, 'priceCurrency');

        return ['price' => $price, 'currency' => $currency !== null ? mb_strtoupper(mb_substr($currency, 0, 3)) : null];
    }

    /**
     * @param  list<string>  $properties
     */
    private static function metaContent(string $html, array $properties): ?string
    {
        foreach ($properties as $property) {
            $quoted = preg_quote($property, '#');
            if (preg_match('#<meta[^>]+(?:property|name)=["\']'.$quoted.'["\'][^>]+content=["\']([^"\']+)["\']#i', $html, $match)
                || preg_match('#<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:property|name)=["\']'.$quoted.'["\']#i', $html, $match)) {
                return trim($match[1]);
            }
        }

        return null;
    }

    private static function itempropContent(string $html, string $prop): ?string
    {
        $quoted = preg_quote($prop, '#');
        if (preg_match('#<[^>]+itemprop=["\']'.$quoted.'["\'][^>]+content=["\']([^"\']+)["\']#i', $html, $match)
            || preg_match('#<[^>]+content=["\']([^"\']+)["\'][^>]+itemprop=["\']'.$quoted.'["\']#i', $html, $match)) {
            return trim($match[1]);
        }

        return null;
    }

    /**
     * Parses a price string in either German ("1.299,00 €") or machine/English ("1299.00") format.
     */
    public static function parsePrice(string $value): ?float
    {
        $clean = preg_replace('/[^0-9.,]/', '', $value) ?? '';

        if ($clean === '') {
            return null;
        }

        $lastComma = strrpos($clean, ',');
        $lastDot = strrpos($clean, '.');

        if ($lastComma !== false && $lastDot !== false) {
            // The separator that comes last is the decimal point; the other groups thousands.
            $clean = $lastComma > $lastDot
                ? str_replace(',', '.', str_replace('.', '', $clean))
                : str_replace(',', '', $clean);
        } elseif ($lastComma !== false) {
            // Only commas: a comma with 1–2 trailing digits is a decimal separator, else thousands.
            $clean = preg_match('/,\d{1,2}$/', $clean) ? str_replace(',', '.', str_replace('.', '', $clean)) : str_replace(',', '', $clean);
        }

        if (! is_numeric($clean)) {
            return null;
        }

        $price = round((float) $clean, 2);

        return $price > 0 ? $price : null;
    }
}
