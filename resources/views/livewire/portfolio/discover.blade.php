<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Livewire\Concerns\InteractsWithPortfolio;
use App\Models\Security;
use App\Models\WatchlistItem;
use App\Support\Portfolio\MarketData;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;
    use InteractsWithPortfolio;

    #[Url(except: 'day_losers')]
    public string $screener = 'day_losers';

    public function watch(string $symbol): void
    {
        $security = Security::where('symbol', $symbol)->first() ?? abort(404);
        WatchlistItem::firstOrCreate(['user_id' => $this->currentUser()->id, 'symbol' => $security->symbol], ['name' => $security->name]);
    }

    public function with(MarketData $market): array
    {
        $screener = in_array($this->screener, MarketData::SCREENERS, true) ? $this->screener : 'day_losers';
        $list = Security::where('scanned_at', '>=', now()->subDays(3))->get()
            ->filter(fn (Security $security) => in_array($screener, $security->screeners ?? [], true))
            ->sortBy(fn (Security $security) => in_array($screener, ['day_losers'], true) ? $security->changePercent() ?? 0 : -($security->changePercent() ?? 0))
            ->values();

        return [
            'indices' => $market->quotes(array_keys(MarketData::INDICES)),
            'list' => $screener === 'undervalued_large_caps' || $screener === 'most_actives' ? $list->sortByDesc('market_cap')->values() : $list,
            'watched' => $this->watchlist()->pluck('symbol')->flip(),
        ];
    }
}; ?>

@php
    $signed = fn ($value) => $value === null ? '—' : ($value > 0 ? '+' : ($value < 0 ? '−' : '±')).number_format(abs((float) $value), 2, ',', '.').' %';
    $tone = fn ($value) => $value > 0 ? 'text-emerald-700 dark:text-emerald-400' : ($value < 0 ? 'text-orange-700 dark:text-orange-400' : 'text-gray-500 dark:text-gray-400');
    $screeners = [
        'day_losers' => __('Losers of the day'),
        'day_gainers' => __('Winners of the day'),
        'undervalued_large_caps' => __('Undervalued large caps'),
        'undervalued_growth_stocks' => __('Undervalued growth stocks'),
        'growth_technology_stocks' => __('Growth tech stocks'),
        'most_actives' => __('Most traded'),
    ];
    $cap = fn (?int $value) => $value ? ($value >= 1e12 ? number_format($value / 1e12, 1, ',', '.').' '.__('tn') : number_format($value / 1e9, 1, ',', '.').' '.__('bn')) : '—';
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
            @include('portfolio.partials.nav', ['active' => 'portfolio.discover'])

            <section>
                <h3 class="mb-2 font-semibold text-gray-900 dark:text-gray-100">{{ __('Markets') }}</h3>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    @foreach (\App\Support\Portfolio\MarketData::INDICES as $symbol => $label)
                        @php
                            $quote = $indices[$symbol] ?? null;
                            $change = $quote?->changePercent();
                        @endphp
                        <a href="{{ route('portfolio.security', ['symbol' => $symbol]) }}" wire:navigate class="rounded-2xl bg-white p-3 shadow-sm hover:ring-1 hover:ring-indigo-200 dark:bg-gray-800 dark:hover:ring-indigo-800">
                            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $label }}</p>
                            <p class="mt-0.5 font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $quote?->last_price ? number_format($quote->last_price, $quote->last_price < 10 ? 4 : 2, ',', '.') : '—' }}</p>
                            <p class="text-xs tabular-nums {{ $tone($change ?? 0) }}">{{ $signed($change) }}</p>
                        </a>
                    @endforeach
                </div>
            </section>

            <section class="overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-gray-800">
                <div class="flex flex-wrap items-center gap-2 px-4 py-3">
                    <h3 class="me-2 font-semibold text-gray-900 dark:text-gray-100">{{ __('Market scan') }}</h3>
                    @foreach ($screeners as $key => $label)
                        <button type="button" wire:click="$set('screener', '{{ $key }}')" @class(['rounded-full px-3 py-1 text-xs', 'bg-indigo-600 text-white dark:bg-indigo-500' => $screener === $key, 'ring-1 ring-gray-200 text-gray-700 hover:bg-gray-50 dark:ring-gray-700 dark:text-gray-300' => $screener !== $key])>{{ $label }}</button>
                    @endforeach
                </div>
                @if ($list->isEmpty())
                    <p class="border-t border-gray-100 px-4 py-6 text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">{{ __('The market scan runs every morning – results appear here afterwards.') }}</p>
                @else
                    <ul class="divide-y divide-gray-100 border-t border-gray-100 dark:divide-gray-700 dark:border-gray-700">
                        @foreach ($list->take(25) as $security)
                            <li class="flex items-center gap-3 px-4 py-2.5 text-sm" wire:key="scan-{{ $security->id }}">
                                <a href="{{ route('portfolio.security', ['symbol' => $security->symbol]) }}" wire:navigate class="min-w-0 flex-1">
                                    <span class="block truncate font-medium text-gray-900 dark:text-gray-100">{{ $security->name }}</span>
                                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $security->symbol }} · {{ __('Market cap') }} {{ $cap($security->market_cap) }}</span>
                                </a>
                                <span class="w-24 text-end tabular-nums text-gray-900 dark:text-gray-100">{{ $security->last_price ? number_format($security->last_price, 2, ',', '.').' '.$security->currency : '—' }}</span>
                                <span class="w-20 text-end tabular-nums {{ $tone($security->changePercent() ?? 0) }}">{{ $signed($security->changePercent()) }}</span>
                                @if ($watched->has($security->symbol))
                                    <span class="w-6 text-center text-gray-400" title="{{ __('On the watchlist') }}">★</span>
                                @else
                                    <button type="button" wire:click="watch('{{ $security->symbol }}')" class="w-6 text-center text-gray-400 hover:text-indigo-600" title="{{ __('Add to watchlist') }}" aria-label="{{ __('Add to watchlist') }}">☆</button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </div>
</div>
