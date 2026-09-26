<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Livewire\Concerns\InteractsWithPortfolio;
use App\Models\Security;
use App\Models\WatchlistItem;
use App\Support\Portfolio\MarketData;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;
    use InteractsWithPortfolio;

    /** Filled by the security picker. */
    public string $symbol = '';

    public string $name = '';

    public ?int $editingId = null;

    public string $notes = '';

    public function updatedSymbol(): void
    {
        $symbol = strtoupper(trim($this->symbol));

        if ($symbol !== '') {
            WatchlistItem::firstOrCreate(['user_id' => $this->currentUser()->id, 'symbol' => mb_substr($symbol, 0, 32)], ['name' => mb_substr($this->name ?: $symbol, 0, 255)]);
        }

        $this->reset('symbol', 'name');
    }

    public function editNotes(int $itemId): void
    {
        $item = $this->watchlist()->find($itemId) ?? abort(404);
        $this->editingId = $item->id;
        $this->notes = (string) $item->notes;
    }

    public function saveNotes(): void
    {
        $this->validate(['notes' => ['nullable', 'string', 'max:2000']]);
        ($this->watchlist()->find($this->editingId) ?? abort(404))->update(['notes' => $this->notes ?: null]);
        $this->editingId = null;
    }

    public function remove(int $itemId): void
    {
        ($this->watchlist()->find($itemId) ?? abort(404))->delete();
    }

    public function refreshPrices(MarketData $market): void
    {
        $market->quotes($this->watchlist()->pluck('symbol')->all(), refresh: true);
    }

    public function with(MarketData $market): array
    {
        $items = $this->watchlist()->orderBy('name')->get();
        $quotes = $market->quotes($items->pluck('symbol')->all());
        $held = \App\Models\PortfolioPosition::whereIn('portfolio_id', $this->portfolios()->select('id'))->pluck('symbol')->flip();

        return [
            'items' => $items,
            'quotes' => $quotes,
            'held' => $held,
            'euro' => fn (?Security $security, ?float $price) => $security && $price !== null ? $market->toEuro($price, $security->currency) : null,
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">📈 {{ __('Portfolio') }}</h2>
            <span class="text-xs text-gray-500 dark:text-gray-400">🔒 {{ __('Only you can see your portfolios.') }}</span>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5">
            @include('portfolio.partials.nav', ['active' => 'portfolio.watchlist'])

            <div class="flex flex-wrap items-center gap-2">
                <div class="w-full sm:max-w-md sm:flex-1">
                    @include('portfolio.partials.security-picker', ['id' => 'watchlist-search', 'model' => 'symbol', 'nameModel' => 'name', 'placeholder' => __('Add security: name, symbol, ISIN or WKN')])
                </div>
                <button type="button" wire:click="refreshPrices" class="ms-auto rounded-lg px-3 py-2 text-sm text-gray-600 hover:bg-white dark:text-gray-300 dark:hover:bg-gray-800">↻ {{ __('Update prices') }}</button>
            </div>

            @if ($items->isEmpty())
                <div class="rounded-2xl bg-white p-8 text-center shadow-sm dark:bg-gray-800">
                    <p class="text-4xl" aria-hidden="true">👀</p>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ __('Follow securities you are interested in – with price, daily change and 52-week range.') }}</p>
                </div>
            @else
                <ul class="divide-y divide-gray-100 overflow-hidden rounded-2xl bg-white shadow-sm dark:divide-gray-700 dark:bg-gray-800">
                    @foreach ($items as $item)
                        @php
                            $security = $quotes[$item->symbol] ?? null;
                            $change = $security?->changePercent();
                            $price = $euro($security, $security?->last_price);
                            $low = $euro($security, $security?->week_52_low);
                            $high = $euro($security, $security?->week_52_high);
                        @endphp
                        <li wire:key="watch-{{ $item->id }}" class="flex flex-wrap items-center gap-3 px-4 py-3">
                            <a href="{{ route('portfolio.security', ['symbol' => $item->symbol]) }}" wire:navigate class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ $item->name ?: $item->symbol }} @if ($held->has($item->symbol))<span class="ms-1 rounded bg-indigo-50 px-1 text-xs font-normal text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200">{{ __('in portfolio') }}</span>@endif</span>
                                <span class="block truncate text-xs text-gray-500 dark:text-gray-400">{{ $item->symbol }}{{ $item->notes ? ' · '.$item->notes : '' }}</span>
                            </a>
                            @if ($low && $high && $price && $high > $low)
                                <span class="hidden w-32 sm:block" title="{{ __('52-week range') }}">
                                    <span class="relative block h-1.5 rounded-full bg-gray-200 dark:bg-gray-700">
                                        <span class="absolute top-1/2 h-3 w-1 -translate-y-1/2 rounded bg-gray-700 dark:bg-gray-200" style="left: {{ max(0, min(100, ($price - $low) / ($high - $low) * 100)) }}%"></span>
                                    </span>
                                    <span class="mt-0.5 flex justify-between text-[10px] tabular-nums text-gray-500 dark:text-gray-400"><span>{{ number_format($low, 2, ',', '.') }}</span><span>{{ number_format($high, 2, ',', '.') }}</span></span>
                                </span>
                            @endif
                            <span class="w-28 shrink-0 text-end">
                                <span class="block text-sm font-medium tabular-nums text-gray-900 dark:text-gray-100">{{ $price !== null ? number_format($price, 2, ',', '.').' €' : '—' }}</span>
                                <span class="block text-xs tabular-nums {{ ($change ?? 0) > 0 ? 'text-emerald-700 dark:text-emerald-400' : (($change ?? 0) < 0 ? 'text-orange-700 dark:text-orange-400' : 'text-gray-500') }}">{{ $change !== null ? ($change > 0 ? '+' : ($change < 0 ? '−' : '±')).number_format(abs($change), 2, ',', '.').' %' : '' }}</span>
                            </span>
                            <span class="flex shrink-0 gap-1">
                                <button type="button" wire:click="editNotes({{ $item->id }})" class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-700" aria-label="{{ __('Notes') }}">✎</button>
                                <button type="button" wire:click="remove({{ $item->id }})" class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-red-600 dark:hover:bg-gray-700" aria-label="{{ __('Remove') }}">✕</button>
                            </span>
                            @if ($editingId === $item->id)
                                <form wire:submit="saveNotes" class="flex w-full gap-2">
                                    <x-text-input wire:model="notes" class="block w-full" :placeholder="__('Why are you watching it? Target price?')" aria-label="{{ __('Notes') }}" />
                                    <x-primary-button>{{ __('Save') }}</x-primary-button>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>
