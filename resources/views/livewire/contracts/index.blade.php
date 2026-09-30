<?php

use App\Support\ContractOverview;
use App\Support\Finance\SubscriptionMatcher;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Costs of all contracts (from collections with a cost field), the next cancellation deadlines
 * and the comparison with the recurring payments from the bank bookings.
 */
new #[Layout('layouts.app')] class extends Component
{
    private function householdId(): int
    {
        $household = Auth::user()->currentHousehold();
        abort_unless($household, 404);

        return $household->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function payment(string $key): array
    {
        return collect(SubscriptionMatcher::for(Auth::user(), $this->householdId())['unmatched'])->firstWhere('key', $key) ?? abort(404);
    }

    /**
     * Creates a contract entry for a recurring payment.
     */
    public function adopt(string $key): void
    {
        $item = SubscriptionMatcher::adopt(Auth::user(), $this->householdId(), $this->payment($key));
        $this->dispatch('toast', message: __('":name" added to your contracts.', ['name' => $item->title]));
    }

    /**
     * Hides a recurring payment that is no contract.
     */
    public function dismiss(string $key): void
    {
        $this->payment($key);
        $user = Auth::user();
        $user->update(['dismissed_subscriptions' => array_values(array_unique([...$user->dismissed_subscriptions ?? [], $key]))]);
    }

    /**
     * Takes over the amount actually paid as the contract's cost.
     */
    public function updateCost(int $itemId): void
    {
        $match = collect(SubscriptionMatcher::for(Auth::user(), $this->householdId())['matched'])->first(fn (array $match) => $match['contract']['item']->id === $itemId && $match['difference'] !== null) ?? abort(404);
        SubscriptionMatcher::updateCost($match['contract']['item'], $match['payment']['monthly']);
    }

    public function with(): array
    {
        $user = Auth::user();

        return [
            'overview' => ContractOverview::for($user, $this->householdId()),
            'matching' => SubscriptionMatcher::for($user, $this->householdId()),
        ];
    }
}; ?>

@php
    $euro = fn (float $value) => number_format($value, 2, ',', '.').' €';
    $date = fn (?string $value) => $value ? \Illuminate\Support\Carbon::parse($value)->format('d.m.Y') : '–';
    $maxCategory = max([1, ...array_values($overview['by_category'])]);
@endphp

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Contract costs') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-5xl space-y-5 px-4 sm:px-6 lg:px-8">
            <div class="flex justify-end">
                <a href="{{ route('letters.index') }}" wire:navigate class="rounded-md bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700">✉️ {{ __('Write a letter') }}</a>
            </div>

            @php($changes = array_values(array_filter($matching['matched'], fn ($match) => $match['difference'] !== null)))
            @if ($matching['unmatched'] !== [] || $changes !== [] || $matching['without_payment'] !== [])
                <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">🔍 {{ __('Compared with your bank bookings') }}</h3>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('Recurring payments of the last months next to your contracts – so no subscription is forgotten.') }}</p>

                    @if ($changes !== [])
                        <h4 class="mt-4 text-sm font-medium text-amber-800 dark:text-amber-300">📈 {{ __('Amount differs') }}</h4>
                        <ul class="mt-1 divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach ($changes as $match)
                                <li wire:key="change-{{ $match['contract']['item']->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2 text-sm">
                                    <a href="{{ $match['contract']['url'] }}" wire:navigate class="min-w-0 basis-full font-medium text-gray-900 hover:underline dark:text-gray-100 sm:basis-0 sm:flex-1">{{ $match['contract']['item']->title }}</a>
                                    <span class="tabular-nums text-gray-600 dark:text-gray-300">{{ __('Contract :contract, debited :debited per month', ['contract' => $euro($match['contract']['monthly']), 'debited' => $euro($match['payment']['monthly'])]) }}</span>
                                    <button type="button" wire:click="updateCost({{ $match['contract']['item']->id }})" class="rounded-lg px-2.5 py-1 text-xs text-indigo-700 ring-1 ring-indigo-200 hover:bg-indigo-50 dark:text-indigo-300 dark:ring-indigo-800 dark:hover:bg-gray-700">{{ __('Take over amount') }}</button>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($matching['unmatched'] !== [])
                        <h4 class="mt-4 text-sm font-medium text-gray-800 dark:text-gray-200">➕ {{ __('Payments without a contract') }}</h4>
                        <ul class="mt-1 divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach ($matching['unmatched'] as $payment)
                                <li wire:key="payment-{{ md5($payment['key']) }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2 text-sm">
                                    <span class="min-w-0 basis-full sm:basis-0 sm:flex-1"><span class="font-medium text-gray-900 dark:text-gray-100">{{ $payment['name'] }}</span><span class="block text-xs text-gray-500 dark:text-gray-400">{{ $euro($payment['amount']) }} · {{ __(['weekly' => 'weekly', 'monthly' => 'monthly', 'bimonthly' => 'every 2 months', 'quarterly' => 'quarterly', 'halfyearly' => 'half-yearly', 'yearly' => 'yearly'][$payment['frequency']]) }} · {{ __('last :date', ['date' => $payment['lastDate']->format('d.m.Y')]) }}</span></span>
                                    <span class="me-auto tabular-nums text-gray-600 dark:text-gray-300 sm:me-0">{{ $euro($payment['monthly']) }} / {{ __('month') }}</span>
                                    <button type="button" wire:click="adopt(@js($payment['key']))" class="rounded-lg bg-indigo-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-indigo-700">{{ __('Add as contract') }}</button>
                                    <button type="button" wire:click="dismiss(@js($payment['key']))" class="rounded-lg px-2.5 py-1 text-xs text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">{{ __('Not a contract') }}</button>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($matching['has_bookings'] && $matching['without_payment'] !== [])
                        <h4 class="mt-4 text-sm font-medium text-gray-800 dark:text-gray-200">❓ {{ __('Contracts without a matching payment') }}</h4>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Already cancelled, paid from another account or named differently at the bank?') }}</p>
                        <ul class="mt-1 flex flex-wrap gap-2">
                            @foreach ($matching['without_payment'] as $contract)
                                <li wire:key="lonely-{{ $contract['item']->id }}"><a href="{{ $contract['url'] }}" wire:navigate class="inline-block rounded-full bg-gray-100 px-3 py-1 text-xs text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">{{ $contract['item']->title }} · {{ $euro($contract['monthly']) }}</a></li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endif

            @if ($overview['contracts'] === [])
                <section class="rounded-2xl bg-white p-6 text-center shadow-sm dark:bg-gray-800">
                    <p class="text-gray-700 dark:text-gray-200">{{ __('No contracts with costs yet.') }}</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Create a collection from the “Contracts & documents” template and enter the monthly cost – everything is summed up here.') }}</p>
                    <a href="{{ route('collections.index') }}" wire:navigate class="mt-3 inline-block text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400">{{ __('To the collections') }} →</a>
                </section>
            @else
                <section class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Per month') }}</p>
                        <p class="mt-1 text-3xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($overview['monthly']) }}</p>
                    </div>
                    <div class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Per year') }}</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($overview['yearly']) }}</p>
                    </div>
                    <div class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Contracts') }}</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ count($overview['contracts']) }}</p>
                    </div>
                </section>

                @if ($overview['deadlines'] !== [])
                    <section class="rounded-2xl bg-amber-50 p-5 shadow-sm ring-1 ring-amber-200 dark:bg-amber-900/20 dark:ring-amber-800">
                        <h3 class="font-semibold text-amber-900 dark:text-amber-200">⏰ {{ __('Cancellation deadlines in the next 90 days') }}</h3>
                        <ul class="mt-2 space-y-2 text-sm">
                            @foreach ($overview['deadlines'] as $deadline)
                                <li wire:key="deadline-{{ $deadline['item']->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                    <a href="{{ $deadline['url'] }}" wire:navigate class="font-medium text-gray-900 hover:underline dark:text-gray-100">{{ $deadline['item']->title }}</a>
                                    <span class="text-amber-800 dark:text-amber-300">{{ __('cancel by :date', ['date' => $date($deadline['cancel_by'])]) }} · {{ trans_choice('in :count day|in :count days', ContractOverview::daysUntil($deadline['cancel_by'])) }}</span>
                                    <a href="{{ route('letters.index', ['template' => 'cancellation', 'entry' => $deadline['item']->id]) }}" wire:navigate class="ml-auto text-indigo-600 hover:underline dark:text-indigo-400">✂️ {{ __('Write cancellation') }}</a>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('By category') }}</h3>
                    <ul class="mt-3 space-y-2 text-sm">
                        @foreach ($overview['by_category'] as $category => $amount)
                            <li>
                                <div class="flex justify-between gap-2"><span class="text-gray-700 dark:text-gray-200">{{ $category }}</span><span class="tabular-nums text-gray-600 dark:text-gray-300">{{ $euro($amount) }} / {{ __('month') }}</span></div>
                                <div class="mt-1 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700"><div class="h-full rounded-full bg-indigo-500" style="width: {{ round($amount / $maxCategory * 100) }}%"></div></div>
                            </li>
                        @endforeach
                    </ul>
                </section>

                <section class="overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-gray-800">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500 dark:bg-gray-900/40 dark:text-gray-400">
                                <tr>
                                    <th class="px-4 py-2">{{ __('Contract') }}</th>
                                    <th class="px-4 py-2">{{ __('Provider') }}</th>
                                    <th class="px-4 py-2 text-right">{{ __('Per month') }}</th>
                                    <th class="px-4 py-2">{{ __('Cancel by') }}</th>
                                    <th class="px-4 py-2"><span class="sr-only">{{ __('Actions') }}</span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                @foreach ($overview['contracts'] as $contract)
                                    <tr wire:key="contract-{{ $contract['item']->id }}">
                                        <td class="px-4 py-2"><a href="{{ $contract['url'] }}" wire:navigate class="font-medium text-gray-900 hover:underline dark:text-gray-100">{{ $contract['item']->title }}</a><span class="block text-xs text-gray-500 dark:text-gray-400">{{ $contract['collection']->name }}</span></td>
                                        <td class="px-4 py-2 text-gray-700 dark:text-gray-300">{{ $contract['provider'] ?? '–' }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($contract['monthly']) }}</td>
                                        <td class="px-4 py-2 text-gray-700 dark:text-gray-300">{{ $date($contract['cancel_by']) }}</td>
                                        <td class="whitespace-nowrap px-4 py-2 text-right"><a href="{{ route('letters.index', ['template' => 'cancellation', 'entry' => $contract['item']->id]) }}" wire:navigate class="text-indigo-600 hover:underline dark:text-indigo-400">✂️ {{ __('Cancel') }}</a></td>
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
