<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Livewire\Concerns\InteractsWithPortfolio;
use App\Support\Portfolio\StrategyCoach;
use App\Support\Portfolio\Valuation;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;
    use InteractsWithPortfolio;

    public function mount(): void
    {
        $this->resolveHousehold();
    }

    public function with(Valuation $valuation): array
    {
        return ['coach' => StrategyCoach::forUser($this->currentUser(), $valuation)];
    }
}; ?>

@php
    $statusStyles = [
        'ok' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-200',
        'warn' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-200',
        'bad' => 'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-200',
    ];
    $statusIcon = ['ok' => '✓', 'warn' => '!', 'bad' => '✕'];
    $thirds = fn (int $filled) => str_repeat('●', $filled).str_repeat('○', 3 - $filled);
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
            @include('portfolio.partials.nav', ['active' => 'portfolio.strategy'])

            <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                <h3 class="font-semibold text-gray-900 dark:text-gray-100">🎯 {{ __('Three-thirds strategy') }}</h3>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('A coach for the anticyclical three-thirds strategy: buy fallen angels in three thirds of 1 % each (full position 3 %), add the next third after a further 10–15 % drop, and sell in thirds as the gain against your average buy price grows (+25 %, +35 %, trailing stop from +30 %, at the latest +100 %).') }}</p>
            </section>

            {{-- Depot health against the ground rules --}}
            <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Depot health') }}</h3>
                    <div class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                        <span>{{ __('Ground rules') }}</span>
                        <span @class([
                            'rounded-full px-2.5 py-0.5 text-sm font-semibold',
                            'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-200' => $coach['health']['score'] >= 80,
                            'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-200' => $coach['health']['score'] >= 50 && $coach['health']['score'] < 80,
                            'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-200' => $coach['health']['score'] < 50,
                        ])>{{ $coach['health']['score'] }} / 100</span>
                    </div>
                </div>
                <dl class="mt-4 grid gap-3 sm:grid-cols-3">
                    @foreach ($coach['health']['checks'] as $check)
                        <div class="rounded-xl border border-gray-100 p-3 dark:border-gray-700">
                            <dt class="flex items-center gap-2 text-sm font-medium text-gray-900 dark:text-gray-100">
                                <span class="flex h-5 w-5 items-center justify-center rounded-full text-xs font-bold {{ $statusStyles[$check['status']] }}">{{ $statusIcon[$check['status']] }}</span>
                                {{ $check['label'] }}
                            </dt>
                            <dd class="mt-1 text-xs text-gray-600 dark:text-gray-400">{{ $check['detail'] }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>

            {{-- Actionable signals --}}
            @if (! empty($coach['signals']))
                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">🔔 {{ __('Signals') }}</h3>
                    <ul class="mt-3 space-y-2 text-sm">
                        @foreach ($coach['signals'] as $signal)
                            <li class="flex items-start gap-2">
                                @if ($signal['type'] === 'sell')
                                    <span>🎯</span><span class="text-gray-800 dark:text-gray-200"><strong>{{ $signal['name'] }}</strong> – {{ $signal['label'] }} ({{ __('currently') }} +{{ number_format((float) $signal['gain_percent'], 1, ',', '.') }} %)</span>
                                @elseif ($signal['type'] === 'buy_tranche')
                                    <span>🛒</span><span class="text-gray-800 dark:text-gray-200"><strong>{{ $signal['name'] }}</strong> – {{ __('ready for the :third. third', ['third' => $signal['third']]) }}</span>
                                @else
                                    <span>⚠️</span><span class="text-gray-800 dark:text-gray-200"><strong>{{ $signal['name'] }}</strong> – {{ __('cluster risk: :weight % of the depot', ['weight' => number_format((float) $signal['weight'], 1, ',', '.')]) }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            {{-- Three-thirds state per position --}}
            <section class="overflow-hidden rounded-2xl bg-white shadow-xs dark:bg-gray-800">
                <h3 class="px-5 pt-5 font-semibold text-gray-900 dark:text-gray-100">{{ __('Three-thirds state') }}</h3>
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-[44rem] text-sm">
                        <thead class="bg-gray-50 text-left text-xs text-gray-500 dark:bg-gray-900/40 dark:text-gray-400">
                            <tr>
                                <th class="px-5 py-2 font-medium">{{ __('Security') }}</th>
                                <th class="px-3 py-2 text-center font-medium">{{ __('Thirds') }}</th>
                                <th class="px-3 py-2 text-end font-medium">{{ __('Weight') }}</th>
                                <th class="px-3 py-2 text-end font-medium">{{ __('Gain') }}</th>
                                <th class="px-5 py-2 font-medium">{{ __('Next step') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse ($coach['positions'] as $position)
                                <tr wire:key="strategy-{{ $position['symbol'] }}">
                                    <td class="max-w-[16rem] px-5 py-2">
                                        <span class="block truncate text-gray-900 dark:text-gray-100">{{ $position['name'] }}</span>
                                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $position['symbol'] }}{{ $position['sector'] ? ' · '.$position['sector'] : '' }}</span>
                                    </td>
                                    <td class="px-3 py-2 text-center text-base tracking-widest text-indigo-600 dark:text-indigo-300" title="{{ __(':filled of 3 thirds', ['filled' => $position['thirds_filled']]) }}">{{ $thirds($position['thirds_filled']) }}</td>
                                    <td class="px-3 py-2 text-end tabular-nums {{ $position['cluster_risk'] ? 'font-semibold text-red-600 dark:text-red-400' : 'text-gray-600 dark:text-gray-300' }}">{{ number_format((float) $position['weight'], 1, ',', '.') }} %</td>
                                    <td class="px-3 py-2 text-end tabular-nums {{ ($position['gain_percent'] ?? 0) >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-orange-700 dark:text-orange-400' }}">{{ $position['gain_percent'] === null ? '—' : (($position['gain_percent'] >= 0 ? '+' : '').number_format((float) $position['gain_percent'], 1, ',', '.').' %') }}</td>
                                    <td class="px-5 py-2 text-gray-600 dark:text-gray-300">
                                        @php $triggered = collect($position['sell_signals'])->where('triggered', true)->last(); @endphp
                                        @if ($triggered)
                                            <span class="text-amber-700 dark:text-amber-400">🎯 {{ $triggered['label'] }}</span>
                                        @elseif ($position['next_tranche'])
                                            <span class="{{ $position['next_tranche']['reached'] ? 'text-emerald-700 dark:text-emerald-400' : '' }}">
                                                🛒 {{ __(':third. third at :price €', ['third' => $position['next_tranche']['third'], 'price' => number_format((float) $position['next_tranche']['trigger_price'], 2, ',', '.')]) }}
                                                @if ($position['next_tranche']['reached']) · {{ __('reached') }} @endif
                                            </span>
                                        @else
                                            <span class="text-gray-400 dark:text-gray-500">{{ __('Hold') }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-5 py-6 text-center text-gray-500 dark:text-gray-400">{{ __('No positions yet.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <p class="px-5 py-3 text-xs text-gray-500 dark:text-gray-400">{{ __('Advice, not investment recommendation. The thirds are derived from the invested share; the gain is measured against the average buy price.') }}</p>
            </section>
        </div>
    </div>
</div>
