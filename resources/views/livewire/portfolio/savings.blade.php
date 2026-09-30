<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Livewire\Concerns\InteractsWithPortfolio;
use App\Models\SavingsPlan;
use App\Support\Portfolio\SavingsPlans;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;
    use InteractsWithPortfolio;

    public bool $showForm = false;

    public ?int $editingId = null;

    /**
     * @var array<string, string>
     */
    public array $form = [];

    private function plans()
    {
        return SavingsPlan::whereIn('portfolio_id', $this->portfolios()->select('id'));
    }

    public function newPlan(): void
    {
        $this->resetValidation();
        $this->editingId = null;
        $this->form = ['portfolio_id' => (string) ($this->portfolioSettings()->default_portfolio_id ?: $this->portfolios()->value('id')), 'symbol' => '', 'name' => '', 'amount' => '', 'frequency' => 'monthly', 'day_of_month' => '1'];
        $this->showForm = true;
    }

    public function editPlan(int $planId): void
    {
        $plan = $this->plans()->find($planId) ?? abort(404);
        $this->resetValidation();
        $this->editingId = $plan->id;
        $this->form = ['portfolio_id' => (string) $plan->portfolio_id, 'symbol' => $plan->symbol, 'name' => (string) $plan->name, 'amount' => number_format($plan->amount, 2, ',', ''), 'frequency' => $plan->frequency, 'day_of_month' => (string) $plan->day_of_month];
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
    }

    public function savePlan(): void
    {
        $this->form['amount'] = $this->parseNumber($this->form['amount']);
        $this->form['symbol'] = strtoupper(trim((string) $this->form['symbol']));

        $data = $this->validate([
            'form.portfolio_id' => ['required', 'integer'],
            'form.symbol' => ['required', 'string', 'max:32'],
            'form.name' => ['nullable', 'string', 'max:255'],
            'form.amount' => ['required', 'numeric', 'min:1', 'max:1000000'],
            'form.frequency' => ['required', 'in:'.implode(',', SavingsPlan::FREQUENCIES)],
            'form.day_of_month' => ['required', 'integer', 'min:1', 'max:28'],
        ], attributes: ['form.symbol' => __('Security'), 'form.amount' => __('Amount')])['form'];

        $portfolio = $this->findPortfolio((int) $data['portfolio_id']);
        $attributes = [
            'portfolio_id' => $portfolio->id,
            'symbol' => $data['symbol'],
            'name' => $data['name'] ?: $data['symbol'],
            'amount' => (float) $data['amount'],
            'frequency' => $data['frequency'],
            'day_of_month' => (int) $data['day_of_month'],
        ];

        if ($this->editingId) {
            $plan = $this->plans()->find($this->editingId) ?? abort(404);
            $rescheduled = $plan->frequency !== $attributes['frequency'] || $plan->day_of_month !== $attributes['day_of_month'];
            $plan->update($attributes + ($rescheduled ? ['next_date' => SavingsPlans::firstDate($attributes['frequency'], $attributes['day_of_month'])] : []));
        } else {
            SavingsPlan::create($attributes + ['next_date' => SavingsPlans::firstDate($attributes['frequency'], $attributes['day_of_month']), 'active' => true]);
        }

        $this->showForm = false;
    }

    public function toggle(int $planId): void
    {
        $plan = $this->plans()->find($planId) ?? abort(404);
        $plan->update(['active' => ! $plan->active] + (! $plan->active && $plan->next_date->lt(today()) ? ['next_date' => SavingsPlans::firstDate($plan->frequency, $plan->day_of_month)] : []));
    }

    public function executeNow(int $planId, SavingsPlans $savingsPlans): void
    {
        $plan = $this->plans()->find($planId) ?? abort(404);

        if (! $savingsPlans->execute($plan, today())) {
            $this->dispatch('toast', message: __('No current price for :symbol – please try again later.', ['symbol' => $plan->symbol]));

            return;
        }

        $this->dispatch('toast', message: __('Purchase booked.'));
    }

    public function deletePlan(int $planId): void
    {
        ($this->plans()->find($planId) ?? abort(404))->delete();
        $this->showForm = false;
    }

    public function with(): array
    {
        $plans = $this->plans()->with('portfolio')->orderByDesc('active')->orderBy('next_date')->get();
        $perMonth = fn (SavingsPlan $plan) => match ($plan->frequency) {
            'weekly' => $plan->amount * 52 / 12,
            'quarterly' => $plan->amount / 3,
            default => $plan->amount,
        };

        return [
            'portfolioList' => $this->portfolios()->orderBy('id')->get(),
            'plans' => $plans,
            'monthly' => round($plans->where('active', true)->sum($perMonth), 2),
        ];
    }
}; ?>

@php
    $euro = fn ($value) => number_format((float) $value, 2, ',', '.').' €';
    $frequencies = ['weekly' => __('weekly'), 'monthly' => __('monthly'), 'quarterly' => __('quarterly')];
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
            @include('portfolio.partials.nav', ['active' => 'portfolio.savings'])

            <div class="flex flex-wrap items-center gap-3">
                <div>
                    <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Savings plans are booked automatically on the execution day at the current price.') }}</p>
                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ __(':amount per month', ['amount' => $euro($monthly)]) }} · {{ __(':amount per year', ['amount' => $euro($monthly * 12)]) }}</p>
                </div>
                @if ($portfolioList->isNotEmpty())
                    <x-primary-button type="button" wire:click="newPlan" class="ms-auto">＋ {{ __('Savings plan') }}</x-primary-button>
                @endif
            </div>

            @if ($plans->isEmpty())
                <div class="rounded-2xl bg-white p-8 text-center shadow-sm dark:bg-gray-800">
                    <p class="text-4xl" aria-hidden="true">💶</p>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ $portfolioList->isEmpty() ? __('Create a portfolio first.') : __('No savings plans yet.') }}</p>
                </div>
            @else
                <ul class="divide-y divide-gray-100 overflow-hidden rounded-2xl bg-white shadow-sm dark:divide-gray-700 dark:bg-gray-800">
                    @foreach ($plans as $plan)
                        <li wire:key="plan-{{ $plan->id }}" @class(['flex flex-wrap items-center gap-3 px-4 py-3', 'opacity-60' => ! $plan->active])>
                            <button type="button" wire:click="editPlan({{ $plan->id }})" class="min-w-0 flex-1 text-start">
                                <span class="block truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ $plan->name ?: $plan->symbol }}</span>
                                <span class="block truncate text-xs text-gray-500 dark:text-gray-400">
                                    {{ $plan->symbol }} · {{ $plan->portfolio->name }} · {{ $frequencies[$plan->frequency] ?? $plan->frequency }}
                                    · {{ $plan->active ? __('next on :date', ['date' => $plan->next_date->format('d.m.Y')]) : __('paused') }}
                                </span>
                            </button>
                            <span class="text-sm font-medium tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($plan->amount) }}</span>
                            <span class="flex shrink-0 gap-1">
                                <button type="button" wire:click="executeNow({{ $plan->id }})" wire:confirm="{{ __('Book a purchase for :amount now?', ['amount' => $euro($plan->amount)]) }}" class="rounded-lg px-2 py-1 text-xs text-indigo-600 hover:bg-indigo-50 dark:text-indigo-300 dark:hover:bg-gray-700">▶ {{ __('Book now') }}</button>
                                <button type="button" wire:click="toggle({{ $plan->id }})" class="rounded-lg px-2 py-1 text-xs text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">{{ $plan->active ? '⏸ '.__('Pause') : '▶ '.__('Resume') }}</button>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    @if ($showForm)
        <x-sheet close="closeForm" :label="$editingId ? __('Edit savings plan') : __('New savings plan')" wire:key="plan-form-{{ $editingId ?? 'new' }}">
            <x-slot:header><strong class="text-gray-800 dark:text-gray-100">💶 {{ $editingId ? __('Edit savings plan') : __('New savings plan') }}</strong></x-slot:header>

            <form id="plan-form" wire:submit="savePlan" class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <x-input-label for="plan-search" :value="__('Security')" />
                    @if ($form['symbol'])
                        <p class="mt-1 flex items-center gap-2 rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-gray-900/40"><strong>{{ $form['symbol'] }}</strong> <span class="truncate text-gray-600 dark:text-gray-300">{{ $form['name'] }}</span> <button type="button" wire:click="$set('form.symbol', '')" class="ms-auto text-gray-400 hover:text-gray-700" aria-label="{{ __('Change') }}"><x-icon name="x-mark" class="h-4 w-4" stroke-width="2" /></button></p>
                    @else
                        <div class="mt-1">@include('portfolio.partials.security-picker', ['id' => 'plan-search', 'model' => 'form.symbol', 'nameModel' => 'form.name'])</div>
                    @endif
                    <x-input-error :messages="$errors->get('form.symbol')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="plan-portfolio" :value="__('Portfolio')" />
                    <select id="plan-portfolio" wire:model="form.portfolio_id" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                        @foreach ($portfolioList as $portfolio)
                            <option value="{{ $portfolio->id }}">{{ $portfolio->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="plan-amount" :value="__('Amount (€)')" />
                    <x-text-input id="plan-amount" wire:model="form.amount" inputmode="decimal" class="mt-1 block w-full" placeholder="100" />
                    <x-input-error :messages="$errors->get('form.amount')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="plan-frequency" :value="__('Interval')" />
                    <select id="plan-frequency" wire:model.live="form.frequency" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                        @foreach ($frequencies as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                @if ($form['frequency'] !== 'weekly')
                    <div>
                        <x-input-label for="plan-day" :value="__('Execution day (1–28)')" />
                        <x-text-input id="plan-day" type="number" min="1" max="28" wire:model="form.day_of_month" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('form.day_of_month')" class="mt-1" />
                    </div>
                @endif
            </form>

            <x-slot:footer>
                <x-primary-button form="plan-form">{{ __('Save') }}</x-primary-button>
                @if ($editingId)
                    <button type="button" wire:click="deletePlan({{ $editingId }})" wire:confirm="{{ __('Delete this savings plan? Booked purchases stay.') }}" class="ms-auto rounded-lg px-3 py-2 text-sm text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-gray-700">{{ __('Delete') }}</button>
                @endif
            </x-slot:footer>
        </x-sheet>
    @endif
</div>
