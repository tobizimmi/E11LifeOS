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
use Throwable;

#[Signature('portfolio:market-scan {--fundamentals=80 : How many securities get fresh key figures per run}')]
#[Description('Scan the market (Yahoo screeners), complete price history and key figures, then compute everyone\'s buy recommendations (runs daily via the scheduler)')]
class RunMarketScan extends Command
{
    public function handle(MarketData $market): int
    {
        // Price histories and recommendations of a few hundred securities need more than the
        // default 128 MB of the command line PHP (the run ended with a fatal error there).
        if ($this->bytes((string) ini_get('memory_limit')) < 1024 ** 3) {
            ini_set('memory_limit', '1024M');
        }

        $scanned = $market->scan();
        $this->line('Screener results: '.count($scanned));

        $own = PortfolioPosition::distinct()->pluck('symbol')->merge(WatchlistItem::distinct()->pluck('symbol'));
        $universe = Security::where('scanned_at', '>=', now()->subWeek())->orWhereIn('symbol', $own)->get();

        foreach ($universe as $security) {
            try {
                $market->history($security->symbol, now()->subYear());
            } catch (Throwable $exception) {
                report($exception); // One broken symbol must not stop the scan.
            }
        }

        // Key figures loaded before insider trades and analyst votes were added count as stale too.
        $stale = $universe->filter(fn (Security $security) => ! $security->fundamentals_updated_at || $security->fundamentals_updated_at->lt(now()->subWeek())
            || ($security->fundamentals !== null && ! array_key_exists('analyst_votes', $security->fundamentals)))
            ->sortBy(fn (Security $security) => $security->fundamentals_updated_at?->timestamp ?? 0)
            ->take((int) $this->option('fundamentals'));

        foreach ($stale as $security) {
            try {
                $market->fundamentals($security);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $users = User::whereHas('portfolios')->orWhereIn('id', PortfolioSetting::select('user_id'))->get();
        $recommendations = $users->sum(fn (User $user) => RecommendationEngine::refreshFor($user));

        $this->info("Universe: {$universe->count()}, key figures updated: {$stale->count()}, recommendations: {$recommendations}");

        return self::SUCCESS;
    }

    /**
     * "128M" → bytes; -1 (unlimited) counts as very large.
     */
    private function bytes(string $limit): int
    {
        if ($limit === '-1') {
            return PHP_INT_MAX;
        }

        $value = (int) $limit;

        return match (strtolower(substr(trim($limit), -1))) {
            'g' => $value * 1024 ** 3,
            'm' => $value * 1024 ** 2,
            'k' => $value * 1024,
            default => $value,
        };
    }
}
