<?php

namespace App\Support;

/**
 * Ready-made areas for the bookmarks module and a rule-based guess of the fitting area for a link
 * (from its domain and title) – the "suggest area" button, offline and deterministic.
 */
class BookmarkAreas
{
    /**
     * Starter areas offered as one-click chips.
     *
     * @var list<array{name: string, icon: string}>
     */
    public const SUGGESTED = [
        ['name' => 'Einkäufe', 'icon' => '🛍️'],
        ['name' => 'Geschenke', 'icon' => '🎁'],
        ['name' => 'Videos', 'icon' => '🎬'],
        ['name' => 'Rezepte', 'icon' => '🍳'],
        ['name' => 'Reise', 'icon' => '✈️'],
        ['name' => 'Lernen', 'icon' => '💡'],
        ['name' => 'Restaurants', 'icon' => '🍽️'],
        ['name' => 'Zeitschriften', 'icon' => '📰'],
        ['name' => 'Artikel', 'icon' => '📄'],
    ];

    /**
     * Each rule: keywords (matched against the lowercased URL + title) → area.
     *
     * @var list<array{0: list<string>, 1: string, 2: string}>
     */
    private const RULES = [
        [['youtube.', 'youtu.be', 'vimeo.', 'twitch.', 'netflix.', 'mediathek', 'tiktok.'], 'Videos', '🎬'],
        [['chefkoch', 'rezept', 'recipe', 'cookidoo', 'eatsmarter', 'kochbar', 'lecker.de'], 'Rezepte', '🍳'],
        [['booking.', 'airbnb', 'expedia', 'trivago', 'hrs.de', 'hotel', '/reise', 'travel', 'getyourguide'], 'Reise', '✈️'],
        [['udemy', 'coursera', 'edx.org', 'khanacademy', 'skillshare', 'tutorial', '/kurs', 'learn', 'lernen', 'masterclass'], 'Lernen', '💡'],
        [['opentable', 'thefork', 'yelp.', 'restaurant', 'speisekarte', 'tripadvisor'], 'Restaurants', '🍽️'],
        [['geschenk', '/gift', 'gift-', 'present'], 'Geschenke', '🎁'],
        [['amazon.', 'ebay.', 'otto.de', 'zalando', 'aliexpress', 'etsy.', 'ikea.', 'mediamarkt', 'saturn.', '/produkt', '/product', 'shop.'], 'Einkäufe', '🛍️'],
        [['spiegel.', 'zeit.de', 'faz.net', 'sueddeutsche', 'nytimes', 'theguardian', 'medium.com', 'magazin', 'magazine', '/blog'], 'Zeitschriften', '📰'],
    ];

    /**
     * The best-matching area for a link, or null when nothing matches.
     *
     * @return array{name: string, icon: string}|null
     */
    public static function suggest(string $url, string $title = ''): ?array
    {
        $haystack = mb_strtolower($url.' '.$title);

        foreach (self::RULES as [$keywords, $name, $icon]) {
            foreach ($keywords as $keyword) {
                if (str_contains($haystack, $keyword)) {
                    return ['name' => $name, 'icon' => $icon];
                }
            }
        }

        return null;
    }
}
