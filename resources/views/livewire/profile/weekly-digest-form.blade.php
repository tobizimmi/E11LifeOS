<?php

use App\Console\Commands\SendWeeklyDigests;
use App\Support\WebPush;
use App\Support\WeeklyDigest;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public string $channel = 'off';

    public string $day = '7';

    public string $hour = '18';

    public bool $showPreview = false;

    public function mount(): void
    {
        $user = Auth::user();
        $this->channel = (string) $user->weekly_digest;
        $this->day = (string) $user->weekly_digest_day;
        $this->hour = (string) $user->weekly_digest_hour;
    }

    public function save(): void
    {
        $data = $this->validate([
            'channel' => ['required', 'in:'.implode(',', WeeklyDigest::CHANNELS)],
            'day' => ['required', 'integer', 'between:1,7'],
            'hour' => ['required', 'integer', 'between:0,23'],
        ]);

        Auth::user()->update(['weekly_digest' => $data['channel'], 'weekly_digest_day' => (int) $data['day'], 'weekly_digest_hour' => (int) $data['hour']]);
        $this->dispatch('digest-saved');
    }

    /**
     * Sends the summary right now (e-mail and/or push, or e-mail when switched off).
     */
    public function sendNow(WebPush $webPush): void
    {
        SendWeeklyDigests::deliver(Auth::user(), $webPush, $this->channel === 'off' ? 'email' : $this->channel);
        $this->dispatch('toast', message: __('Weekly summary sent.'));
    }

    public function with(): array
    {
        return [
            'preview' => $this->showPreview ? WeeklyDigest::for(Auth::user()) : null,
        ];
    }
}; ?>

<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ __('Weekly summary') }}</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('Once a week an overview of your tasks, deadlines, meal plan, shopping list and – if you use them – portfolio, finances and open invoices.') }}</p>
    </header>

    <form wire:submit="save" class="mt-4 grid gap-4 sm:grid-cols-3">
        <div>
            <x-input-label for="digest-channel" :value="__('Send')" />
            <select id="digest-channel" wire:model="channel" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                <option value="off">{{ __('Off') }}</option>
                <option value="email">{{ __('By e-mail') }}</option>
                <option value="push">{{ __('As push notification') }}</option>
                <option value="both">{{ __('E-mail and push') }}</option>
            </select>
        </div>
        <div>
            <x-input-label for="digest-day" :value="__('Day')" />
            <select id="digest-day" wire:model="day" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                @foreach (range(1, 7) as $weekday)
                    <option value="{{ $weekday }}">{{ now()->startOfWeek()->addDays($weekday - 1)->translatedFormat('l') }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <x-input-label for="digest-hour" :value="__('Time')" />
            <select id="digest-hour" wire:model="hour" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                @foreach (range(0, 23) as $h)
                    <option value="{{ $h }}">{{ sprintf('%02d:00', $h) }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex flex-wrap items-center gap-3 sm:col-span-3">
            <x-primary-button>{{ __('Save') }}</x-primary-button>
            <x-secondary-button type="button" wire:click="$toggle('showPreview')">{{ $showPreview ? __('Hide preview') : __('Preview') }}</x-secondary-button>
            <button type="button" wire:click="sendNow" class="text-sm text-indigo-600 underline dark:text-indigo-400">{{ __('Send now') }}</button>
            <x-action-message on="digest-saved">{{ __('Saved.') }}</x-action-message>
        </div>
    </form>

    @if ($preview)
        <div class="mt-4 space-y-3 rounded-lg bg-gray-50 p-4 text-sm dark:bg-gray-900/50">
            <p class="font-medium text-gray-900 dark:text-gray-100">{{ $preview['week'] }}</p>
            @forelse ($preview['sections'] as $section)
                <div wire:key="digest-{{ $loop->index }}">
                    <a href="{{ $section['url'] }}" wire:navigate class="font-medium text-gray-800 hover:underline dark:text-gray-200">{{ $section['icon'] }} {{ $section['title'] }}</a>
                    <ul class="mt-1 space-y-0.5 text-gray-600 dark:text-gray-400">
                        @foreach ($section['lines'] as $line)
                            <li>• {{ $line }}</li>
                        @endforeach
                    </ul>
                </div>
            @empty
                <p class="text-gray-500 dark:text-gray-400">{{ __('A quiet week – nothing due.') }}</p>
            @endforelse
        </div>
    @endif
</section>
