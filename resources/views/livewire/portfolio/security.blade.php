<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Livewire\Concerns\InteractsWithPortfolio;
use App\Models\PortfolioPosition;
use App\Models\PortfolioTransaction;
use App\Models\WatchlistItem;
use App\Support\Portfolio\MarketData;
use App\Support\Portfolio\Valuation;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;
    use InteractsWithPortfolio;

    #[Locked]
    public string $symbol = '';

    #[Url(except: '1y')]
    public string $range = '1y';

    public function mount(string $symbol): void
    {
        $this->symbol = strtoupper(mb_substr($symbol, 0, 32));
        app(MarketData::class)->quote($this->symbol) ?? abort(404);
    }

    public function toggleWatchlist(): void
    {
        $item = $this->watchlist()->where('symbol', $this->symbol)->first();

        if ($item) {
            $item->delete();
        } else {
            WatchlistItem::create(['user_id' => $this->currentUser()->id, 'symbol' => $this->symbol, 'name' => (string) \App\Models\Security::where('symbol', $this->symbol)->value('name')]);
        }
    }

    public function with(MarketData $market, Valuation $valuation): array
    {
        $security = $market->quote($this->symbol);
        $from = match ($this->range) {
            '1m' => now()->subMonth(),
            '3m' => now()->subMonths(3),
            '5y' => now()->subYears(5),
            'max' => now()->subYears(30),
            default => now()->subYear(),
        };
        $prices = $market->history($this->symbol, $from);
        $portfolioIds = $this->portfolios()->pluck('id');
        $positions = PortfolioPosition::whereIn('portfolio_id', $portfolioIds)->where('symbol', $this->symbol)->with('portfolio')->get();

        return [
            'security' => $security,
            'price' => $security?->last_price ? $market->toEuro($security->last_price, $security->currency) : null,
            'chart' => $prices->map(fn ($price) => ['label' => $price->date->format('d.m.y'), 'value' => (float) $price->close])->values()->all(),
            'rows' => $valuation->rows($positions),
            'transactions' => PortfolioTransaction::whereIn('portfolio_id', $portfolioIds)->where('symbol', $this->symbol)->with('portfolio')->orderByDesc('executed_on')->limit(50)->get(),
            'watched' => $this->watchlist()->where('symbol', $this->symbol)->exists(),
        ];
    }
}; ?>

@php
    $euro = fn ($value) => number_format((float) $value, 2, ',', '.').' €';
    $signed = fn ($value, $suffix = ' €') => ($value > 0 ? '+' : ($value < 0 ? '−' : '±')).number_format(abs((float) $value), 2, ',', '.').$suffix;
    $tone = fn ($value) => $value > 0 ? 'text-emerald-700 dark:text-emerald-400' : ($value < 0 ? 'text-orange-700 dark:text-orange-400' : 'text-gray-500 dark:text-gray-400');
    $quantity = fn ($value) => rtrim(rtrim(number_format((float) $value, 6, ',', '.'), '0'), ',');
    $change = $security?->changePercent();
    $typeLabels = [
        'buy' => __('Purchase'), 'sell' => __('Sale'), 'dividend' => __('Dividend'), 'split' => __('Split'),
        'fee' => __('Fee'), 'transfer_in' => __('Transfer in'), 'transfer_out' => __('Transfer out'),
    ];
@endphp

<div>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">📈 {{ __('Portfolio') }}</h2>
            <span class="text-xs text-gray-500 dark:text-gray-400">🔒 {{ __('Only you can see your portfolios.') }}</span>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5">
            @include('portfolio.partials.nav', ['active' => ''])

            <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h3 class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ $security->name }}</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ $security->symbol }}
                            @if ($security->isin) · {{ __('ISIN') }} {{ $security->isin }} @endif
                            @if ($security->wkn) · {{ __('WKN') }} {{ $security->wkn }} @endif
                            @if ($security->exchange) · {{ $security->exchange }} @endif
                        </p>
                    </div>
                    <div class="text-end">
                        <p class="text-2xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ number_format((float) $security->last_price, 2, ',', '.') }} {{ $security->currency }}</p>
                        <p class="text-sm tabular-nums {{ $tone($change ?? 0) }}">{{ $change !== null ? $signed($change, ' %') : '' }}@if ($price !== null && $security->currency !== 'EUR') <span class="text-gray-500 dark:text-gray-400">· ≈ {{ $euro($price) }}</span>@endif</p>
                        <p class="text-xs text-gray-400">{{ $security->price_updated_at?->diffForHumans() }}</p>
                    </div>
                </div>
                <div class="mt-4 flex flex-wrap gap-2">
                    <a href="{{ route('portfolio.transactions', ['new' => 1, 'symbol' => $security->symbol]) }}" wire:navigate class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700">＋ {{ __('Transaction') }}</a>
                    <button type="button" wire:click="toggleWatchlist" class="rounded-lg px-3 py-1.5 text-sm ring-1 ring-gray-200 hover:bg-gray-50 dark:ring-gray-700 dark:hover:bg-gray-700">{{ $watched ? '★ '.__('On the watchlist') : '☆ '.__('Add to watchlist') }}</button>
                </div>
            </section>

            <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Price history') }} ({{ $security->currency }})</h3>
                    <div class="inline-flex rounded-lg bg-gray-100 p-0.5 text-xs dark:bg-gray-900">
                        @foreach (['1m' => __('1M'), '3m' => __('3M'), '1y' => __('1Y'), '5y' => __('5Y'), 'max' => __('Max')] as $key => $label)
                            <button type="button" wire:click="$set('range', '{{ $key }}')" @class(['rounded-md px-2.5 py-1', 'bg-white font-medium text-gray-900 shadow-sm dark:bg-gray-700 dark:text-gray-100' => $range === $key, 'text-gray-600 dark:text-gray-400' => $range !== $key])>{{ $label }}</button>
                        @endforeach
                    </div>
                </div>
                <x-line-chart :points="$chart" :value-label="__('Closing price')" :currency="false" wire:key="security-chart-{{ $range }}" />
                <dl class="mt-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                    @foreach ([
                        __('Previous close') => $security->previous_close ? number_format($security->previous_close, 2, ',', '.') : '—',
                        __('52-week high') => $security->week_52_high ? number_format($security->week_52_high, 2, ',', '.') : '—',
                        __('52-week low') => $security->week_52_low ? number_format($security->week_52_low, 2, ',', '.') : '—',
                        __('Type') => __(ucfirst($security->asset_type)),
                    ] as $label => $value)
                        <div class="rounded-xl bg-gray-50 px-3 py-2 dark:bg-gray-900/40">
                            <dt class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                            <dd class="tabular-nums text-gray-900 dark:text-gray-100">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>

            @if ($rows !== [])
                <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Your position') }}</h3>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        @foreach ($rows as $row)
                            <div class="rounded-xl bg-gray-50 p-3 text-sm dark:bg-gray-900/40" wire:key="holding-{{ $row['position']->id }}">
                                <p class="font-medium text-gray-900 dark:text-gray-100">{{ $row['position']->portfolio->name }}</p>
                                <p class="text-gray-600 dark:text-gray-300">{{ $quantity($row['position']->quantity) }} × Ø {{ $euro($row['position']->avg_buy_price) }}</p>
                                <p class="mt-1 flex justify-between"><span>{{ __('Value') }}</span><span class="tabular-nums">{{ $euro($row['value']) }}</span></p>
                                <p class="flex justify-between {{ $tone($row['gain']) }}"><span>{{ __('Gain / loss') }}</span><span class="tabular-nums">{{ $signed($row['gain']) }}{{ $row['gain_percent'] !== null ? ' ('.$signed($row['gain_percent'], ' %').')' : '' }}</span></p>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            @if ($transactions->isNotEmpty())
                <section class="overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-gray-800">
                    <h3 class="px-4 py-3 font-semibold text-gray-900 dark:text-gray-100">{{ __('Transactions') }}</h3>
                    <ul class="divide-y divide-gray-100 border-t border-gray-100 dark:divide-gray-700 dark:border-gray-700">
                        @foreach ($transactions as $transaction)
                            <li class="flex items-center gap-3 px-4 py-2 text-sm" wire:key="security-tx-{{ $transaction->id }}">
                                <span class="w-20 text-xs tabular-nums text-gray-500">{{ $transaction->executed_on->format('d.m.Y') }}</span>
                                <span class="w-24">{{ $typeLabels[$transaction->type] ?? $transaction->type }}</span>
                                <span class="flex-1 text-gray-600 dark:text-gray-300">{{ $transaction->portfolio->name }}</span>
                                <span class="tabular-nums">{{ $transaction->quantity > 0 ? $quantity($transaction->quantity).' × ' : '' }}{{ number_format($transaction->price, 2, ',', '.') }} {{ $transaction->currency }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>
    </div>
</div>
