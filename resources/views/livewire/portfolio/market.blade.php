<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Livewire\Concerns\InteractsWithPortfolio;
use App\Models\PortfolioPosition;
use App\Models\Security;
use App\Support\Portfolio\MarketAnalysis;
use App\Support\Portfolio\MarketData;
use App\Support\Portfolio\StockScore;
use App\Support\Portfolio\TechnicalAnalysis;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Market analysis from the top down: the global situation, the sectors and the own shares rated
 * with the points system (company figures, chart, sector, market).
 */
new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;
    use InteractsWithPortfolio;

    /** Set once the page is visible – fetching the market data takes a moment. */
    public bool $ready = false;

    public function load(): void
    {
        $this->ready = true;
    }

    public function with(MarketData $market): array
    {
        if (! $this->ready) {
            return ['global' => null, 'sectors' => [], 'scores' => collect()];
        }

        $global = MarketAnalysis::global($market);
        $sectors = MarketAnalysis::sectorRanking($market);
        $symbols = PortfolioPosition::whereIn('portfolio_id', $this->portfolios()->select('id'))->where('quantity', '>', 0)->pluck('symbol')
            ->merge($this->watchlist()->pluck('symbol'))->unique()->take(40);
        $held = PortfolioPosition::whereIn('portfolio_id', $this->portfolios()->select('id'))->where('quantity', '>', 0)->pluck('symbol')->all();

        $scores = Security::whereIn('symbol', $symbols)->get()
            ->reject(fn (Security $security) => str_starts_with($security->symbol, '^') || in_array($security->asset_type, ['etf', 'fund', 'crypto'], true))
            ->map(function (Security $security) use ($market, $sectors, $global, $held) {
                $technical = TechnicalAnalysis::analyse($market->history($security->symbol, now()->subMonths(14))->map(fn ($price) => (float) $price->close)->values()->all());

                return [
                    'security' => $security,
                    'held' => in_array($security->symbol, $held, true),
                    'score' => StockScore::rate($security->fundamentals ?? [], $technical, MarketAnalysis::sectorStanding($sectors, $security->sector), $global['light']),
                ];
            })
            ->sortByDesc(fn (array $row) => [$row['score']['percent'], $row['score']['max']])->values();

        return ['global' => $global, 'sectors' => $sectors, 'scores' => $scores];
    }
}; ?>

@php
    $pct = fn ($value) => $value !== null ? ($value >= 0 ? '+' : '').number_format($value * 100, 1, ',', '.').' %' : '—';
    $tone = fn ($value) => $value === null ? 'text-gray-400' : ($value >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-700 dark:text-red-400');
    $trendIcon = ['up' => '↗', 'down' => '↘', 'sideways' => '→', 'unknown' => '·'];
    $verdicts = \App\Support\Portfolio\StockScore::verdicts();
@endphp

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Market analysis') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5" wire:init="load">
            @include('portfolio.partials.nav', ['active' => 'portfolio.market'])

            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('From the top down, as in the “Aktien- und Börsenführerschein”: first the global situation, then the sectors, then the single share.') }}</p>

            @if (! $global)
                <p class="rounded-2xl bg-white p-5 text-sm text-gray-500 shadow-xs dark:bg-gray-800 dark:text-gray-400" wire:loading.delay>⏳ {{ __('Loading market data …') }}</p>
            @else
                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800" aria-labelledby="global-heading">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 id="global-heading" class="font-semibold text-gray-900 dark:text-gray-100">🌍 {{ __('1. Global analysis') }}</h3>
                        <span @class(['rounded-full px-3 py-1 text-sm font-medium', 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-200' => $global['light'] === 'green', 'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-200' => $global['light'] === 'yellow', 'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-200' => $global['light'] === 'red', 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200' => $global['light'] === 'unknown'])>
                            {{ ['green' => '🟢 '.__('Market light: favourable'), 'yellow' => '🟡 '.__('Market light: mixed'), 'red' => '🔴 '.__('Market light: difficult'), 'unknown' => '⚪ '.__('Market light: no data')][$global['light']] }}
                        </span>
                    </div>
                    <div class="mt-3 overflow-x-auto">
                        <table class="w-full text-sm tabular-nums">
                            <thead class="text-xs text-gray-500 dark:text-gray-400"><tr><th class="py-1 text-start font-medium">{{ __('Indicator') }}</th><th class="text-end font-medium">{{ __('Current') }}</th><th class="text-end font-medium">{{ __('3 months') }}</th><th class="text-end font-medium">{{ __('12 months') }}</th><th class="text-end font-medium">{{ __('Trend') }}</th></tr></thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                @foreach ($global['rows'] as $row)
                                    <tr>
                                        <td class="py-1.5 text-gray-900 dark:text-gray-100">{{ $row['label'] }}</td>
                                        <td class="text-end text-gray-700 dark:text-gray-300">{{ $row['price'] !== null ? number_format($row['price'], $row['price'] < 10 ? 3 : 2, ',', '.') : '—' }}</td>
                                        <td class="text-end {{ $tone($row['change_3m']) }}">{{ $pct($row['change_3m']) }}</td>
                                        <td class="text-end {{ $tone($row['change_12m']) }}">{{ $pct($row['change_12m']) }}</td>
                                        <td @class(['text-end', 'text-emerald-700 dark:text-emerald-400' => $row['reading'] > 0, 'text-red-700 dark:text-red-400' => $row['reading'] < 0, 'text-gray-500' => $row['reading'] === 0])>{{ $trendIcon[$row['trend']] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Rising indices are good for shares; rising interest rates and a rising VIX weigh on them. Trend = price compared with the 200-day line.') }}</p>
                </section>

                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800" aria-labelledby="sector-heading">
                    <h3 id="sector-heading" class="font-semibold text-gray-900 dark:text-gray-100">🏭 {{ __('2. Sector analysis') }}</h3>
                    <div class="mt-3 overflow-x-auto">
                        <table class="w-full text-sm tabular-nums">
                            <thead class="text-xs text-gray-500 dark:text-gray-400"><tr><th class="py-1 text-start font-medium">#</th><th class="text-start font-medium">{{ __('Sector') }}</th><th class="text-end font-medium">{{ __('3 months') }}</th><th class="text-end font-medium">{{ __('6 months') }}</th><th class="text-end font-medium">{{ __('12 months') }}</th><th class="text-end font-medium">{{ __('vs. S&P 500 (6 m)') }}</th><th class="text-end font-medium">{{ __('Trend') }}</th></tr></thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                @foreach ($sectors as $row)
                                    <tr>
                                        <td class="py-1.5 text-gray-500">{{ $row['rank'] }}</td>
                                        <td class="text-gray-900 dark:text-gray-100"><a href="{{ route('portfolio.security', ['symbol' => $row['symbol']]) }}" wire:navigate class="hover:underline">{{ $row['label'] }}</a> <span class="text-xs text-gray-400">{{ $row['symbol'] }}</span></td>
                                        <td class="text-end {{ $tone($row['change_3m']) }}">{{ $pct($row['change_3m']) }}</td>
                                        <td class="text-end {{ $tone($row['change_6m']) }}">{{ $pct($row['change_6m']) }}</td>
                                        <td class="text-end {{ $tone($row['change_12m']) }}">{{ $pct($row['change_12m']) }}</td>
                                        <td class="text-end font-medium {{ $tone($row['relative_6m']) }}">{{ $pct($row['relative_6m']) }}</td>
                                        <td class="text-end text-gray-500">{{ $trendIcon[$row['trend']] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Measured with the US sector ETFs. Strong sectors tend to stay strong for a while – shares from the top third get extra points.') }}</p>
                </section>

                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800" aria-labelledby="score-heading">
                    <h3 id="score-heading" class="font-semibold text-gray-900 dark:text-gray-100">🏅 {{ __('3. Points system for your shares') }}</h3>
                    @if ($scores->isEmpty())
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('No shares in your portfolio or on your watchlist yet.') }}</p>
                    @else
                        <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach ($scores as $row)
                                <li wire:key="score-{{ $row['security']->symbol }}" class="flex flex-wrap items-center gap-3 py-2 text-sm">
                                    <a href="{{ route('portfolio.security', ['symbol' => $row['security']->symbol]) }}" wire:navigate class="min-w-0 flex-1">
                                        <span class="block font-medium text-gray-900 hover:underline dark:text-gray-100">{{ $row['security']->name }}</span>
                                        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $row['security']->symbol }}{{ $row['held'] ? ' · '.__('in the portfolio') : ' · '.__('watchlist') }}{{ $row['security']->sector ? ' · '.$row['security']->sector : '' }}</span>
                                    </a>
                                    <span class="w-24 text-end tabular-nums text-gray-700 dark:text-gray-300">{{ $row['score']['points'] }} / {{ $row['score']['max'] }}</span>
                                    <span class="rounded-full px-2.5 py-0.5 text-xs font-medium {{ $verdicts[$row['score']['verdict']]['color'] }}">{{ $verdicts[$row['score']['verdict']]['label'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('0–2 points per criterion: valuation, profitability, growth, debt, dividend, chart, sector and market. Details on each share’s page. No investment advice.') }}</p>
                    @endif
                </section>
            @endif
        </div>
    </div>
</div>
