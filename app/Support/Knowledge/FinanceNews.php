<?php

namespace App\Support\Knowledge;

use App\Support\Portfolio\NewsFeed;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Current news on taxes, stock market, financing and new laws for the knowledge area
 * (Google News search, cached for a few hours per topic).
 */
class FinanceNews
{
    /** @var array<string, array{icon: string, query: string}> */
    public const TOPICS = [
        'taxes' => ['icon' => '🧾', 'query' => '(Steuer OR Steuererklärung OR Freibetrag OR Finanzamt) Änderung 2026'],
        'stocks' => ['icon' => '📈', 'query' => '(Börse OR DAX OR ETF OR Aktien) Anleger'],
        'financing' => ['icon' => '🏦', 'query' => '(Bauzinsen OR Baufinanzierung OR KfW-Förderung OR Kredit Zinsen)'],
        'laws' => ['icon' => '⚖️', 'query' => '(neues Gesetz OR Neuregelung OR "ab 2026") (Rente OR Steuer OR Finanzen OR Verbraucher)'],
    ];

    public const CACHE_HOURS = 3;

    public function __construct(private NewsFeed $feed) {}

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'taxes' => __('Taxes'),
            'stocks' => __('Stock market'),
            'financing' => __('Financing'),
            'laws' => __('New rules'),
        ];
    }

    /**
     * @return list<array{title: string, url: string, published_at: ?Carbon, source: ?string, summary: ?string, key: string}>
     */
    public function for(string $topic, int $limit = 15): array
    {
        if (! isset(self::TOPICS[$topic])) {
            return [];
        }

        $items = Cache::remember('knowledge-news:'.$topic, now()->addHours(self::CACHE_HOURS), fn () => $this->feed->read(
            'https://news.google.com/rss/search?'.http_build_query(['q' => self::TOPICS[$topic]['query'].' when:30d', 'hl' => 'de', 'gl' => 'DE', 'ceid' => 'DE:de']),
            $limit,
        ));

        return array_map(fn (array $item) => $item + ['key' => self::key($item['url'])], $items);
    }

    /**
     * Stable favourite key of a news article.
     */
    public static function key(string $url): string
    {
        return md5($url);
    }
}
