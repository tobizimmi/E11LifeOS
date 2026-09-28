<?php

use App\Support\ContractOverview;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Costs of all contracts (from collections with a cost field) and the next cancellation deadlines.
 */
new #[Layout('layouts.app')] class extends Component
{
    public function with(): array
    {
        $user = Auth::user();
        $household = $user->currentHousehold();
        abort_unless($household, 404);

        return ['overview' => ContractOverview::for($user, $household->id)];
    }
}; ?>

@php
    $euro = fn (float $value) => number_format($value, 2, ',', '.').' €';
    $date = fn (?string $value) => $value ? \Illuminate\Support\Carbon::parse($value)->format('d.m.Y') : '–';
    $maxCategory = max([1, ...array_values($overview['by_category'])]);
@endphp

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">📑 {{ __('Contract costs') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-5xl space-y-5 px-4 sm:px-6 lg:px-8">
            <div class="flex justify-end">
                <a href="{{ route('letters.index') }}" wire:navigate class="rounded-md bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700">✉️ {{ __('Write a letter') }}</a>
            </div>

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
