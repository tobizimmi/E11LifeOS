<?php

use App\Support\Admin\AdminOverview;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Admin › Overview: accounts and activity, storage per household, support queue and the error
 * trend of the last two weeks at a glance.
 */
new #[Layout('layouts.app')] class extends Component
{
    public function mount(): void
    {
        $this->authorize('admin');
    }

    public function with(): array
    {
        $heartbeat = Cache::store('monitoring')->get('scheduler:heartbeat');

        return [
            'accounts' => AdminOverview::accounts(),
            'storage' => AdminOverview::storage(),
            'support' => AdminOverview::support(),
            'errors' => AdminOverview::errors(),
            'failedJobs' => rescue(fn () => DB::table('failed_jobs')->count(), 0, false),
            'heartbeat' => $heartbeat ? Carbon::parse($heartbeat) : null,
        ];
    }
}; ?>

@php
    $card = 'rounded-2xl bg-white p-4 shadow-xs dark:bg-gray-800';
    $label = 'text-xs leading-tight text-gray-500 dark:text-gray-400';
    $value = 'mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-gray-100';
    $mb = fn (int $bytes) => number_format($bytes / 1048576, 1, ',', '.').' MB';
    $maxErrors = max(1, ...array_values($errors));
    $errorTotal = array_sum($errors);
@endphp

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Administration') }}</h2>
        <x-admin-nav />
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
            <section aria-labelledby="overview-accounts">
                <h3 id="overview-accounts" class="mb-2 text-sm font-semibold text-gray-700 dark:text-gray-300">👥 {{ __('Accounts') }}</h3>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <a href="{{ route('admin.users') }}" wire:navigate class="{{ $card }} hover:ring-1 hover:ring-indigo-200 dark:hover:ring-indigo-800"><p class="{{ $label }}">{{ __('Accounts') }}</p><p class="{{ $value }}">{{ $accounts['total'] }}</p><p class="text-xs text-gray-500 dark:text-gray-400">{{ trans_choice(':count administrator|:count administrators', $accounts['admins']) }}</p></a>
                    <div class="{{ $card }}"><p class="{{ $label }}">{{ __('Active (7 days)') }}</p><p class="{{ $value }}">{{ $accounts['active7'] }}</p></div>
                    <div class="{{ $card }}"><p class="{{ $label }}">{{ __('Active (30 days)') }}</p><p class="{{ $value }}">{{ $accounts['active30'] }}</p></div>
                    <div class="{{ $card }}"><p class="{{ $label }}">{{ __('New (30 days)') }}</p><p class="{{ $value }}">{{ $accounts['new30'] }}</p></div>
                </div>
            </section>

            <section aria-labelledby="overview-support">
                <h3 id="overview-support" class="mb-2 text-sm font-semibold text-gray-700 dark:text-gray-300">🎧 {{ __('Support') }}</h3>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <a href="{{ route('admin.support') }}" wire:navigate class="{{ $card }} hover:ring-1 hover:ring-indigo-200 dark:hover:ring-indigo-800"><p class="{{ $label }}">{{ __('Unsolved') }}</p><p class="{{ $value }}">{{ $support['unsolved'] }}</p></a>
                    <a href="{{ route('admin.support', ['view' => 'unassigned']) }}" wire:navigate class="{{ $card }} hover:ring-1 hover:ring-indigo-200 dark:hover:ring-indigo-800"><p class="{{ $label }}">{{ __('Unassigned') }}</p><p class="{{ $value }}">{{ $support['unassigned'] }}</p></a>
                    <a href="{{ route('admin.support', ['view' => 'overdue']) }}" wire:navigate class="{{ $card }} hover:ring-1 hover:ring-indigo-200 dark:hover:ring-indigo-800"><p class="{{ $label }}">{{ __('Overdue') }}</p><p @class([$value, 'text-red-600! dark:text-red-400!' => $support['overdue'] > 0])>@if ($support['overdue'] > 0)⏰ @endif{{ $support['overdue'] }}</p></a>
                    <div class="{{ $card }}"><p class="{{ $label }}">{{ __('New requests (7 days)') }}</p><p class="{{ $value }}">{{ $support['new7'] }}</p></div>
                </div>
            </section>

            <div class="grid gap-6 lg:grid-cols-2">
                <section aria-labelledby="overview-errors" class="{{ $card }}">
                    <div class="flex items-baseline justify-between gap-2">
                        <h3 id="overview-errors" class="text-sm font-semibold text-gray-700 dark:text-gray-300">🐞 {{ __('Errors per day') }}</h3>
                        <a href="{{ route('admin.system') }}" wire:navigate class="text-xs text-indigo-600 underline dark:text-indigo-400">{{ __('System') }}</a>
                    </div>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ trans_choice(':count error in the last 14 days|:count errors in the last 14 days', $errorTotal) }} · {{ trans_choice(':count failed job|:count failed jobs', $failedJobs) }} · {{ __('Scheduler') }}: {{ $heartbeat ? $heartbeat->diffForHumans() : __('never') }}</p>
                    <div class="mt-4 flex h-32 items-end gap-1" aria-hidden="true">
                        @foreach ($errors as $day => $count)
                            <div class="group relative flex h-full flex-1 flex-col justify-end" title="{{ \Illuminate\Support\Carbon::parse($day)->format('d.m.') }}: {{ $count }}">
                                <div @class(['w-full rounded-t-sm', 'bg-indigo-500 dark:bg-indigo-400' => $count > 0, 'bg-gray-200 dark:bg-gray-700' => $count === 0]) style="height: {{ $count > 0 ? max(4, round($count / $maxErrors * 100)) : 2 }}%"></div>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-1 flex justify-between text-[11px] text-gray-500 dark:text-gray-400" aria-hidden="true">
                        <span>{{ \Illuminate\Support\Carbon::parse(array_key_first($errors))->format('d.m.') }}</span>
                        <span>{{ __('today') }}</span>
                    </div>
                    <table class="sr-only">
                        <caption>{{ __('Errors per day') }}</caption>
                        <tbody>
                            @foreach ($errors as $day => $count)<tr><th scope="row">{{ \Illuminate\Support\Carbon::parse($day)->format('d.m.Y') }}</th><td>{{ $count }}</td></tr>@endforeach
                        </tbody>
                    </table>
                </section>

                <section aria-labelledby="overview-storage" class="{{ $card }}">
                    <div class="flex items-baseline justify-between gap-2">
                        <h3 id="overview-storage" class="text-sm font-semibold text-gray-700 dark:text-gray-300">💾 {{ __('Storage per household') }}</h3>
                        <a href="{{ route('admin.households') }}" wire:navigate class="text-xs text-indigo-600 underline dark:text-indigo-400">{{ __('Households') }}</a>
                    </div>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ trans_choice(':count household|:count households', $storage['total']) }} · {{ $mb($storage['bytes']) }}</p>
                    <ul class="mt-3 space-y-2.5">
                        @forelse ($storage['top'] as $household)
                            <li wire:key="storage-{{ $household['id'] }}">
                                <div class="flex items-baseline justify-between gap-2 text-sm">
                                    <span class="truncate text-gray-900 dark:text-gray-100">🏠 {{ $household['name'] }}</span>
                                    <span class="shrink-0 tabular-nums text-gray-600 dark:text-gray-300">{{ $mb($household['bytes']) }}</span>
                                </div>
                                <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                                    <div class="h-full rounded-full bg-indigo-500 dark:bg-indigo-400" style="width: {{ $storage['bytes'] > 0 ? max(1, round($household['bytes'] / $storage['bytes'] * 100)) : 0 }}%"></div>
                                </div>
                                <p class="mt-0.5 text-[11px] text-gray-500 dark:text-gray-400">{{ trans_choice(':count file|:count files', $household['files']) }} · {{ trans_choice(':count entry|:count entries', $household['entries']) }}</p>
                            </li>
                        @empty
                            <li class="text-sm text-gray-500 dark:text-gray-400">{{ __('No households yet.') }}</li>
                        @endforelse
                    </ul>
                </section>
            </div>
        </div>
    </div>
</div>
