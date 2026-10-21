<?php

use App\Models\CalendarConnection;
use App\Support\Calendar\GoogleCalendar;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

/**
 * Connect a Google Calendar for two-way sync. Dormant until the Google OAuth credentials are
 * configured on the server; once connected, upcoming Google events appear in the LifeOS calendar.
 */
new class extends Component
{
    public function connection(): ?CalendarConnection
    {
        return CalendarConnection::where('user_id', Auth::id())->where('provider', 'google')->first();
    }

    public function syncNow(): void
    {
        if ($connection = $this->connection()) {
            GoogleCalendar::pull($connection);
            $this->dispatch('toast', message: __('Calendar synced.'));
        }
    }

    public function disconnect(): void
    {
        CalendarConnection::where('user_id', Auth::id())->where('provider', 'google')->delete();
        $this->dispatch('toast', message: __('Google Calendar disconnected.'));
    }

    public function with(): array
    {
        return ['configured' => GoogleCalendar::configured(), 'connection' => $this->connection()];
    }
}; ?>

<section class="max-w-xl space-y-4">
    <header>
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">🗓️ {{ __('Google Calendar') }}</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('Connect your Google Calendar so its events appear in LifeOS.') }}</p>
    </header>

    @if (! $configured)
        <p class="rounded-lg bg-gray-50 px-4 py-3 text-sm text-gray-600 dark:bg-gray-900/40 dark:text-gray-400">
            {{ __('Prepared, but not yet available: the Google access credentials still need to be set on the server. Once they are, the connect button appears here.') }}
        </p>
    @elseif ($connection)
        <div class="rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:bg-emerald-900/30 dark:text-emerald-100">
            {{ __('Connected') }}@if ($connection->google_email): {{ $connection->google_email }}@endif.
            @if ($connection->last_synced_at)<span class="block text-xs opacity-80">{{ __('Last synced :time', ['time' => $connection->last_synced_at->diffForHumans()]) }}</span>@endif
        </div>
        <div class="flex flex-wrap gap-2">
            <x-primary-button type="button" wire:click="syncNow" wire:loading.attr="disabled" wire:target="syncNow">{{ __('Sync now') }}</x-primary-button>
            <x-secondary-button type="button" wire:click="disconnect" wire:confirm="{{ __('Disconnect Google Calendar?') }}">{{ __('Disconnect') }}</x-secondary-button>
        </div>
    @else
        <a href="{{ route('google-calendar.connect') }}" class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
            {{ __('Connect with Google') }}
        </a>
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Writing LifeOS events back to Google follows in a later step.') }}</p>
    @endif
</section>
