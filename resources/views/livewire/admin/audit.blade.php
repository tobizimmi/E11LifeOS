<?php

use App\Models\SecurityEvent;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Admin › Log: every administrator action (households created, exported, deleted, members
 * changed) with who, when and from where – for accountability under the GDPR.
 */
new #[Layout('layouts.app')] class extends Component
{
    public function mount(): void
    {
        $this->authorize('admin');
    }

    public function with(): array
    {
        return [
            'events' => SecurityEvent::where('event', 'admin_action')->with('user:id,name')->latest('created_at')->limit(200)->get(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Administration') }}</h2>
        <x-admin-nav />
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-4xl space-y-4 px-4 sm:px-6 lg:px-8">
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Actions of administrators on households and members. Kept as long as the security log (Server settings › Security).') }}</p>
            <ul class="divide-y divide-gray-100 rounded-2xl bg-white text-sm shadow-xs dark:divide-gray-700 dark:bg-gray-800">
                @forelse ($events as $event)
                    <li class="flex flex-wrap items-baseline justify-between gap-2 px-4 py-2.5" wire:key="event-{{ $event->id }}">
                        <span class="text-gray-900 dark:text-gray-100">🛠 {{ $event->user?->name ?? __('Deleted account') }} · <span class="font-mono text-xs">{{ $event->details }}</span></span>
                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $event->created_at->format('d.m.Y H:i') }} · {{ $event->ip_address }} · {{ $event->device }}</span>
                    </li>
                @empty
                    <li class="px-4 py-3 text-gray-500 dark:text-gray-400">{{ __('No administrator actions yet.') }}</li>
                @endforelse
            </ul>
        </div>
    </div>
</div>
