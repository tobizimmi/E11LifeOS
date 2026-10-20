<?php

use App\Support\Finance\WealthPlan;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Wealth plan ("FIRE"): takes the current net worth and the monthly savings rate from the cashflow
 * analysis and projects the fortune forward. The member can tweak the assumptions and sees when their
 * assets could cover their living costs (financial independence).
 */
new #[Layout('layouts.app')] class extends Component
{
    public string $monthlySavings = '';

    public string $monthlyExpenses = '';

    public string $return = '';

    public string $withdrawal = '';

    public string $target = '';

    public function mount(): void
    {
        $plan = WealthPlan::for(Auth::user());
        $this->monthlySavings = $this->decimal($plan['monthly_savings']);
        $this->monthlyExpenses = $this->decimal($plan['monthly_expenses']);
        $this->return = rtrim(rtrim(number_format($plan['return'], 1, '.', ''), '0'), '.');
        $this->withdrawal = rtrim(rtrim(number_format($plan['withdrawal'], 1, '.', ''), '0'), '.');
    }

    public function resetPlan(): void
    {
        $this->reset('monthlySavings', 'monthlyExpenses', 'return', 'withdrawal', 'target');
        $this->mount();
    }

    private function decimal(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    public function with(): array
    {
        $plan = WealthPlan::for(Auth::user(), [
            'monthly_savings' => $this->monthlySavings,
            'monthly_expenses' => $this->monthlyExpenses,
            'return' => $this->return,
            'withdrawal' => $this->withdrawal,
            'target' => $this->target,
        ]);

        $milestones = collect($plan['series'])
            ->filter(fn (array $point) => $point['year'] % 5 === 0 || $point['year'] === $plan['years_to_target'])
            ->values()
            ->all();

        return [
            'plan' => $plan,
            'milestones' => $milestones,
            'points' => collect($plan['series'])
                ->map(fn (array $point) => ['label' => (string) (today()->year + $point['year']), 'value' => $point['value']])
                ->all(),
        ];
    }
}; ?>

@php
    $euro = fn (?float $value) => $value === null ? '–' : number_format((float) $value, 0, ',', '.').' €';
    $input = 'block w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
@endphp

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">🚀 {{ __('Wealth plan') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-5xl space-y-5 px-4 sm:px-6 lg:px-8">
            <div class="flex flex-wrap justify-end gap-2">
                <a href="{{ route('net-worth.index') }}" wire:navigate class="rounded-md bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-xs ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700">🥧 {{ __('Net worth') }}</a>
                <a href="{{ route('cashflow.index') }}" wire:navigate class="rounded-md bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-xs ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700">🔁 {{ __('Cashflow analysis') }}</a>
            </div>

            {{-- Headline figures --}}
            <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Today') }}</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($plan['start']) }}</p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Current net worth') }}</p>
                </div>
                <div class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Target') }}</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-indigo-600 dark:text-indigo-400">{{ $euro($plan['target']) }}</p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $target !== '' ? __('Your target') : __('Financial independence (FIRE)') }}</p>
                </div>
                <div class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Reached in') }}</p>
                    @if ($plan['years_to_target'] !== null)
                        <p class="mt-1 text-2xl font-semibold tabular-nums text-emerald-600 dark:text-emerald-400">{{ trans_choice(':count year|:count years', $plan['years_to_target'], ['count' => $plan['years_to_target']]) }}</p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('in :year', ['year' => $plan['target_year']]) }}</p>
                    @else
                        <p class="mt-1 text-2xl font-semibold text-gray-400 dark:text-gray-500">{{ __('not in :years years', ['years' => WealthPlan::HORIZON_YEARS]) }}</p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Save more or adjust the return.') }}</p>
                    @endif
                </div>
                <div class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Passive income') }}</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($plan['passive_at_target']) }}</p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('per month at the target') }}</p>
                </div>
            </section>

            {{-- Assumptions --}}
            <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                <div class="flex items-baseline justify-between gap-2">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Assumptions') }}</h3>
                    <button type="button" wire:click="resetPlan" class="text-xs text-indigo-600 underline dark:text-indigo-400">{{ __('Reset') }}</button>
                </div>
                <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <label class="text-xs text-gray-600 dark:text-gray-300">{{ __('Monthly savings') }}
                        <div class="relative mt-1">
                            <input type="number" step="0.01" min="0" wire:model.live.debounce.400ms="monthlySavings" class="{{ $input }} pe-7">
                            <span class="pointer-events-none absolute inset-y-0 end-2 flex items-center text-gray-400">€</span>
                        </div>
                    </label>
                    <label class="text-xs text-gray-600 dark:text-gray-300">{{ __('Monthly expenses') }}
                        <div class="relative mt-1">
                            <input type="number" step="0.01" min="0" wire:model.live.debounce.400ms="monthlyExpenses" class="{{ $input }} pe-7">
                            <span class="pointer-events-none absolute inset-y-0 end-2 flex items-center text-gray-400">€</span>
                        </div>
                    </label>
                    <label class="text-xs text-gray-600 dark:text-gray-300">{{ __('Target amount (optional)') }}
                        <div class="relative mt-1">
                            <input type="number" step="0.01" min="0" wire:model.live.debounce.400ms="target" placeholder="{{ $euro($plan['fire_number']) }}" class="{{ $input }} pe-7">
                            <span class="pointer-events-none absolute inset-y-0 end-2 flex items-center text-gray-400">€</span>
                        </div>
                    </label>
                    <label class="text-xs text-gray-600 dark:text-gray-300">{{ __('Expected return per year') }}
                        <div class="relative mt-1">
                            <input type="number" step="0.1" min="0" max="20" wire:model.live.debounce.400ms="return" class="{{ $input }} pe-7">
                            <span class="pointer-events-none absolute inset-y-0 end-2 flex items-center text-gray-400">%</span>
                        </div>
                    </label>
                    <label class="text-xs text-gray-600 dark:text-gray-300">{{ __('Withdrawal rate') }}
                        <div class="relative mt-1">
                            <input type="number" step="0.1" min="1" max="10" wire:model.live.debounce.400ms="withdrawal" class="{{ $input }} pe-7">
                            <span class="pointer-events-none absolute inset-y-0 end-2 flex items-center text-gray-400">%</span>
                        </div>
                    </label>
                </div>
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('The FIRE number is your yearly expenses divided by the withdrawal rate – the classic 4 % rule. The projection compounds your net worth with the return and adds your savings every month.') }}</p>
            </section>

            {{-- Projection chart --}}
            <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                <h3 class="mb-3 font-semibold text-gray-900 dark:text-gray-100">{{ __('Projection') }}</h3>
                <x-line-chart :points="$points" :value-label="__('Net worth')" :height="220" />
            </section>

            {{-- Milestones --}}
            <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                <h3 class="mb-3 font-semibold text-gray-900 dark:text-gray-100">{{ __('Milestones') }}</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                <th class="py-2 pe-3 font-medium">{{ __('Year') }}</th>
                                <th class="py-2 pe-3 font-medium">{{ __('In') }}</th>
                                <th class="py-2 text-right font-medium">{{ __('Net worth') }}</th>
                                <th class="py-2 pe-0 text-right font-medium">{{ __('Monthly passive') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach ($milestones as $point)
                                <tr wire:key="milestone-{{ $point['year'] }}" @class(['bg-emerald-50/60 dark:bg-emerald-900/20' => $plan['years_to_target'] !== null && $point['year'] === $plan['years_to_target']])>
                                    <td class="py-2 pe-3 tabular-nums text-gray-700 dark:text-gray-300">{{ today()->year + $point['year'] }}</td>
                                    <td class="py-2 pe-3 text-gray-500 dark:text-gray-400">{{ $point['year'] === 0 ? __('now') : trans_choice(':count year|:count years', $point['year'], ['count' => $point['year']]) }}</td>
                                    <td class="py-2 text-right tabular-nums font-medium text-gray-900 dark:text-gray-100">{{ $euro($point['value']) }}</td>
                                    <td class="py-2 pe-0 text-right tabular-nums text-gray-600 dark:text-gray-300">{{ $euro($point['value'] * ($plan['withdrawal'] / 100) / 12) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            @unless ($plan['has_data'])
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Tip: enter your net worth and your income & expenses, then this plan fills itself from your real figures.') }}</p>
            @endunless
        </div>
    </div>
</div>
