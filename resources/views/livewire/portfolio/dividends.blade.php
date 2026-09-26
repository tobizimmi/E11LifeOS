<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Livewire\Concerns\InteractsWithPortfolio;
use App\Models\PortfolioPosition;
use App\Models\PortfolioTransaction;
use App\Support\Portfolio\Analytics;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;
    use InteractsWithPortfolio;

    #[Url(as: 'depot', except: 0)]
    public int $portfolioId = 0;

    public function with(Analytics $analytics): array
    {
        $ids = $this->portfolioId ? $this->portfolios()->whereKey($this->portfolioId)->pluck('id') : $this->portfolios()->pluck('id');
        $positions = PortfolioPosition::whereIn('portfolio_id', $ids)->get();
        $held = $positions->groupBy('symbol')->map(fn ($group) => $group->sum('quantity'))->all();
        $report = $analytics->dividends(PortfolioTransaction::whereIn('portfolio_id', $ids)->where('type', 'dividend')->get(), $held);
        $cost = $positions->sum(fn (PortfolioPosition $position) => $position->costBasis());

        return [
            'portfolioList' => $this->portfolios()->orderBy('id')->get(),
            'report' => $report,
            'yieldOnCost' => $cost > 0 ? round($report['expected'] / $cost * 100, 2) : null,
            'recent' => PortfolioTransaction::whereIn('portfolio_id', $ids)->where('type', 'dividend')->with('portfolio')->orderByDesc('executed_on')->limit(20)->get(),
        ];
    }
}; ?>

@php
    $euro = fn ($value) => number_format((float) $value, 2, ',', '.').' €';
    $maxMonth = max(1, collect($report['by_month'])->max('value'));
    $maxYear = max(1, collect($report['by_year'])->max() ?? 1);
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
            @include('portfolio.partials.nav', ['active' => 'portfolio.dividends'])

            <div class="flex flex-wrap items-center gap-2">
                <select wire:model.live="portfolioId" aria-label="{{ __('Portfolio') }}" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                    <option value="0">{{ __('All portfolios') }}</option>
                    @foreach ($portfolioList as $portfolio)
                        <option value="{{ $portfolio->id }}">{{ $portfolio->name }}</option>
                    @endforeach
                </select>
                <a href="{{ route('portfolio.transactions', ['new' => 1, 'type' => 'dividend']) }}" wire:navigate class="ms-auto rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">＋ {{ __('Dividend') }}</a>
            </div>

            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                @foreach ([
                    [__('Last 12 months'), $euro($report['trailing']), __('net, after withholding tax')],
                    [__('Expected next 12 months'), $euro($report['expected']), __('from the securities you still hold')],
                    [__('Per month (Ø)'), $euro($report['expected'] / 12), __('expected')],
                    [__('Yield on cost'), $yieldOnCost !== null ? number_format($yieldOnCost, 2, ',', '.').' %' : '—', __('expected dividends ÷ purchase value')],
                ] as [$label, $value, $hint])
                    <div class="rounded-2xl bg-white p-4 shadow-sm dark:bg-gray-800">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $label }}</p>
                        <p class="mt-1 whitespace-nowrap text-xl font-semibold tabular-nums text-gray-900 dark:text-gray-100 sm:text-2xl">{{ $value }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</p>
                    </div>
                @endforeach
            </div>

            <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Dividends per month') }}</h3>
                <div class="mt-4 flex h-40 items-end gap-1 border-b border-gray-200 dark:border-gray-700">
                    @foreach ($report['by_month'] as $month)
                        <div class="group relative flex h-full flex-1 items-end justify-center" tabindex="0">
                            <div class="w-full max-w-[18px] rounded-t bg-emerald-600 dark:bg-emerald-500" style="height: {{ $month['value'] > 0 ? max(2, $month['value'] / $maxMonth * 100) : 0 }}%"></div>
                            <div class="pointer-events-none absolute bottom-full z-10 mb-1 hidden whitespace-nowrap rounded-lg bg-gray-900 px-2 py-1 text-xs text-white shadow group-hover:block group-focus:block">{{ $month['label'] }}: {{ $euro($month['value']) }}</div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-1 flex gap-1 text-center text-[10px] text-gray-500 dark:text-gray-400">
                    @foreach ($report['by_month'] as $month)
                        <span class="flex-1 truncate">{{ $loop->index % 3 === 0 ? $month['label'] : '' }}</span>
                    @endforeach
                </div>
            </section>

            <div class="grid gap-5 lg:grid-cols-2">
                <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Per year') }}</h3>
                    @if ($report['by_year'] === [])
                        <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">{{ __('No dividends recorded yet.') }}</p>
                    @else
                        <ul class="mt-3 space-y-2">
                            @foreach (array_reverse($report['by_year'], true) as $year => $amount)
                                <li class="text-sm">
                                    <div class="flex justify-between"><span class="text-gray-700 dark:text-gray-300">{{ $year }}</span><span class="tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($amount) }}</span></div>
                                    <div class="mt-1 h-2 rounded-full bg-gray-100 dark:bg-gray-700"><div class="h-2 rounded-full bg-emerald-600 dark:bg-emerald-500" style="width: {{ max(1, $amount / $maxYear * 100) }}%"></div></div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Per security') }}</h3>
                    @if ($report['by_security'] === [])
                        <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">{{ __('No dividends recorded yet.') }}</p>
                    @else
                        <table class="mt-3 w-full text-sm">
                            <thead class="text-left text-xs text-gray-500 dark:text-gray-400">
                                <tr><th class="pb-1 font-medium">{{ __('Security') }}</th><th class="pb-1 text-end font-medium">{{ __('12 months') }}</th><th class="pb-1 text-end font-medium">{{ __('Total') }}</th></tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                @foreach (array_slice($report['by_security'], 0, 15) as $row)
                                    <tr>
                                        <td class="max-w-[12rem] truncate py-1.5 text-gray-700 dark:text-gray-300">{{ $row['name'] }}</td>
                                        <td class="py-1.5 text-end tabular-nums">{{ $euro($row['trailing']) }}</td>
                                        <td class="py-1.5 text-end tabular-nums">{{ $euro($row['total']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </section>
            </div>

            @if ($recent->isNotEmpty())
                <section class="overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-gray-800">
                    <h3 class="px-4 py-3 font-semibold text-gray-900 dark:text-gray-100">{{ __('Latest payments') }}</h3>
                    <ul class="divide-y divide-gray-100 border-t border-gray-100 text-sm dark:divide-gray-700 dark:border-gray-700">
                        @foreach ($recent as $transaction)
                            <li class="flex items-center gap-3 px-4 py-2" wire:key="dividend-{{ $transaction->id }}">
                                <span class="w-20 text-xs tabular-nums text-gray-500">{{ $transaction->executed_on->format('d.m.Y') }}</span>
                                <span class="min-w-0 flex-1 truncate text-gray-700 dark:text-gray-300">{{ $transaction->name ?: $transaction->symbol }} <span class="text-xs text-gray-500">· {{ $transaction->portfolio->name }}</span></span>
                                <span class="tabular-nums text-emerald-700 dark:text-emerald-400">{{ number_format($transaction->cashFlow(), 2, ',', '.') }} {{ $transaction->currency }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>
    </div>
</div>
