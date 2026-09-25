<?php

use App\Support\WebPush;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public ?string $status = null;

    public function sendTest(WebPush $webPush): void
    {
        $reached = $webPush->send(Auth::user(), __('Test notification'), __('Push notifications work on this device.'), route('profile'));

        $this->status = $reached > 0
            ? trans_choice('Sent to :count device.|Sent to :count devices.', $reached)
            : __('No device could be reached. Turn notifications on again on this device.');
    }

    public function with(): array
    {
        return [
            'configured' => WebPush::isConfigured(),
            'publicKey' => WebPush::publicKey(),
            'deviceCount' => Auth::user()->pushSubscriptions()->count(),
        ];
    }
}; ?>

<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
            {{ __('Push notifications') }}
        </h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            {{ __('Get the morning reminders as a notification on your phone or computer – independent of the e-mail.') }}
        </p>
    </header>

    @if (! $configured)
        <p class="mt-4 rounded-md bg-amber-50 dark:bg-amber-900/30 p-3 text-sm text-amber-800 dark:text-amber-200">{{ __('Push notifications are not set up on this server (VAPID keys, see README).') }}</p>
    @else
        <div
            class="mt-4 space-y-3"
            x-data="pushSettings(@js($publicKey), @js(route('push.subscribe')))"
        >
            <p x-show="!supported" x-cloak class="text-sm text-gray-600 dark:text-gray-400">
                <span x-show="needsHomeScreen">{{ __('On iPhone and iPad: open the Share menu in Safari, choose “Add to Home Screen”, then open E11LifeOS from the home screen and turn notifications on there.') }}</span>
                <span x-show="!needsHomeScreen">{{ __('This browser does not support push notifications.') }}</span>
            </p>
            <p x-show="supported && permission === 'denied'" x-cloak class="text-sm text-red-700 dark:text-red-300">{{ __('Notifications are blocked for this site. Allow them in the browser or system settings.') }}</p>

            <p x-show="failed" x-cloak class="text-sm text-red-700 dark:text-red-300" role="alert">{{ __('Notifications could not be turned on. Please try again later or in another browser.') }}</p>

            <div x-show="supported" x-cloak class="flex flex-wrap items-center gap-3">
                <x-primary-button type="button" x-show="!subscribed" x-on:click="enable()" x-bind:disabled="busy">{{ __('Turn on for this device') }}</x-primary-button>
                <x-secondary-button type="button" x-show="subscribed" x-on:click="disable()" x-bind:disabled="busy">{{ __('Turn off for this device') }}</x-secondary-button>
                <span x-show="subscribed" class="text-sm text-green-700 dark:text-green-400">✓ {{ __('Active on this device') }}</span>
            </div>

            <div class="flex flex-wrap items-center gap-3 text-sm text-gray-600 dark:text-gray-400">
                <span>{{ trans_choice(':count device receives notifications.|:count devices receive notifications.', $deviceCount) }}</span>
                @if ($deviceCount > 0)
                    <button type="button" wire:click="sendTest" class="underline hover:text-gray-900 dark:hover:text-gray-200">{{ __('Send test notification') }}</button>
                @endif
            </div>
            @if ($status)
                <p class="text-sm text-gray-700 dark:text-gray-300" role="status">{{ $status }}</p>
            @endif
        </div>
    @endif
</section>
