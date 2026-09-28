<?php

use App\Models\BankAccount;
use App\Models\NetWorthItem;
use App\Models\NetWorthSnapshot;
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
