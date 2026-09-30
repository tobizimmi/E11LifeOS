<?php

use App\Support\CalendarFeed;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Volt\Component;

new class extends Component
{
    /** @var list<string> */
    public array $sections = [];

    public bool $details = true;

    public function mount(): void
    {
        $options = CalendarFeed::options(Auth::user());
        $this->sections = $options['sections'];
        $this->details = $options['details'];
    }

    /**
     * Stores which areas the subscription contains and whether descriptions and links are included.
     */
    public function saveOptions(): void
    {
        $this->validate(['sections' => ['array'], 'sections.*' => ['in:'.implode(',', CalendarFeed::SECTIONS)], 'details' => ['boolean']]);
        Auth::user()->update(['calendar_feed_options' => ['sections' => array_values(array_unique($this->sections)), 'details' => $this->details]]);
        $this->dispatch('toast', message: __('Saved.'));
    }

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
            'sectionLabels' => [
                'tasks' => '✅ '.__('Tasks'),
                'dates' => '📌 '.__('Deadlines & dates'),
                'life' => '🗂️ '.__('Trips, birthdays, vehicles, home & documents'),
                'health' => '🩺 '.__('Health appointments & check-ups'),
                'real_estate' => '🏠 '.__('Real estate'),
                'portfolio' => '📈 '.__('Portfolio (savings plans, dividends)'),
            ],
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
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('iPhone: Settings → Calendar → Accounts → Add Account → Other → Add Subscribed Calendar, paste the link. Google Calendar: “Other calendars” → “From URL”. Proton Calendar: “Other calendars” → “Add calendar from URL”. Keep the link private – anyone who has it can see these dates.') }}</p>
            <form wire:submit="saveOptions" class="rounded-lg bg-gray-50 p-3 dark:bg-gray-900/40">
                <p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ __('What the subscription contains') }}</p>
                <div class="mt-2 grid gap-1 sm:grid-cols-2">
                    @foreach ($sectionLabels as $key => $label)
                        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300"><input type="checkbox" value="{{ $key }}" wire:model="sections" class="rounded border-gray-300 text-indigo-600"> {{ $label }}</label>
                    @endforeach
                </div>
                <label class="mt-2 flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300"><input type="checkbox" wire:model="details" class="rounded border-gray-300 text-indigo-600"> {{ __('Include notes, amounts and links (off: titles and dates only)') }}</label>
                <x-secondary-button type="submit" class="mt-2">{{ __('Save') }}</x-secondary-button>
            </form>
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
