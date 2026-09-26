<?php

namespace App\Console\Commands;

use App\Models\PortfolioPosition;
use App\Models\PortfolioSetting;
use App\Models\Security;
use App\Models\User;
use App\Models\WatchlistItem;
use App\Support\Portfolio\MarketData;
use App\Support\Portfolio\RecommendationEngine;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('portfolio:market-scan {--fundamentals=80 : How many securities get fresh key figures per run}')]
#[Description('Scan the market (Yahoo screeners), complete price history and key figures, then compute everyone\'s buy recommendations (runs daily via the scheduler)')]
class RunMarketScan extends Command
{
    public function handle(MarketData $market): int
    {
        $scanned = $market->scan();
        $this->line('Screener results: '.count($scanned));

        $own = PortfolioPosition::distinct()->pluck('symbol')->merge(WatchlistItem::distinct()->pluck('symbol'));
        $universe = Security::where('scanned_at', '>=', now()->subWeek())->orWhereIn('symbol', $own)->get();

        foreach ($universe as $security) {
            $market->history($security->symbol, now()->subYear());
        }

        $stale = $universe->filter(fn (Security $security) => ! $security->fundamentals_updated_at || $security->fundamentals_updated_at->lt(now()->subWeek()))
            ->sortBy(fn (Security $security) => $security->fundamentals_updated_at?->timestamp ?? 0)
            ->take((int) $this->option('fundamentals'));

        foreach ($stale as $security) {
            $market->fundamentals($security);
        }

        $users = User::whereHas('portfolios')->orWhereIn('id', PortfolioSetting::select('user_id'))->get();
        $recommendations = $users->sum(fn (User $user) => RecommendationEngine::refreshFor($user));

        $this->info("Universe: {$universe->count()}, key figures updated: {$stale->count()}, recommendations: {$recommendations}");

        return self::SUCCESS;
    }
}
