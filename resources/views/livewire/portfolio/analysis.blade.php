<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Livewire\Concerns\InteractsWithPortfolio;
use App\Models\PortfolioPosition;
use App\Support\Portfolio\Analytics;
use App\Support\Portfolio\Valuation;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;
    use InteractsWithPortfolio;

    #[Url(as: 'depot', except: 0)]
    public int $portfolioId = 0;

    #[Url(except: '1y')]
    public string $range = '1y';

    #[Url(except: 'position')]
    public string $allocation = 'position';

    public function with(Valuation $valuation, Analytics $analytics): array
    {
        $portfolios = $this->portfolios()->orderBy('id')->get();
        $selected = $this->portfolioId ? $portfolios->where('id', $this->portfolioId) : $portfolios;
        $positions = PortfolioPosition::whereIn('portfolio_id', $selected->modelKeys())->with('portfolio')->get();
        $rows = $valuation->rows($positions);
        $from = match ($this->range) {
            '3m' => now()->subMonths(3),
            '5y' => now()->subYears(5),
            'max' => now()->subYears(50),
            default => now()->subYear(),
        };
        $benchmark = $this->portfolioSettings()->benchmark_symbol ?: '^GDAXI';
        $performance = $analytics->performance($selected->modelKeys(), $from, $benchmark);

        return [
            'portfolioList' => $portfolios,
            'summary' => $valuation->summary($rows),
            'allocationRows' => $analytics->allocation($rows, $this->allocation),
            'performance' => $performance,
            'risk' => $analytics->risk($performance),
            'benchmark' => $benchmark,
            'correlation' => $analytics->correlation(collect($rows)->pluck('position.symbol')->unique()->values()->all()),
            'topMovers' => collect($rows)->filter(fn (array $row) => $row['day_change_percent'] !== null)->sortByDesc('day_change_percent')->values(),
        ];
    }
}; ?>

@php
    $signed = fn ($value, $suffix = ' %') => $value === null ? '—' : ($value > 0 ? '+' : ($value < 0 ? '−' : '±')).number_format(abs((float) $value), $suffix === ' %' ? 1 : 2, ',', '.').$suffix;
    $tone = fn ($value) => $value > 0 ? 'text-emerald-700 dark:text-emerald-400' : ($value < 0 ? 'text-orange-700 dark:text-orange-400' : 'text-gray-500 dark:text-gray-400');
    $lastPerformance = $performance ? end($performance) : null;
    // Diverging scale for correlations: orange (−1) … grey (0) … blue (+1).
    $correlationColor = function (?float $value) {
        if ($value === null) {
            return 'transparent';
        }

        $strength = min(1, abs($value));

        return $value >= 0 ? 'rgba(37, 99, 235, '.round(0.08 + $strength * 0.72, 2).')' : 'rgba(234, 88, 12, '.round(0.08 + $strength * 0.72, 2).')';
    };
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
            @include('portfolio.partials.nav', ['active' => 'portfolio.analysis'])

            <div class="flex flex-wrap items-center gap-2">
                <select wire:model.live="portfolioId" aria-label="{{ __('Portfolio') }}" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                    <option value="0">{{ __('All portfolios') }}</option>
                    @foreach ($portfolioList as $portfolio)
                        <option value="{{ $portfolio->id }}">{{ $portfolio->name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Performance vs. benchmark --}}
            <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Performance compared with :benchmark', ['benchmark' => $benchmark]) }}</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Portfolio: gain on the invested amount, so deposits do not count as performance. Benchmark: price change.') }}</p>
                    </div>
                    <div class="inline-flex rounded-lg bg-gray-100 p-0.5 text-xs dark:bg-gray-900">
                        @foreach (['3m' => __('3M'), '1y' => __('1Y'), '5y' => __('5Y'), 'max' => __('Max')] as $key => $label)
                            <button type="button" wire:click="$set('range', '{{ $key }}')" @class(['rounded-md px-2.5 py-1', 'bg-white font-medium text-gray-900 shadow-xs dark:bg-gray-700 dark:text-gray-100' => $range === $key, 'text-gray-600 dark:text-gray-400' => $range !== $key])>{{ $label }}</button>
                        @endforeach
                    </div>
                </div>
                <x-line-chart :points="$performance" :value-label="__('Portfolio')" :compare-label="$benchmark" :currency="false" suffix=" %" wire:key="performance-{{ $portfolioId }}-{{ $range }}" />
                <dl class="mt-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-5">
                    @foreach ([
                        [__('Portfolio'), $lastPerformance ? $signed($lastPerformance['value']) : '—', $lastPerformance['value'] ?? 0],
                        [$benchmark, $lastPerformance && $lastPerformance['compare'] !== null ? $signed($lastPerformance['compare']) : '—', $lastPerformance['compare'] ?? 0],
                        [__('Volatility (p.a.)'), $risk['volatility'] !== null ? number_format($risk['volatility'], 1, ',', '.').' %' : '—', 0],
                        [__('Max. drawdown'), $signed($risk['max_drawdown']), $risk['max_drawdown'] ?? 0],
                        [__('Best / worst day'), $risk['best_day'] !== null ? $signed($risk['best_day']).' / '.$signed($risk['worst_day']) : '—', 0],
                    ] as [$label, $value, $sign])
                        <div class="rounded-xl bg-gray-50 px-3 py-2 dark:bg-gray-900/40">
                            <dt class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                            <dd class="tabular-nums {{ $sign ? $tone($sign) : 'text-gray-900 dark:text-gray-100' }}">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>

            <div class="grid gap-5 lg:grid-cols-2">
                {{-- Allocation --}}
                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Allocation') }}</h3>
                        <select wire:model.live="allocation" aria-label="{{ __('Group by') }}" class="rounded-lg border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                            @foreach (['position' => __('Positions'), 'type' => __('Asset class'), 'sector' => __('Sector'), 'country' => __('Country'), 'currency' => __('Currency'), 'portfolio' => __('Portfolio')] as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if ($allocationRows === [])
                        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No positions yet.') }}</p>
                    @else
                        <ul class="mt-4 space-y-2.5">
                            @foreach (array_slice($allocationRows, 0, 12) as $row)
                                <li class="text-sm" wire:key="allocation-{{ $allocation }}-{{ $loop->index }}">
                                    <div class="flex justify-between gap-3">
                                        <span class="truncate text-gray-700 dark:text-gray-300">{{ $allocation === 'type' ? __(ucfirst($row['label'])) : $row['label'] }}</span>
                                        <span class="shrink-0 tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($row['percent'], 1, ',', '.') }} % <span class="text-xs text-gray-500">· {{ number_format($row['value'], 0, ',', '.') }} €</span></span>
                                    </div>
                                    <div class="mt-1 h-2 rounded-full bg-gray-100 dark:bg-gray-700">
                                        <div class="h-2 rounded-full bg-indigo-500" style="width: {{ max(1, $row['percent']) }}%"></div>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                        @if (count($allocationRows) > 12)
                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('… and :count more', ['count' => count($allocationRows) - 12]) }}</p>
                        @endif
                        @if (in_array($allocation, ['sector', 'country'], true) && collect($allocationRows)->contains('label', '—'))
                            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('Sector and country are added by the daily market data update.') }}</p>
                        @endif
                    @endif
                </section>

                {{-- Today --}}
                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Winners and losers today') }}</h3>
                    @if ($topMovers->isEmpty())
                        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No prices yet.') }}</p>
                    @else
                        <ul class="mt-3 divide-y divide-gray-100 text-sm dark:divide-gray-700">
                            @foreach ($topMovers->take(4)->concat($topMovers->count() > 8 ? $topMovers->slice(-4) : $topMovers->slice(4)) as $row)
                                <li class="flex items-center justify-between gap-3 py-2" wire:key="mover-{{ $row['position']->id }}">
                                    <a href="{{ route('portfolio.security', ['symbol' => $row['position']->symbol]) }}" wire:navigate class="truncate text-gray-700 hover:underline dark:text-gray-300">{{ $row['position']->name ?: $row['position']->symbol }}</a>
                                    <span class="shrink-0 tabular-nums {{ $tone($row['day_change_percent']) }}">{{ $signed($row['day_change_percent']) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            </div>

            {{-- Correlation --}}
            @if (count($correlation['symbols']) >= 2)
                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Correlation of daily returns (1 year)') }}</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('+1: move together, 0: independent, −1: opposite. Low correlation spreads the risk.') }}</p>
                    <div class="mt-3 overflow-x-auto">
                        <table class="text-xs">
                            <thead>
                                <tr>
                                    <th></th>
                                    @foreach ($correlation['symbols'] as $symbol)
                                        <th class="px-1 pb-1 font-medium text-gray-500 dark:text-gray-400"><span class="block max-w-[4.5rem] truncate">{{ $symbol }}</span></th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($correlation['symbols'] as $a)
                                    <tr>
                                        <th class="pe-2 text-end font-medium text-gray-500 dark:text-gray-400">{{ $a }}</th>
                                        @foreach ($correlation['symbols'] as $b)
                                            @php
                                                $value = $correlation['matrix'][$a][$b] ?? null;
                                            @endphp
                                            <td class="p-0.5">
                                                <span class="block w-16 rounded-sm px-1 py-1.5 text-center tabular-nums text-gray-900 dark:text-gray-100" style="background: {{ $correlationColor($value) }}" title="{{ $a }} / {{ $b }}">{{ $value !== null ? number_format($value, 2, ',', '') : '—' }}</span>
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif
        </div>
    </div>
</div>
