<?php

namespace App\Console\Commands;

use App\Models\NewsArticle;
use App\Models\PortfolioPosition;
use App\Models\Security;
use App\Models\WatchlistItem;
use App\Support\Portfolio\NewsFeed;
use App\Support\Portfolio\Notifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('portfolio:fetch-news {--securities=40 : How many held or watched securities are searched per run}')]
#[Description('Fetch finance news (market feeds and headlines per held or watched security) and push new ones (runs hourly via the scheduler)')]
class FetchPortfolioNews extends Command
{
    public function handle(NewsFeed $feed, Notifier $notifier): int
    {
        $stored = $feed->fetchMarket();
        $symbols = PortfolioPosition::distinct()->pluck('symbol')->merge(WatchlistItem::distinct()->pluck('symbol'))->unique();

        // Securities searched longest ago first, so that all get their turn.
        $lastFetched = NewsArticle::whereIn('symbol', $symbols)->groupBy('symbol')->selectRaw('symbol, MAX(created_at) as fetched')->pluck('fetched', 'symbol');
        $securities = Security::whereIn('symbol', $symbols)->where('symbol', 'not like', '^%')->get()
            ->sortBy(fn (Security $security) => $lastFetched[$security->symbol] ?? '')
            ->take((int) $this->option('securities'));

        foreach ($securities as $security) {
            $stored += $feed->fetchForSecurity($security);
        }

        // Keep three months of news.
        NewsArticle::where('published_at', '<', now()->subMonths(3))->delete();
        $pushed = $feed->notify($notifier);

        $this->info("New articles: {$stored}, notifications: {$pushed}");

        return self::SUCCESS;
    }
}
