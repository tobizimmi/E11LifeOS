<?php

namespace App\Console\Commands;

use App\Models\Portfolio;
use App\Models\PortfolioPosition;
use App\Models\PortfolioSetting;
use App\Models\Security;
use App\Models\WatchlistItem;
use App\Support\Portfolio\MarketData;
use App\Support\Portfolio\Valuation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('portfolio:update-prices {--history : Also complete the daily price history of every security}')]
#[Description('Fetch current prices of all held and watched securities and record the portfolio values (runs every 15 minutes via the scheduler)')]
class UpdatePortfolioPrices extends Command
{
    public function handle(MarketData $market, Valuation $valuation): int
    {
        $symbols = PortfolioPosition::distinct()->pluck('symbol')
            ->merge(WatchlistItem::distinct()->pluck('symbol'))
            ->merge(Security::where('tracked', true)->pluck('symbol'))
            ->merge(PortfolioSetting::whereNotNull('benchmark_symbol')->distinct()->pluck('benchmark_symbol'))
            ->merge(array_keys(MarketData::INDICES))
            ->unique()->values()->all();

        $quotes = $market->quotes($symbols, refresh: true);

        if ($this->option('history')) {
            foreach ($symbols as $symbol) {
                $market->history($symbol, now()->subYear());
            }
        }

        $snapshots = 0;

        Portfolio::whereHas('positions')->each(function (Portfolio $portfolio) use ($valuation, &$snapshots) {
            $snapshots += $valuation->snapshot($portfolio, throttleMinutes: 10) ? 1 : 0;
        });

        $this->info('Prices updated: '.count($quotes).'/'.count($symbols).", snapshots: {$snapshots}");

        return self::SUCCESS;
    }
}
