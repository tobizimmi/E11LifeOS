<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Livewire\Concerns\InteractsWithPortfolio;
use App\Models\NewsArticle;
use App\Models\PortfolioPosition;
use App\Models\PortfolioRecommendation;
use App\Models\Security;
use App\Support\Portfolio\MarketData;
use App\Support\Portfolio\NewsFeed;
use App\Support\Portfolio\StockInsights;
use App\Support\Portfolio\Valuation;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * My positions at a glance (analysts, insiders, own strategy, next figures, latest news) and the
 * insider barometer: where managers buy or sell shares of their own company.
 */
new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;
    use InteractsWithPortfolio;

    #[Url(except: 'mine')]
    public string $scope = 'mine';

    /**
     * Loads key figures, insider trades and news of the own positions again (a few seconds).
     */
    public function refresh(MarketData $market, NewsFeed $feed): void
    {
        $symbols = $this->ownSymbols();
        $updated = 0;

        foreach (Security::whereIn('symbol', $symbols)->limit(40)->get() as $security) {
            if ($market->fundamentals($security, refresh: true)) {
                $updated++;
            }

            $feed->fetchForSecurity($security, 5);
        }

        $this->dispatch('toast', message: trans_choice(':count security updated.|:count securities updated.', $updated));
    }

    /**
     * @return list<string>
     */
    private function ownSymbols(): array
    {
        return PortfolioPosition::whereIn('portfolio_id', $this->portfolios()->select('id'))->where('quantity', '>', 0)->pluck('symbol')
            ->merge($this->watchlist()->pluck('symbol'))->unique()->values()->all();
    }

    public function with(MarketData $market, Valuation $valuation): array
    {
        $user = $this->currentUser();
        $positions = PortfolioPosition::whereIn('portfolio_id', $this->portfolios()->select('id'))->where('quantity', '>', 0)->with('portfolio')->get();
        $rows = collect($valuation->rows($positions))->groupBy(fn (array $row) => $row['position']->symbol)->map(function ($group) {
            $first = $group->first();

            return ['security' => $first['security'], 'symbol' => $first['position']->symbol, 'name' => $first['security']?->name ?? $first['position']->name ?? $first['position']->symbol, 'value' => $group->sum('value'), 'gain_percent' => $group->sum('cost') > 0 ? round(($group->sum('value') - $group->sum('cost')) / $group->sum('cost') * 100, 2) : null];
        })->sortByDesc('value')->values();

        $news = NewsArticle::whereIn('symbol', $rows->pluck('symbol'))->orderByDesc('published_at')->get()->groupBy('symbol');
        $strategy = PortfolioRecommendation::where('user_id', $user->id)->whereIn('symbol', $rows->pluck('symbol'))->get()->keyBy('symbol');

        $checks = $rows->map(function (array $row) use ($news, $strategy) {
            $figures = $row['security']?->fundamentals ?? [];

            return $row + [
                'consensus' => StockInsights::consensus($figures, $row['security']?->last_price),
                'insider' => StockInsights::insiderBarometer($figures),
                'strategy' => $strategy->get($row['symbol']),
                'next_earnings' => $figures['next_earnings'] ?? null,
                'news' => $news->get($row['symbol'], collect())->first(),
                'plans' => StockInsights::plans($news->get($row['symbol'], collect()))->first(),
            ];
        });

        // Insider barometer: own positions and watchlist, or everything the market scan knows.
        $barometer = Security::query()->whereNotNull('fundamentals')
            ->when($this->scope === 'mine', fn ($query) => $query->whereIn('symbol', $this->ownSymbols()))
            ->get()
            ->map(fn (Security $security) => ['security' => $security, 'insider' => StockInsights::insiderBarometer($security->fundamentals ?? [])])
            ->filter(fn (array $entry) => $entry['insider'] && $entry['insider']['buys'] + $entry['insider']['sells'] > 0)
            ->sortByDesc(fn (array $entry) => [$entry['insider']['score'], $entry['insider']['buys']])
            ->values();

        return [
            'checks' => $checks,
            'buying' => $barometer->filter(fn (array $entry) => $entry['insider']['score'] > 0)->take(25)->values(),
            'selling' => $barometer->filter(fn (array $entry) => $entry['insider']['score'] < 0)->sortBy(fn (array $entry) => $entry['insider']['score'])->take(15)->values(),
            'updatedAt' => Security::whereIn('symbol', $rows->pluck('symbol'))->max('fundamentals_updated_at'),
        ];
    }
}; ?>

@php
    $euro = fn ($value) => number_format((float) $value, 2, ',', '.').' €';
    $signed = fn ($value) => $value === null ? '—' : ($value > 0 ? '+' : ($value < 0 ? '−' : '±')).number_format(abs((float) $value), 1, ',', '.').' %';
    $levelClasses = [
        'strong_buy' => 'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/50 dark:text-emerald-100',
        'buy' => 'bg-sky-100 text-sky-900 dark:bg-sky-900/50 dark:text-sky-100',
        'hold' => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-100',
        'neutral' => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-100',
        'sell' => 'bg-orange-100 text-orange-900 dark:bg-orange-900/50 dark:text-orange-100',
        'strong_sell' => 'bg-red-100 text-red-900 dark:bg-red-900/50 dark:text-red-100',
    ];
    $strategyLabels = ['strong_buy' => __('Strong buy'), 'moderate_buy' => __('Buy'), 'watchlist_buy' => __('Watch')];
@endphp

<div>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Portfolio') }}</h2>
            <span class="text-xs text-gray-500 dark:text-gray-400">🔒 {{ __('Only you can see your portfolios.') }}</span>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5">
            @include('portfolio.partials.nav', ['active' => 'portfolio.insights'])

            <section class="rounded-2xl bg-white shadow-sm dark:bg-gray-800" aria-labelledby="positions-check">
                <div class="flex flex-wrap items-center justify-between gap-2 px-5 pt-5">
                    <div>
                        <h3 id="positions-check" class="font-semibold text-gray-900 dark:text-gray-100">🩺 {{ __('My positions in check') }}</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('What analysts and insiders say, your strategy, the next quarterly figures and the latest news.') }}@if ($updatedAt) {{ __('Data from :time.', ['time' => \Illuminate\Support\Carbon::parse($updatedAt)->diffForHumans()]) }}@endif</p>
                    </div>
                    <button type="button" wire:click="refresh" wire:loading.attr="disabled" class="rounded-lg px-3 py-1.5 text-sm ring-1 ring-gray-200 hover:bg-gray-50 disabled:opacity-50 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700">
                        <span wire:loading.remove wire:target="refresh">↻ {{ __('Update data') }}</span>
                        <span wire:loading wire:target="refresh">{{ __('Loading …') }}</span>
                    </button>
                </div>

                @if ($checks->isEmpty())
                    <p class="p-5 text-sm text-gray-500 dark:text-gray-400">{{ __('No positions yet.') }}</p>
                @else
                    <ul class="mt-3 divide-y divide-gray-100 border-t border-gray-100 dark:divide-gray-700 dark:border-gray-700">
                        @foreach ($checks as $check)
                            <li wire:key="check-{{ $check['symbol'] }}" class="grid gap-2 px-5 py-3 text-sm sm:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1.6fr)] sm:items-center">
                                <div class="min-w-0">
                                    <a href="{{ route('portfolio.security', $check['symbol']) }}" wire:navigate class="block truncate font-medium text-gray-900 hover:underline dark:text-gray-100">{{ $check['name'] }}</a>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $euro($check['value']) }} · <span @class(['text-emerald-700 dark:text-emerald-400' => ($check['gain_percent'] ?? 0) > 0, 'text-orange-700 dark:text-orange-400' => ($check['gain_percent'] ?? 0) < 0])>{{ $signed($check['gain_percent']) }}</span>
                                        @if ($check['next_earnings'] && \Illuminate\Support\Carbon::parse($check['next_earnings'])->isFuture())
                                            · 📅 {{ __('Figures :date', ['date' => \Illuminate\Support\Carbon::parse($check['next_earnings'])->format('d.m.')]) }}
                                        @endif
                                    </p>
                                </div>
                                <div>
                                    <p class="text-[11px] uppercase tracking-wide text-gray-400">{{ __('Analysts') }}</p>
                                    @if ($check['consensus'])
                                        <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $levelClasses[$check['consensus']['level']] }}">{{ $check['consensus']['label'] }}</span>
                                        @if ($check['consensus']['upside'] !== null)
                                            <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('target :upside', ['upside' => $signed($check['consensus']['upside'])]) }}</span>
                                        @endif
                                    @else
                                        <span class="text-xs text-gray-400">—</span>
                                    @endif
                                </div>
                                <div>
                                    <p class="text-[11px] uppercase tracking-wide text-gray-400">{{ __('Insiders') }} @if ($check['strategy']) · {{ __('Strategy') }}@endif</p>
                                    @if ($check['insider'])
                                        <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $levelClasses[$check['insider']['level']] }}" title="{{ __(':buys purchases, :sells sales', ['buys' => $check['insider']['buys'], 'sells' => $check['insider']['sells']]) }}">{{ $check['insider']['label'] }}</span>
                                    @else
                                        <span class="text-xs text-gray-400">—</span>
                                    @endif
                                    @if ($check['strategy'])
                                        <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $levelClasses['buy'] }}">🔎 {{ $strategyLabels[$check['strategy']->rating] ?? $check['strategy']->rating }}</span>
                                    @endif
                                </div>
                                <div class="min-w-0 text-xs">
                                    @if ($check['plans'])
                                        <a href="{{ $check['plans']->url }}" target="_blank" rel="noopener noreferrer" class="line-clamp-2 text-gray-700 hover:underline dark:text-gray-300">💡 {{ $check['plans']->title }}</a>
                                    @elseif ($check['news'])
                                        <a href="{{ $check['news']->url }}" target="_blank" rel="noopener noreferrer" class="line-clamp-2 text-gray-700 hover:underline dark:text-gray-300">📰 {{ $check['news']->title }}</a>
                                    @else
                                        <span class="text-gray-400">{{ __('No news yet.') }}</span>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <p class="px-5 py-3 text-xs text-gray-500 dark:text-gray-400">{{ __('Analyst votes and price targets are opinions, not advice – use them as one piece of the picture.') }}</p>
            </section>

            <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800" aria-labelledby="insider-barometer">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h3 id="insider-barometer" class="font-semibold text-gray-900 dark:text-gray-100">🕵️ {{ __('Insider barometer') }}</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Purchases and sales of board members and major shareholders in the last six months. Purchases with own money are the stronger signal – sales often have private reasons.') }}</p>
                    </div>
                    <div class="inline-flex rounded-lg bg-gray-100 p-0.5 text-xs dark:bg-gray-900" role="tablist">
                        @foreach (['mine' => __('My positions & watchlist'), 'market' => __('Whole market scan')] as $scopeKey => $scopeLabel)
                            <button type="button" role="tab" wire:click="$set('scope', '{{ $scopeKey }}')" aria-selected="{{ $scope === $scopeKey ? 'true' : 'false' }}" @class(['rounded-md px-2.5 py-1', 'bg-white font-medium text-gray-900 shadow-sm dark:bg-gray-700 dark:text-gray-100' => $scope === $scopeKey, 'text-gray-600 dark:text-gray-400' => $scope !== $scopeKey])>{{ $scopeLabel }}</button>
                        @endforeach
                    </div>
                </div>

                @if ($buying->isEmpty() && $selling->isEmpty())
                    <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No insider trades known yet. They are loaded with the key figures (daily market scan or “Update data”).') }}</p>
                @else
                    <div class="mt-4 grid gap-4 lg:grid-cols-2">
                        @foreach ([[__('Insiders are buying'), $buying, 'emerald'], [__('Insiders are selling'), $selling, 'orange']] as [$title, $entries, $color])
                            <div>
                                <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $title }}</h4>
                                <ul class="mt-2 space-y-1.5">
                                    @forelse ($entries as $entry)
                                        <li wire:key="barometer-{{ $entry['security']->symbol }}" class="flex items-center gap-2 text-sm">
                                            <a href="{{ route('portfolio.security', $entry['security']->symbol) }}" wire:navigate class="min-w-0 flex-1 truncate text-gray-900 hover:underline dark:text-gray-100">{{ $entry['security']->name }}</a>
                                            <span class="text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ __(':buys ↑ :sells ↓', ['buys' => $entry['insider']['buys'], 'sells' => $entry['insider']['sells']]) }}</span>
                                            <span class="relative h-2 w-20 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700" aria-hidden="true">
                                                <span class="absolute inset-y-0 start-0 rounded-full {{ $color === 'emerald' ? 'bg-emerald-500' : 'bg-orange-500' }}" style="width: {{ abs($entry['insider']['score']) }}%"></span>
                                            </span>
                                            <span class="w-10 text-end text-xs font-medium tabular-nums text-gray-700 dark:text-gray-300">{{ $entry['insider']['score'] > 0 ? '+' : '' }}{{ $entry['insider']['score'] }}</span>
                                        </li>
                                    @empty
                                        <li class="text-sm text-gray-500 dark:text-gray-400">—</li>
                                    @endforelse
                                </ul>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>
        </div>
    </div>
</div>
