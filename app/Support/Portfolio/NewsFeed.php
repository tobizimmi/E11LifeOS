<?php

namespace App\Support\Portfolio;

use App\Enums\NotificationCategory;
use App\Models\NewsArticle;
use App\Models\PortfolioPosition;
use App\Models\PortfolioSetting;
use App\Models\Security;
use App\Models\WatchlistItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;
use Throwable;

/**
 * Finance news from RSS feeds (market news) and Google News (per security), stored as
 * news_articles; owners of a security can get new headlines as push notification.
 */
class NewsFeed
{
    /** @var array<string, string> name => RSS URL */
    public const FEEDS = [
        'finanzen.net' => 'https://www.finanzen.net/rss/news',
        'Börse Online' => 'https://www.boerse-online.de/rss/news.rss',
        'onvista' => 'https://www.onvista.de/news/feed',
        'finanztrends' => 'https://www.finanztrends.de/feed/',
        'MarketWatch' => 'https://feeds.content.dowjones.io/public/rss/mw_topstories',
    ];

    /**
     * Market news from all feeds.
     */
    public function fetchMarket(int $perFeed = 15): int
    {
        $stored = 0;

        foreach (self::FEEDS as $name => $url) {
            $stored += $this->store($this->read($url, $perFeed), null, $name);
        }

        return $stored;
    }

    /**
     * Headlines about one security (Google News by name and ISIN).
     */
    public function fetchForSecurity(Security $security, int $limit = 8): int
    {
        $name = trim(preg_replace('/\b(AG|SE|Inc\.?|Corp\.?|N\.V\.|plc|S\.A\.|Holding|Co\.|KGaA|& Co\.)\b/i', '', $security->name) ?? $security->name);
        $query = $security->isin ? $security->isin.' OR "'.$name.'"' : '"'.$name.'" Aktie';

        return $this->store($this->read('https://news.google.com/rss/search?'.http_build_query(['q' => $query, 'hl' => 'de', 'gl' => 'DE', 'ceid' => 'DE:de']), $limit), $security->symbol, 'Google News');
    }

    /**
     * Push new headlines about the securities of users who switched news notifications on.
     */
    public function notify(Notifier $notifier): int
    {
        $sent = 0;

        foreach (PortfolioSetting::where('news_push_enabled', true)->with('user')->get() as $settings) {
            $symbols = PortfolioPosition::whereHas('portfolio', fn ($query) => $query->where('user_id', $settings->user_id))->pluck('symbol');

            if (! $settings->news_push_portfolio_only) {
                $symbols = $symbols->merge(WatchlistItem::where('user_id', $settings->user_id)->pluck('symbol'));
            }

            $articles = NewsArticle::whereIn('symbol', $symbols->unique())->whereNull('notified_at')
                ->where('published_at', '>=', now()->subHours(max(1, $settings->news_push_max_age_hours ?? 24)))->orderByDesc('published_at')->get();

            if ($articles->isEmpty() || ! $settings->user) {
                continue;
            }

            $notifier->send(
                $settings->user,
                '📰 '.trans_choice(':count new headline about your securities|:count new headlines about your securities', $articles->count()),
                $articles->take(3)->map(fn (NewsArticle $article) => '• '.$article->symbol.': '.mb_strimwidth($article->title, 0, 110, '…'))->implode("\n"),
                route('portfolio.news', ['filter' => 'mine']),
                category: NotificationCategory::Important->value,
            );
            $sent++;
        }

        // Articles are announced once, even to several users.
        NewsArticle::whereNull('notified_at')->update(['notified_at' => now()]);

        return $sent;
    }

    /**
     * @param  list<array{title: string, url: string, published_at: ?Carbon, source: ?string, summary: ?string, thumbnail: ?string}>  $items
     */
    private function store(array $items, ?string $symbol, string $feed): int
    {
        $stored = 0;

        foreach ($items as $item) {
            $hash = sha1(strtok($item['url'], '?').'|'.$symbol);

            if (NewsArticle::where('url_hash', $hash)->exists()) {
                continue;
            }

            NewsArticle::create([
                'symbol' => $symbol,
                'title' => mb_substr($item['title'], 0, 600),
                'url' => mb_substr($item['url'], 0, 1000),
                'url_hash' => $hash,
                'source' => $item['source'] ? mb_substr($item['source'], 0, 100) : $feed,
                'feed' => $feed,
                'summary' => $item['summary'],
                'thumbnail' => $item['thumbnail'] ? mb_substr($item['thumbnail'], 0, 1000) : null,
                'published_at' => $item['published_at'] ?? now(),
            ]);
            $stored++;
        }

        return $stored;
    }

    /**
     * @return list<array{title: string, url: string, published_at: ?Carbon, source: ?string, summary: ?string, thumbnail: ?string}>
     */
    public function read(string $url, int $limit): array
    {
        try {
            $response = Http::withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; E11LifeOS/1.0)'])->timeout(10)->get($url);
        } catch (Throwable) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        return self::parse($response->body(), $limit);
    }

    /**
     * RSS 2.0 or Atom → items.
     *
     * @return list<array{title: string, url: string, published_at: ?Carbon, source: ?string, summary: ?string, thumbnail: ?string}>
     */
    public static function parse(string $xml, int $limit = 20): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NONET);
        libxml_use_internal_errors($previous);

        if (! $document) {
            return [];
        }

        $entries = isset($document->channel) ? $document->channel->item : $document->entry;
        $items = [];

        foreach ($entries as $entry) {
            $link = (string) ($entry->link['href'] ?? '') ?: trim((string) $entry->link);
            $title = trim(html_entity_decode(strip_tags((string) $entry->title), ENT_QUOTES | ENT_HTML5));

            if ($title === '' || ! str_starts_with($link, 'http')) {
                continue;
            }

            $date = (string) ($entry->pubDate ?? '') ?: (string) ($entry->updated ?? $entry->published ?? '');
            $media = $entry->children('http://search.yahoo.com/mrss/');
            $thumbnail = isset($media->content) ? (string) $media->content->attributes()->url : (isset($media->thumbnail) ? (string) $media->thumbnail->attributes()->url : null);
            $thumbnail ??= isset($entry->enclosure) && str_starts_with((string) $entry->enclosure['type'], 'image') ? (string) $entry->enclosure['url'] : null;
            $summary = trim(html_entity_decode(strip_tags((string) ($entry->description ?? $entry->summary ?? '')), ENT_QUOTES | ENT_HTML5));

            try {
                $published = $date !== '' ? Carbon::parse($date) : null;
            } catch (Throwable) {
                $published = null;
            }

            $items[] = [
                'title' => $title,
                'url' => $link,
                'published_at' => $published?->isFuture() ? now() : $published,
                'source' => isset($entry->source) ? trim((string) $entry->source) : null,
                'summary' => $summary !== '' && $summary !== $title ? mb_substr($summary, 0, 500) : null,
                'thumbnail' => $thumbnail ?: null,
            ];

            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }
}
