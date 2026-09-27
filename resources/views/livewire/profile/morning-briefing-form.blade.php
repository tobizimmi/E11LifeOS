<?php

use App\Console\Commands\SendMorningBriefings;
use App\Support\MorningBriefing;
use App\Support\Weather;
use App\Support\WebPush;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public string $channel = 'off';

    public string $hour = '7';

    public string $place = '';

    public bool $showPreview = false;

    public function mount(): void
    {
        $user = Auth::user();
        $this->channel = (string) ($user->morning_briefing ?? 'off');
        $this->hour = (string) ($user->morning_briefing_hour ?? 7);
        $this->place = (string) $user->weather_place;
    }

    /**
     * Saves the settings; a changed place is looked up to get its coordinates for the weather.
     */
    public function save(): void
    {
        $data = $this->validate([
            'channel' => ['required', 'in:'.implode(',', MorningBriefing::CHANNELS)],
            'hour' => ['required', 'integer', 'between:0,23'],
            'place' => ['nullable', 'string', 'max:100'],
        ]);

        $user = Auth::user();
        $attributes = ['morning_briefing' => $data['channel'], 'morning_briefing_hour' => (int) $data['hour']];
        $place = trim((string) $data['place']);

        if ($place === '') {
            $attributes += ['weather_place' => null, 'weather_latitude' => null, 'weather_longitude' => null];
        } elseif ($place !== $user->weather_place) {
            $location = Weather::geocode($place);

            if (! $location) {
                $this->addError('place', __('This place could not be found.'));

                return;
            }

            $attributes += ['weather_place' => $location['name'], 'weather_latitude' => $location['latitude'], 'weather_longitude' => $location['longitude']];
            $this->place = $location['name'];
        }

        $user->update($attributes);
        $this->dispatch('briefing-saved');
    }

    public function sendNow(WebPush $webPush): void
    {
        SendMorningBriefings::deliver(Auth::user(), $webPush, $this->channel === 'off' ? 'email' : $this->channel);
        $this->dispatch('toast', message: __('Morning briefing sent.'));
    }

    public function with(): array
    {
        return [
            'preview' => $this->showPreview ? MorningBriefing::for(Auth::user()) : null,
        ];
    }
}; ?>

<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ __('Morning briefing') }}</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('Every morning: the weather, your tasks and dates for today, the meal plan and the shopping list.') }}</p>
    </header>

    <form wire:submit="save" class="mt-4 grid gap-4 sm:grid-cols-3">
        <div>
            <x-input-label for="briefing-channel" :value="__('Send')" />
            <select id="briefing-channel" wire:model="channel" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                <option value="off">{{ __('Off') }}</option>
                <option value="email">{{ __('By e-mail') }}</option>
                <option value="push">{{ __('As push notification') }}</option>
                <option value="both">{{ __('E-mail and push') }}</option>
            </select>
        </div>
        <div>
            <x-input-label for="briefing-hour" :value="__('Time')" />
            <select id="briefing-hour" wire:model="hour" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                @foreach (range(4, 12) as $h)
                    <option value="{{ $h }}">{{ sprintf('%02d:00', $h) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <x-input-label for="briefing-place" :value="__('Place for the weather')" />
            <x-text-input id="briefing-place" wire:model="place" type="text" class="mt-1 block w-full text-sm" :placeholder="__('e.g. Berlin')" />
            <x-input-error :messages="$errors->get('place')" class="mt-1" />
        </div>
        <div class="flex flex-wrap items-center gap-3 sm:col-span-3">
            <x-primary-button>{{ __('Save') }}</x-primary-button>
            <x-secondary-button type="button" wire:click="$toggle('showPreview')">{{ $showPreview ? __('Hide preview') : __('Preview') }}</x-secondary-button>
            <button type="button" wire:click="sendNow" class="text-sm text-indigo-600 underline dark:text-indigo-400">{{ __('Send now') }}</button>
            <x-action-message on="briefing-saved">{{ __('Saved.') }}</x-action-message>
        </div>
    </form>

    @if ($preview)
        <div class="mt-4 space-y-3 rounded-lg bg-gray-50 p-4 text-sm dark:bg-gray-900/50">
            <p class="font-medium text-gray-900 dark:text-gray-100">{{ $preview['date'] }}</p>
            @if ($preview['weather'])
                <p class="text-gray-700 dark:text-gray-300">{{ $preview['weather'] }}</p>
            @endif
            @forelse ($preview['sections'] as $section)
                <div wire:key="briefing-{{ $loop->index }}">
                    <a href="{{ $section['url'] }}" wire:navigate class="font-medium text-gray-800 hover:underline dark:text-gray-200">{{ $section['icon'] }} {{ $section['title'] }}</a>
                    <ul class="mt-1 space-y-0.5 text-gray-600 dark:text-gray-400">
                        @foreach ($section['lines'] as $line)
                            <li>• {{ $line }}</li>
                        @endforeach
                    </ul>
                </div>
            @empty
                <p class="text-gray-500 dark:text-gray-400">{{ __('Nothing due today.') }}</p>
            @endforelse
        </div>
    @endif
</section>
