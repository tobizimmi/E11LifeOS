<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Volt\Component;

new class extends Component
{
    /**
     * Creates the subscription link, or a new one (the old link stops working).
     */
    public function createLink(): void
    {
        Auth::user()->forceFill(['calendar_token' => Str::random(40)])->save();
    }

    public function disableLink(): void
    {
        Auth::user()->forceFill(['calendar_token' => null])->save();
    }

    public function with(): array
    {
        $token = Auth::user()->calendar_token;

        return [
            'url' => $token ? route('calendar.feed', ['token' => $token]) : null,
        ];
    }
}; ?>

<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
            {{ __('Calendar subscription') }}
        </h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            {{ __('Show your open tasks and dates with a reminder (e.g. cancellation deadlines) in the calendar on your phone or computer. The calendar updates itself.') }}
        </p>
    </header>

    @if ($url)
        <div class="mt-4 space-y-3" x-data="{ copied: false }">
            <div class="flex gap-2">
                <input type="text" readonly value="{{ $url }}" x-ref="url" x-on:focus="$event.target.select()" class="min-w-0 flex-1 rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 text-sm" aria-label="{{ __('Calendar link') }}" />
                <x-secondary-button type="button" x-on:click="navigator.clipboard.writeText($refs.url.value); copied = true; setTimeout(() => copied = false, 2000)">
                    <span x-show="! copied">{{ __('Copy') }}</span><span x-show="copied" x-cloak>✓</span>
                </x-secondary-button>
            </div>
            <a href="{{ preg_replace('#^https?://#', 'webcal://', $url) }}" class="inline-block text-sm text-indigo-600 dark:text-indigo-400 underline">📅 {{ __('Open in calendar app') }}</a>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('iPhone: Settings → Calendar → Accounts → Add Account → Other → Add Subscribed Calendar, paste the link. Google Calendar: “Other calendars” → “From URL”. Keep the link private – anyone who has it can see these dates.') }}</p>
            <div class="flex flex-wrap gap-3 text-sm">
                <button type="button" wire:click="createLink" wire:confirm="{{ __('Create a new link? The old one stops working.') }}" class="text-gray-600 dark:text-gray-400 underline">{{ __('New link') }}</button>
                <button type="button" wire:click="disableLink" class="text-red-600 dark:text-red-400 underline">{{ __('Switch off') }}</button>
            </div>
        </div>
    @else
        <div class="mt-4">
            <x-primary-button type="button" wire:click="createLink">{{ __('Create calendar link') }}</x-primary-button>
        </div>
    @endif
</section>
