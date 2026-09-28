<?php

use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Notification center: everything that was pushed or reminded, also without push or e-mail.
 */
new #[Layout('layouts.app')] class extends Component
{
    public bool $unreadOnly = false;

    private function find(string $id): DatabaseNotification
    {
        return Auth::user()->notifications()->findOrFail($id);
    }

    /**
     * Marks the entry as read and opens its target.
     */
    public function open(string $id): void
    {
        $notification = $this->find($id);
        $notification->markAsRead();
        $this->dispatch('notifications-read');

        $url = $notification->data['url'] ?? null;

        if (is_string($url) && str_starts_with($url, url('/'))) {
            $this->redirect($url, navigate: true);
        }
    }

    public function toggleRead(string $id): void
    {
        $notification = $this->find($id);
        $notification->read() ? $notification->markAsUnread() : $notification->markAsRead();
        $this->dispatch('notifications-read');
    }

    public function markAllRead(): void
    {
        Auth::user()->unreadNotifications()->update(['read_at' => now()]);
        $this->dispatch('notifications-read');
    }

    public function clearRead(): void
    {
        Auth::user()->readNotifications()->delete();
    }

    public function with(): array
    {
        $notifications = Auth::user()->notifications()
            ->when($this->unreadOnly, fn ($query) => $query->whereNull('read_at'))
            ->limit(100)->get();

        return [
            'groups' => $notifications->groupBy(fn (DatabaseNotification $notification) => match (true) {
                $notification->created_at->isToday() => __('Today'),
                $notification->created_at->isYesterday() => __('Yesterday'),
                $notification->created_at->gt(now()->subWeek()) => __('This week'),
                default => __('Earlier'),
            }),
            'unreadCount' => Auth::user()->unreadNotifications()->count(),
            'hasRead' => Auth::user()->readNotifications()->exists(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">🔔 {{ __('Notifications') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-3xl space-y-4 px-4 sm:px-6 lg:px-8">
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <div class="me-auto inline-flex rounded-lg bg-white p-0.5 ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700" role="group">
                    <button type="button" wire:click="$set('unreadOnly', false)" aria-pressed="{{ $unreadOnly ? 'false' : 'true' }}" @class(['rounded-md px-3 py-1', 'bg-indigo-600 text-white' => ! $unreadOnly, 'text-gray-600 dark:text-gray-300' => $unreadOnly])>{{ __('All') }}</button>
                    <button type="button" wire:click="$set('unreadOnly', true)" aria-pressed="{{ $unreadOnly ? 'true' : 'false' }}" @class(['rounded-md px-3 py-1', 'bg-indigo-600 text-white' => $unreadOnly, 'text-gray-600 dark:text-gray-300' => ! $unreadOnly])>{{ __('Unread') }} @if ($unreadCount)({{ $unreadCount }})@endif</button>
                </div>
                @if ($unreadCount > 0)
                    <x-secondary-button type="button" wire:click="markAllRead">✓ {{ __('Mark all as read') }}</x-secondary-button>
                @endif
                @if ($hasRead)
                    <button type="button" wire:click="clearRead" wire:confirm="{{ __('Delete all read notifications?') }}" class="px-2 text-gray-500 underline hover:text-gray-700 dark:text-gray-400">{{ __('Delete read ones') }}</button>
                @endif
            </div>

            @forelse ($groups as $label => $notifications)
                <section wire:key="notification-group-{{ $loop->index }}">
                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $label }}</h3>
                    <ul class="divide-y divide-gray-100 overflow-hidden rounded-2xl bg-white shadow-sm dark:divide-gray-700 dark:bg-gray-800">
                        @foreach ($notifications as $notification)
                            <li wire:key="notification-{{ $notification->id }}" @class(['flex items-start gap-3 px-4 py-3', 'bg-indigo-50/60 dark:bg-indigo-900/20' => $notification->unread()])>
                                <span @class(['mt-2 h-2 w-2 shrink-0 rounded-full', 'bg-indigo-500' => $notification->unread(), 'bg-transparent' => $notification->read()]) aria-hidden="true"></span>
                                <button type="button" wire:click="open('{{ $notification->id }}')" class="min-w-0 flex-1 text-start">
                                    <span @class(['block text-sm text-gray-900 dark:text-gray-100', 'font-semibold' => $notification->unread()])>{{ $notification->data['title'] ?? '' }}</span>
                                    @if (filled($notification->data['body'] ?? null))
                                        <span class="mt-0.5 block whitespace-pre-line text-sm text-gray-600 dark:text-gray-300">{{ \Illuminate\Support\Str::limit($notification->data['body'], 300) }}</span>
                                    @endif
                                    <span class="mt-1 block text-xs text-gray-400" title="{{ $notification->created_at->format('d.m.Y H:i') }}">{{ $notification->created_at->diffForHumans() }}</span>
                                </button>
                                <button type="button" wire:click="toggleRead('{{ $notification->id }}')" class="shrink-0 rounded-md px-2 py-1 text-xs text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700" title="{{ $notification->unread() ? __('Mark as read') : __('Mark as unread') }}" aria-label="{{ $notification->unread() ? __('Mark as read') : __('Mark as unread') }}">{{ $notification->unread() ? '✓' : '↺' }}</button>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @empty
                <div class="rounded-2xl bg-white p-8 text-center shadow-sm dark:bg-gray-800">
                    <p class="text-3xl" aria-hidden="true">🔕</p>
                    <p class="mt-2 font-medium text-gray-900 dark:text-gray-100">{{ $unreadOnly ? __('Nothing unread.') : __('No notifications yet.') }}</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Reminders, new tasks for you, budget warnings and price alerts appear here – also without push or e-mail.') }}</p>
                </div>
            @endforelse
        </div>
    </div>
</div>
