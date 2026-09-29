<?php

use App\Models\BankAccount;
use App\Models\NetWorthItem;
use App\Models\NetWorthSnapshot;
use App\Models\SavingsGoal;
use App\Support\Finance\NetWorth;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Net worth across all modules with its history; bank balances and other assets or debts are entered here.
 */
new #[Layout('layouts.app')] class extends Component
{
    /**
     * @var array{name: string, kind: string, value: string}
     */
    public array $item = ['name' => '', 'kind' => NetWorthItem::ASSET, 'value' => ''];

    public int $editingAccountId = 0;

    /**
     * @var array{balance: string, balance_on: string}
     */
    public array $account = ['balance' => '', 'balance_on' => ''];

    public int $editingItemId = 0;

    /**
     * @var array{name: string, icon: string, target_amount: string, target_date: string, bank_account_id: string, saved_amount: string}
     */
    public array $goal = ['name' => '', 'icon' => '🎯', 'target_amount' => '', 'target_date' => '', 'bank_account_id' => '', 'saved_amount' => ''];

    public int $editingGoalId = 0;

    public string $goalSaved = '';

    public string $itemValue = '';

    public function mount(): void
    {
        $netWorth = NetWorth::for(Auth::user());

        if ($netWorth['assets'] != 0 || $netWorth['liabilities'] != 0) {
            NetWorth::snapshot(Auth::user());
        }
    }

    public function addItem(): void
    {
        $validated = $this->validate([
            'item.name' => ['required', 'string', 'max:120'],
            'item.kind' => ['required', Rule::in([NetWorthItem::ASSET, NetWorthItem::LIABILITY])],
            'item.value' => ['required', 'numeric', 'min:0', 'max:999999999'],
        ])['item'];

        NetWorthItem::create($validated + ['user_id' => Auth::id(), 'position' => (int) NetWorthItem::where('user_id', Auth::id())->max('position') + 1]);
        $this->reset('item');
        $this->refreshSnapshot();
    }

    public function editItem(int $id): void
    {
        $item = $this->findItem($id);
        $this->editingItemId = $item->id;
        $this->itemValue = number_format($item->value, 2, '.', '');
    }

    public function saveItem(): void
    {
        $this->validate(['itemValue' => ['required', 'numeric', 'min:0', 'max:999999999']]);
        $this->findItem($this->editingItemId)->update(['value' => $this->itemValue]);
        $this->reset('editingItemId', 'itemValue');
        $this->refreshSnapshot();
    }

    public function deleteItem(int $id): void
    {
        $this->findItem($id)->delete();
        $this->reset('editingItemId', 'itemValue');
        $this->refreshSnapshot();
    }

    public function editAccount(int $id): void
    {
        $account = $this->findAccount($id);
        $this->editingAccountId = $account->id;
        $this->account = ['balance' => $account->currentBalance() !== null ? number_format($account->currentBalance(), 2, '.', '') : '', 'balance_on' => today()->toDateString()];
    }

    public function saveAccount(): void
    {
        $validated = $this->validate([
            'account.balance' => ['required', 'numeric', 'min:-999999999', 'max:999999999'],
            'account.balance_on' => ['required', 'date', 'before_or_equal:today'],
        ])['account'];

        $this->findAccount($this->editingAccountId)->update($validated);
        $this->reset('editingAccountId', 'account');
        $this->refreshSnapshot();
    }

    public function cancelEdit(): void
    {
        $this->reset('editingAccountId', 'account', 'editingItemId', 'itemValue');
        $this->resetErrorBag();
    }

    public function addGoal(): void
    {
        $validated = $this->validate([
            'goal.name' => ['required', 'string', 'max:120'],
            'goal.icon' => ['nullable', 'string', 'max:16'],
            'goal.target_amount' => ['required', 'numeric', 'min:1', 'max:999999999'],
            'goal.target_date' => ['nullable', 'date', 'after:today'],
            'goal.bank_account_id' => ['nullable', \Illuminate\Validation\Rule::exists('bank_accounts', 'id')->where('user_id', Auth::id())],
            'goal.saved_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ], attributes: ['goal.name' => __('Name'), 'goal.target_amount' => __('Target amount')])['goal'];

        SavingsGoal::create([
            'user_id' => Auth::id(),
            'name' => $validated['name'],
            'icon' => ($validated['icon'] ?? '') ?: '🎯',
            'target_amount' => $validated['target_amount'],
            'target_date' => ($validated['target_date'] ?? '') ?: null,
            'bank_account_id' => ($validated['bank_account_id'] ?? '') ?: null,
            'saved_amount' => ($validated['saved_amount'] ?? '') ?: 0,
            'position' => (int) SavingsGoal::where('user_id', Auth::id())->max('position') + 1,
        ]);
        $this->reset('goal');
    }

    public function editGoal(int $id): void
    {
        $goal = $this->findGoal($id);
        $this->editingGoalId = $goal->id;
        $this->goalSaved = number_format($goal->saved_amount, 2, '.', '');
    }

    public function saveGoal(): void
    {
        $this->validate(['goalSaved' => ['required', 'numeric', 'min:0', 'max:999999999']]);
        $this->findGoal($this->editingGoalId)->update(['saved_amount' => $this->goalSaved]);
        $this->reset('editingGoalId', 'goalSaved');
    }

    public function deleteGoal(int $id): void
    {
        $this->findGoal($id)->delete();
        $this->reset('editingGoalId', 'goalSaved');
    }

    private function findGoal(int $id): SavingsGoal
    {
        return SavingsGoal::where('user_id', Auth::id())->find($id) ?? abort(404);
    }

    private function findItem(int $id): NetWorthItem
    {
        return NetWorthItem::where('user_id', Auth::id())->find($id) ?? abort(404);
    }

    private function findAccount(int $id): BankAccount
    {
        return BankAccount::where('user_id', Auth::id())->find($id) ?? abort(404);
    }

    private function refreshSnapshot(): void
    {
        NetWorth::snapshot(Auth::user());
    }

    public function with(): array
    {
        $netWorth = NetWorth::for(Auth::user());
        $history = NetWorthSnapshot::where('user_id', Auth::id())->where('taken_on', '>=', today()->subYear()->toDateString())->orderBy('taken_on')->get();
        $monthAgo = $history->last(fn (NetWorthSnapshot $snapshot) => $snapshot->taken_on->lte(today()->subDays(30)));

        return [
            'goals' => SavingsGoal::where('user_id', Auth::id())->with('bankAccount')->orderBy('position')->get(),
            'accounts' => \App\Models\BankAccount::where('user_id', Auth::id())->orderBy('name')->get(['id', 'name']),
            'netWorth' => $netWorth,
            'points' => $history->map(fn (NetWorthSnapshot $snapshot) => ['label' => $snapshot->taken_on->translatedFormat('j. M Y'), 'value' => $snapshot->netWorth()])->values()->all(),
            'change' => $monthAgo ? round($netWorth['net'] - $monthAgo->netWorth(), 2) : null,
        ];
    }
}; ?>

@php
    $euro = fn (?float $value) => $value === null ? '–' : number_format($value, 2, ',', '.').' €';
    $input = 'block w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
@endphp

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">💎 {{ __('Net worth') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-5xl space-y-5 px-4 sm:px-6 lg:px-8">
            {{-- Headline figures --}}
            <section class="grid gap-3 sm:grid-cols-3">
                <div class="rounded-2xl bg-white p-5 shadow-sm sm:col-span-1 dark:bg-gray-800">
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Net worth') }}</p>
                    <p @class(['mt-1 text-3xl font-semibold tabular-nums', 'text-gray-900 dark:text-gray-100' => $netWorth['net'] >= 0, 'text-rose-600 dark:text-rose-400' => $netWorth['net'] < 0])>{{ $euro($netWorth['net']) }}</p>
                    @if ($change !== null)
                        <p @class(['mt-1 text-sm', 'text-emerald-700 dark:text-emerald-400' => $change >= 0, 'text-rose-600 dark:text-rose-400' => $change < 0])>{{ $change >= 0 ? '▲' : '▼' }} {{ $euro(abs($change)) }} {{ __('in 30 days') }}</p>
                    @endif
                </div>
                <div class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Assets') }}</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($netWorth['assets']) }}</p>
                </div>
                <div class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Debts') }}</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($netWorth['liabilities']) }}</p>
                </div>
            </section>

            <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                <h3 class="mb-3 font-semibold text-gray-900 dark:text-gray-100">{{ __('History') }}</h3>
                <x-line-chart :points="$points" :value-label="__('Net worth')" :height="200" />
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('A value is stored every evening, and whenever you change something here.') }}</p>
            </section>

            {{-- Savings goals --}}
            <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                <h3 class="font-semibold text-gray-900 dark:text-gray-100">🎯 {{ __('Savings goals') }}</h3>
                <ul class="mt-3 space-y-3">
                    @forelse ($goals as $savingsGoal)
                        <li wire:key="goal-{{ $savingsGoal->id }}">
                            <div class="flex flex-wrap items-baseline justify-between gap-2 text-sm">
                                <span class="font-medium text-gray-900 dark:text-gray-100">{{ $savingsGoal->icon }} {{ $savingsGoal->name }}</span>
                                <span class="tabular-nums text-gray-600 dark:text-gray-300">{{ $euro($savingsGoal->savedSoFar()) }} / {{ $euro($savingsGoal->target_amount) }}</span>
                            </div>
                            <div class="mt-1 h-2.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700" role="progressbar" aria-valuenow="{{ $savingsGoal->progress() }}" aria-valuemin="0" aria-valuemax="100" aria-label="{{ $savingsGoal->name }}">
                                <div @class(['h-full rounded-full', 'bg-emerald-500' => $savingsGoal->progress() >= 100, 'bg-indigo-500' => $savingsGoal->progress() < 100]) style="width: {{ $savingsGoal->progress() }}%"></div>
                            </div>
                            <div class="mt-1 flex flex-wrap items-center gap-x-3 text-xs text-gray-500 dark:text-gray-400">
                                <span>{{ number_format($savingsGoal->progress(), 0, ',', '.') }} %</span>
                                @if ($savingsGoal->bankAccount)<span>🏦 {{ $savingsGoal->bankAccount->name }}</span>@endif
                                @if ($savingsGoal->target_date)<span>{{ __('by :date', ['date' => $savingsGoal->target_date->translatedFormat('M Y')]) }}</span>@endif
                                @if ($needed = $savingsGoal->monthlyNeeded())<span>{{ __(':amount per month needed', ['amount' => $euro($needed)]) }}</span>@endif
                                @if (! $savingsGoal->bankAccount)
                                    <button type="button" wire:click="editGoal({{ $savingsGoal->id }})" class="text-indigo-600 underline dark:text-indigo-400">{{ __('Update amount') }}</button>
                                @endif
                                <button type="button" wire:click="deleteGoal({{ $savingsGoal->id }})" wire:confirm="{{ __('Delete this goal?') }}" class="text-red-600 underline dark:text-red-400">{{ __('Delete') }}</button>
                            </div>
                            @if ($editingGoalId === $savingsGoal->id)
                                <form wire:submit="saveGoal" class="mt-2 flex flex-wrap items-center gap-2">
                                    <input type="number" step="0.01" min="0" wire:model="goalSaved" class="{{ $input }} w-36" aria-label="{{ __('Saved so far') }}">
                                    <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
                                    <x-secondary-button type="button" wire:click="$set('editingGoalId', 0)">{{ __('Cancel') }}</x-secondary-button>
                                </form>
                            @endif
                        </li>
                    @empty
                        <li class="text-sm text-gray-500 dark:text-gray-400">{{ __('e.g. emergency fund, holiday, new car – linked to an account the progress updates itself.') }}</li>
                    @endforelse
                </ul>
                <form wire:submit="addGoal" class="mt-4 grid gap-2 sm:grid-cols-[4rem_1fr_9rem_10rem]">
                    <input wire:model="goal.icon" class="{{ $input }} text-center" aria-label="{{ __('Icon') }}">
                    <input wire:model="goal.name" placeholder="{{ __('Goal, e.g. emergency fund') }}" class="{{ $input }}" aria-label="{{ __('Name') }}">
                    <input type="number" step="0.01" min="1" wire:model="goal.target_amount" placeholder="{{ __('Target in €') }}" class="{{ $input }}" aria-label="{{ __('Target amount') }}">
                    <input type="date" wire:model="goal.target_date" class="{{ $input }}" aria-label="{{ __('Target date') }}">
                    <select wire:model.live="goal.bank_account_id" class="{{ $input }} sm:col-span-2" aria-label="{{ __('Progress from') }}">
                        <option value="">{{ __('Enter the saved amount myself') }}</option>
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}">🏦 {{ $account->name }}</option>
                        @endforeach
                    </select>
                    @if (($goal['bank_account_id'] ?? '') === '')
                        <input type="number" step="0.01" min="0" wire:model="goal.saved_amount" placeholder="{{ __('Saved so far') }}" class="{{ $input }}" aria-label="{{ __('Saved so far') }}">
                    @endif
                    <x-primary-button type="submit" class="justify-center">{{ __('Add goal') }}</x-primary-button>
                </form>
                <x-input-error class="mt-1" :messages="array_merge($errors->get('goal.name'), $errors->get('goal.target_amount'), $errors->get('goal.target_date'))" />
            </section>

            {{-- Composition --}}
            <div class="grid gap-4 md:grid-cols-2">
                @foreach ($netWorth['groups'] as $group)
                    @continue($group['rows'] === [] && ! in_array($group['key'], ['other_assets', 'other_liabilities'], true))
                    <section wire:key="group-{{ $group['key'] }}" class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                        <div class="flex items-baseline justify-between gap-2">
                            <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ $group['icon'] }} {{ $group['label'] }}</h3>
                            <span @class(['font-semibold tabular-nums', 'text-rose-600 dark:text-rose-400' => $group['liability'] && $group['total'] > 0, 'text-gray-900 dark:text-gray-100' => ! $group['liability'] || $group['total'] <= 0])>{{ $group['liability'] && $group['total'] > 0 ? '− ' : '' }}{{ $euro($group['total']) }}</span>
                        </div>
                        <ul class="mt-3 divide-y divide-gray-100 text-sm dark:divide-gray-700">
                            @forelse ($group['rows'] as $row)
                                <li wire:key="row-{{ $group['key'] }}-{{ $loop->index }}" class="py-2">
                                    <div class="flex items-center gap-2">
                                        <div class="min-w-0 flex-1">
                                            @if ($row['url'])
                                                <a href="{{ $row['url'] }}" wire:navigate class="block truncate text-gray-800 hover:underline dark:text-gray-200">{{ $row['label'] }}</a>
                                            @else
                                                <span class="block truncate text-gray-800 dark:text-gray-200">{{ $row['label'] }}</span>
                                            @endif
                                            @if ($row['hint'])<span class="block text-xs text-gray-500 dark:text-gray-400">{{ $row['hint'] }}</span>@endif
                                        </div>
                                        <span class="tabular-nums text-gray-700 dark:text-gray-300">{{ $euro($row['value']) }}</span>
                                        @if ($group['key'] === 'accounts')
                                            <button type="button" wire:click="editAccount({{ $row['id'] }})" class="rounded px-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-700" title="{{ __('Enter balance') }}" aria-label="{{ __('Enter balance') }}">✎</button>
                                        @elseif (in_array($group['key'], ['other_assets', 'other_liabilities'], true))
                                            <button type="button" wire:click="editItem({{ $row['id'] }})" class="rounded px-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-700" title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}">✎</button>
                                        @endif
                                    </div>
                                    @if ($group['key'] === 'accounts' && $editingAccountId === $row['id'])
                                        <form wire:submit="saveAccount" class="mt-2 grid grid-cols-2 gap-2 rounded-xl bg-gray-50 p-3 dark:bg-gray-900/50">
                                            <label class="text-xs text-gray-600 dark:text-gray-300">{{ __('Balance') }}
                                                <input type="number" step="0.01" wire:model="account.balance" class="{{ $input }} mt-1">
                                            </label>
                                            <label class="text-xs text-gray-600 dark:text-gray-300">{{ __('As of') }}
                                                <input type="date" wire:model="account.balance_on" class="{{ $input }} mt-1">
                                            </label>
                                            <p class="col-span-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Transactions booked after this day are added automatically.') }}</p>
                                            <x-input-error class="col-span-2" :messages="array_merge($errors->get('account.balance'), $errors->get('account.balance_on'))" />
                                            <div class="col-span-2 flex gap-2">
                                                <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
                                                <x-secondary-button type="button" wire:click="cancelEdit">{{ __('Cancel') }}</x-secondary-button>
                                            </div>
                                        </form>
                                    @elseif (in_array($group['key'], ['other_assets', 'other_liabilities'], true) && $editingItemId === $row['id'])
                                        <form wire:submit="saveItem" class="mt-2 flex flex-wrap items-center gap-2 rounded-xl bg-gray-50 p-3 dark:bg-gray-900/50">
                                            <input type="number" step="0.01" min="0" wire:model="itemValue" class="{{ $input }} w-36" aria-label="{{ __('Value') }}">
                                            <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
                                            <x-secondary-button type="button" wire:click="cancelEdit">{{ __('Cancel') }}</x-secondary-button>
                                            <button type="button" wire:click="deleteItem({{ $row['id'] }})" wire:confirm="{{ __('Delete this entry?') }}" class="ms-auto text-xs text-red-600 underline dark:text-red-400">{{ __('Delete') }}</button>
                                            <x-input-error class="w-full" :messages="$errors->get('itemValue')" />
                                        </form>
                                    @endif
                                </li>
                            @empty
                                <li class="py-2 text-gray-500 dark:text-gray-400">{{ $group['key'] === 'other_assets' ? __('e.g. cash, car, call money at another bank') : __('e.g. car loan, private loan') }}</li>
                            @endforelse
                        </ul>
                    </section>
                @endforeach
            </div>

            {{-- Add an asset or debt --}}
            <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                <h3 class="font-semibold text-gray-900 dark:text-gray-100">＋ {{ __('Add asset or debt') }}</h3>
                <form wire:submit="addItem" class="mt-3 grid gap-2 sm:grid-cols-[1fr_12rem_10rem_auto]">
                    <input wire:model="item.name" placeholder="{{ __('Name, e.g. car') }}" class="{{ $input }}" aria-label="{{ __('Name') }}">
                    <select wire:model="item.kind" class="{{ $input }}" aria-label="{{ __('Kind') }}">
                        <option value="asset">💎 {{ __('Asset') }}</option>
                        <option value="liability">📉 {{ __('Debt') }}</option>
                    </select>
                    <input type="number" step="0.01" min="0" wire:model="item.value" placeholder="{{ __('Value in €') }}" class="{{ $input }}" aria-label="{{ __('Value') }}">
                    <x-primary-button type="submit" class="justify-center">{{ __('Add') }}</x-primary-button>
                </form>
                <x-input-error class="mt-1" :messages="array_merge($errors->get('item.name'), $errors->get('item.kind'), $errors->get('item.value'))" />
            </section>

            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Shared properties count in full for every member. Loans are calculated from the amount, interest and repayment since their start.') }}</p>
        </div>
    </div>
</div>
