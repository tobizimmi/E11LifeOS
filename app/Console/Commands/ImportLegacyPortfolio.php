<?php

namespace App\Console\Commands;

use App\Models\Portfolio;
use App\Models\PortfolioSetting;
use App\Models\PriceAlert;
use App\Models\TargetAllocation;
use App\Models\User;
use App\Models\WatchlistItem;
use App\Support\Portfolio\Positions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('portfolio:import-legacy
    {email : E-mail address of the LifeOS account that receives the data}
    {--legacy-email= : E-mail of the account in the old portfolio manager (default: the same)}
    {--connection=portfolio_legacy : Database connection of the old portfolio manager}
    {--without-prices : Skip the price history (can be large)}')]
#[Description('Import portfolios, transactions, watchlist, settings, savings plans, alerts and price history from the old portfolio manager database (safe to run again)')]
class ImportLegacyPortfolio extends Command
{
    private ConnectionInterface $legacy;

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error('No LifeOS account with this e-mail address.');

            return self::FAILURE;
        }

        try {
            $this->legacy = DB::connection((string) $this->option('connection'));
            $legacyUser = $this->legacy->table('users')->where('email', $this->option('legacy-email') ?: $user->email)->first();
        } catch (Throwable $exception) {
            $this->error('The old database is not reachable: '.$exception->getMessage());

            return self::FAILURE;
        }

        if (! $legacyUser) {
            $this->error('No account with this e-mail address in the old portfolio manager.');

            return self::FAILURE;
        }

        $this->importSecurities();
        $portfolioMap = $this->importPortfolios($user, (int) $legacyUser->id);
        $this->importUserData($user, (int) $legacyUser->id, $portfolioMap);

        if (! $this->option('without-prices')) {
            $this->importPriceHistory();
        }

        $this->info('Done. Instagram analyses and bank connections follow with their modules.');

        return self::SUCCESS;
    }

    private function hasTable(string $table): bool
    {
        return $this->legacy->getSchemaBuilder()->hasTable($table);
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->legacy->getSchemaBuilder()->hasColumn($table, $column);
    }

    /**
     * Master data of securities and the ISIN/WKN mapping cache.
     */
    private function importSecurities(): void
    {
        if ($this->hasTable('stocks')) {
            $count = 0;

            $this->legacy->table('stocks')->orderBy('id')->chunk(500, function ($stocks) use (&$count) {
                $rows = $stocks->map(fn ($stock) => [
                    'symbol' => mb_substr(strtoupper((string) $stock->symbol), 0, 32),
                    'name' => (string) ($stock->name ?? ''),
                    'isin' => isset($stock->isin) && strlen((string) $stock->isin) === 12 ? $stock->isin : null,
                    'wkn' => isset($stock->wkn) && $stock->wkn !== '' ? mb_substr($stock->wkn, 0, 10) : null,
                    'exchange' => ($stock->exchange ?? '') !== '' ? mb_substr((string) $stock->exchange, 0, 64) : null,
                    'asset_type' => in_array($stock->asset_type ?? 'stock', ['stock', 'etf', 'fund', 'crypto', 'bond', 'other'], true) ? $stock->asset_type : 'other',
                    'currency' => isset($stock->currency) && strlen((string) $stock->currency) === 3 ? $stock->currency : null,
                    'sector' => $stock->sector ?? null,
                    'country' => $stock->country ?? null,
                    'market_cap' => $stock->market_cap ?? null,
                    'week_52_high' => $stock->week_52_high ?? null,
                    'week_52_low' => $stock->week_52_low ?? null,
                    'dividend_yield' => $stock->dividend_yield ?? null,
                    'beta' => $stock->beta ?? null,
                    'last_price' => $stock->last_price ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->filter(fn (array $row) => $row['symbol'] !== '')->unique('symbol')->values()->all();

                DB::table('securities')->upsert($rows, ['symbol'], ['name', 'isin', 'wkn', 'exchange', 'asset_type', 'sector', 'country', 'market_cap', 'week_52_high', 'week_52_low', 'dividend_yield', 'beta', 'updated_at']);
                $count += count($rows);
            });

            $this->line("Securities: {$count}");
        }

        if ($this->hasTable('symbol_mappings')) {
            $rows = $this->legacy->table('symbol_mappings')->get()->map(fn ($mapping) => [
                'input_key' => mb_substr(strtoupper((string) $mapping->input_key), 0, 20),
                'symbol' => $mapping->mapped_symbol ? mb_substr(strtoupper((string) $mapping->mapped_symbol), 0, 32) : null,
                'data' => $mapping->mapped_data,
                'created_at' => now(),
                'updated_at' => now(),
            ])->unique('input_key')->values();

            foreach ($rows->chunk(500) as $chunk) {
                DB::table('security_mappings')->upsert($chunk->all(), ['input_key'], ['symbol', 'data', 'updated_at']);
            }

            $this->line("ISIN/WKN mappings: {$rows->count()}");
        }
    }

    /**
     * @return array<int, Portfolio> legacy portfolio id → portfolio
     */
    private function importPortfolios(User $user, int $legacyUserId): array
    {
        $map = [];
        $hasWkn = $this->hasColumn('transactions', 'wkn');

        foreach ($this->legacy->table('portfolios')->where('user_id', $legacyUserId)->orderBy('id')->get() as $legacyPortfolio) {
            $portfolio = Portfolio::updateOrCreate(
                ['user_id' => $user->id, 'legacy_id' => $legacyPortfolio->id],
                [
                    'name' => mb_substr((string) $legacyPortfolio->name, 0, 100),
                    'description' => $legacyPortfolio->description ?? null,
                    'broker' => $legacyPortfolio->broker ?? null,
                    'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($legacyPortfolio->color ?? '')) ? $legacyPortfolio->color : '#4f46e5',
                ],
            );
            $map[(int) $legacyPortfolio->id] = $portfolio;

            // Replace the transactions: running the import again gives the same result.
            $portfolio->transactions()->delete();
            $transactions = $this->legacy->table('transactions')->where('portfolio_id', $legacyPortfolio->id)->orderBy('id')->get();

            foreach ($transactions->chunk(500) as $chunk) {
                DB::table('portfolio_transactions')->insert($chunk->map(fn ($transaction) => [
                    'portfolio_id' => $portfolio->id,
                    'symbol' => mb_substr(strtoupper((string) $transaction->symbol), 0, 32),
                    'name' => (string) ($transaction->name ?? ''),
                    'isin' => isset($transaction->isin) && strlen((string) $transaction->isin) === 12 ? strtoupper($transaction->isin) : null,
                    'wkn' => $hasWkn && ($transaction->wkn ?? '') !== '' ? mb_substr($transaction->wkn, 0, 10) : null,
                    'type' => $transaction->type,
                    'quantity' => (float) $transaction->quantity,
                    'price' => (float) $transaction->price,
                    'fees' => (float) ($transaction->fees ?? 0),
                    'taxes' => (float) ($transaction->taxes ?? 0),
                    'currency' => strtoupper((string) ($transaction->currency ?? 'EUR')) ?: 'EUR',
                    'exchange_rate' => (float) ($transaction->exchange_rate ?? 1) ?: 1,
                    'executed_on' => $transaction->transaction_date,
                    'broker' => $transaction->broker ?? null,
                    'notes' => $transaction->notes ?? null,
                    'import_source' => 'legacy',
                    'created_at' => $transaction->created_at ?? now(),
                    'updated_at' => now(),
                ])->all());
            }

            Positions::recalculate($portfolio);
            $this->restorePositionDetails($portfolio, (int) $legacyPortfolio->id);
            $this->importSnapshots($portfolio, (int) $legacyPortfolio->id);

            $this->line("Portfolio „{$portfolio->name}“: {$transactions->count()} transactions, {$portfolio->positions()->count()} positions");
        }

        return $map;
    }

    /**
     * Positions entered by hand in the old app and labels, notes and price marks.
     */
    private function restorePositionDetails(Portfolio $portfolio, int $legacyPortfolioId): void
    {
        $existing = $portfolio->positions()->get()->keyBy('symbol');

        foreach ($this->legacy->table('positions')->where('portfolio_id', $legacyPortfolioId)->get() as $legacyPosition) {
            $symbol = mb_substr(strtoupper((string) $legacyPosition->symbol), 0, 32);
            $extras = array_filter([
                'notes' => $legacyPosition->notes ?? null,
                'labels' => isset($legacyPosition->labels) && $legacyPosition->labels !== '' ? array_values(array_filter(array_map('trim', explode(',', (string) $legacyPosition->labels)))) : null,
                'stop_loss_price' => $legacyPosition->stop_loss_price ?? null,
                'take_profit_price' => $legacyPosition->take_profit_price ?? null,
            ], fn ($value) => $value !== null && $value !== []);

            if ($existing->has($symbol)) {
                $existing[$symbol]->update($extras);
            } elseif ((float) $legacyPosition->quantity > 0) {
                $portfolio->positions()->create([
                    'symbol' => $symbol,
                    'name' => (string) ($legacyPosition->name ?? $symbol),
                    'isin' => isset($legacyPosition->isin) && strlen((string) $legacyPosition->isin) === 12 ? $legacyPosition->isin : null,
                    'quantity' => (float) $legacyPosition->quantity,
                    'avg_buy_price' => (float) $legacyPosition->avg_buy_price,
                    'currency' => $legacyPosition->currency ?? 'EUR',
                    'asset_type' => $legacyPosition->asset_type ?? 'stock',
                    'manual' => true,
                ] + $extras);
            }
        }
    }

    private function importSnapshots(Portfolio $portfolio, int $legacyPortfolioId): void
    {
        if (! $this->hasTable('portfolio_snapshots')) {
            return;
        }

        $hasTime = $this->hasColumn('portfolio_snapshots', 'snapshot_at');
        $portfolio->snapshots()->delete();

        $this->legacy->table('portfolio_snapshots')->where('portfolio_id', $legacyPortfolioId)->orderBy('id')->chunk(1000, function ($snapshots) use ($portfolio, $hasTime) {
            DB::table('portfolio_snapshots')->insert($snapshots->map(fn ($snapshot) => [
                'portfolio_id' => $portfolio->id,
                'taken_at' => $hasTime && $snapshot->snapshot_at ? $snapshot->snapshot_at : Carbon::parse($snapshot->snapshot_date)->setTime(22, 0),
                'total_value' => (float) $snapshot->total_value,
                'total_cost' => (float) $snapshot->total_cost,
            ])->all());
        });
    }

    /**
     * @param  array<int, Portfolio>  $portfolioMap
     */
    private function importUserData(User $user, int $legacyUserId, array $portfolioMap): void
    {
        if ($this->hasTable('watchlist')) {
            $items = $this->legacy->table('watchlist')->where('user_id', $legacyUserId)->get();

            foreach ($items as $item) {
                WatchlistItem::updateOrCreate(
                    ['user_id' => $user->id, 'symbol' => mb_substr(strtoupper((string) $item->symbol), 0, 32)],
                    ['name' => (string) ($item->name ?? ''), 'notes' => $item->notes ?? null],
                );
            }

            $this->line("Watchlist: {$items->count()}");
        }

        if ($this->hasTable('user_settings') && ($legacy = $this->legacy->table('user_settings')->where('user_id', $legacyUserId)->first())) {
            PortfolioSetting::for($user)->fill(array_filter([
                'default_portfolio_id' => isset($legacy->default_portfolio_id) ? ($portfolioMap[(int) $legacy->default_portfolio_id] ?? null)?->id : null,
                'strategy' => in_array($legacy->strategy ?? null, PortfolioSetting::STRATEGIES, true) ? $legacy->strategy : null,
                'drop_threshold' => $legacy->drop_threshold ?? null,
                'drop_period_days' => $legacy->drop_period_days ?? null,
                'min_stability_score' => $legacy->min_stability_score ?? null,
                'min_market_cap_bn' => $legacy->min_market_cap_bn ?? null,
                'rsi_threshold' => $legacy->rsi_threshold ?? null,
                'max_pe_ratio' => $legacy->max_pe_ratio ?? null,
                'max_debt_equity' => $legacy->max_debt_equity ?? null,
                'check_200ma' => isset($legacy->check_200ma) ? (bool) $legacy->check_200ma : null,
                'prefer_dividends' => isset($legacy->prefer_dividends) ? (bool) $legacy->prefer_dividends : null,
                'benchmark_symbol' => $legacy->benchmark_symbol ?? null,
                'tax_allowance' => $legacy->freistellungsauftrag ?? null,
                'pushover_user_key' => $legacy->pushover_user_key ?? null,
                'pushover_app_token' => $legacy->pushover_app_token ?? null,
                'news_push_enabled' => isset($legacy->news_push_enabled) ? (bool) $legacy->news_push_enabled : null,
                'news_push_portfolio_only' => isset($legacy->news_push_portfolio_only) ? (bool) $legacy->news_push_portfolio_only : null,
            ], fn ($value) => $value !== null && $value !== ''))->save();

            $this->line('Settings imported.');
        }

        if ($this->hasTable('savings_plans')) {
            foreach ($portfolioMap as $legacyId => $portfolio) {
                $portfolio->savingsPlans()->delete();

                foreach ($this->legacy->table('savings_plans')->where('portfolio_id', $legacyId)->get() as $plan) {
                    $portfolio->savingsPlans()->create([
                        'symbol' => mb_substr(strtoupper((string) $plan->symbol), 0, 32),
                        'name' => $plan->name ?? null,
                        'amount' => (float) $plan->amount_eur,
                        'frequency' => $plan->frequency ?? 'monthly',
                        'day_of_month' => (int) ($plan->day_of_month ?? 1),
                        'next_date' => $plan->next_date,
                        'active' => (bool) ($plan->active ?? true),
                    ]);
                }
            }
        }

        if ($this->hasTable('target_allocations')) {
            foreach ($this->legacy->table('target_allocations')->where('user_id', $legacyUserId)->get() as $target) {
                TargetAllocation::updateOrCreate(
                    ['user_id' => $user->id, 'symbol' => mb_substr(strtoupper((string) $target->symbol), 0, 32)],
                    ['name' => $target->name ?? null, 'target_percent' => (float) $target->target_pct],
                );
            }
        }

        if ($this->hasTable('price_alerts')) {
            PriceAlert::where('user_id', $user->id)->delete();

            foreach ($this->legacy->table('price_alerts')->where('user_id', $legacyUserId)->get() as $alert) {
                PriceAlert::create([
                    'user_id' => $user->id,
                    'symbol' => mb_substr(strtoupper((string) $alert->symbol), 0, 32),
                    'name' => $alert->name ?? null,
                    'condition' => $alert->condition,
                    'target_price' => (float) $alert->target_price,
                    'currency' => $alert->currency ?? 'EUR',
                    'triggered_at' => ($alert->triggered ?? false) ? ($alert->triggered_at ?? now()) : null,
                    'active' => (bool) ($alert->active ?? true),
                ]);
            }
        }

        $this->line('Savings plans, target allocations and price alerts imported.');
    }

    private function importPriceHistory(): void
    {
        if (! $this->hasTable('price_history')) {
            return;
        }

        $count = 0;

        $this->legacy->table('price_history')->orderBy('id')->chunkById(2000, function ($prices) use (&$count) {
            DB::table('security_prices')->upsert($prices->map(fn ($price) => [
                'symbol' => mb_substr(strtoupper((string) $price->symbol), 0, 32),
                'date' => $price->date,
                'open' => $price->open ?? null,
                'high' => $price->high ?? null,
                'low' => $price->low ?? null,
                'close' => (float) $price->close,
                'volume' => $price->volume ?? null,
            ])->unique(fn (array $row) => $row['symbol'].'|'.$row['date'])->values()->all(), ['symbol', 'date'], ['open', 'high', 'low', 'close', 'volume']);
            $count += $prices->count();
        });

        $this->line("Price history: {$count} daily prices");
    }
}
