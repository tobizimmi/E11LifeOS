<?php

use App\Support\Modules\Licenses;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Modules & subscription: which licensed areas the household has, trials to start and license
 * requests to the team.
 */
new #[Layout('layouts.app')] class extends Component
{
    public ?string $requesting = null;

    public string $note = '';

    public function startTrial(string $module): void
    {
        Licenses::startTrial(Auth::user(), $module);
        $this->dispatch('toast', message: __('The trial has started – have fun!'));
        $this->redirectRoute('modules.index', navigate: true);
    }

    public function openRequest(string $module): void
    {
        $this->requesting = $module;
        $this->reset('note');
    }

    public function sendRequest(): void
    {
        $this->validate(['note' => ['nullable', 'string', 'max:2000']]);
        $ticket = Licenses::request(Auth::user(), (string) $this->requesting, $this->note);
        $this->reset('requesting', 'note');
        $this->dispatch('toast', message: __('Request sent as :number – the team gets back to you.', ['number' => $ticket->number()]));
    }

    public function with(): array
    {
        return ['modules' => Licenses::overview(Auth::user())];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Modules & subscription') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-3xl space-y-4 px-4 sm:px-6 lg:px-8">
            @if ($modules === [])
                <p class="rounded-2xl bg-white p-6 text-sm text-gray-600 shadow-xs dark:bg-gray-800 dark:text-gray-300">✨ {{ __('All areas are included for your household – nothing to buy.') }}</p>
            @else
                <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('These areas are offered separately. Start a trial or ask the team for a license.') }}</p>
                <ul class="space-y-3">
                    @foreach ($modules as $module)
                        <li wire:key="module-{{ $module['key'] }}" class="rounded-2xl bg-white p-4 shadow-xs dark:bg-gray-800">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="font-medium text-gray-900 dark:text-gray-100">{{ $module['icon'] }} {{ $module['label'] }}</p>
                                    <p class="mt-0.5 text-sm">
                                        @if ($module['allowed'])
                                            <span class="text-emerald-700 dark:text-emerald-300">● {{ match ($module['source']) { 'trial' => __('Trial'), 'license' => __('License'), default => __('Released') } }}@if ($module['until']) {{ __('until :date', ['date' => $module['until']->format('d.m.Y')]) }}@endif</span>
                                        @elseif (str_starts_with($module['reason'], 'blocked'))
                                            <span class="text-red-600 dark:text-red-400">● {{ __('Locked by the administrator') }}</span>
                                        @else
                                            <span class="text-gray-500 dark:text-gray-400">● {{ __('Not active') }}</span>
                                        @endif
                                        @if ($module['price'] !== '')<span class="ms-2 text-gray-500 dark:text-gray-400">{{ $module['price'] }}</span>@endif
                                    </p>
                                </div>
                                @unless ($module['allowed'] || str_starts_with($module['reason'], 'blocked'))
                                    <div class="flex flex-wrap gap-2">
                                        @if ($module['can_trial'])
                                            <x-primary-button type="button" wire:click="startTrial('{{ $module['key'] }}')" wire:confirm="{{ __('Start a :days-day trial for the whole household?', ['days' => $module['trial_days']]) }}">🧪 {{ trans_choice('Try :count day|Try :count days', $module['trial_days']) }}</x-primary-button>
                                        @endif
                                        @if ($module['requested'])
                                            <span class="self-center text-sm text-gray-500 dark:text-gray-400">{{ __('Requested') }}</span>
                                        @else
                                            <x-secondary-button type="button" wire:click="openRequest('{{ $module['key'] }}')">{{ __('Request license') }}</x-secondary-button>
                                        @endif
                                    </div>
                                @endunless
                            </div>
                            @if ($requesting === $module['key'])
                                <form wire:submit="sendRequest" class="mt-3 space-y-2">
                                    <textarea wire:model="note" rows="2" class="block w-full rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" placeholder="{{ __('Note for the team (optional)') }}" aria-label="{{ __('Note') }}"></textarea>
                                    <div class="flex gap-2"><x-primary-button>{{ __('Send request') }}</x-primary-button><x-secondary-button type="button" wire:click="$set('requesting', null)">{{ __('Cancel') }}</x-secondary-button></div>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>
