<?php

use App\Models\PushSubscription;
use App\Support\Pushover;
use App\Support\WebPush;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public ?string $status = null;

    /** Hour the quiet time starts ("" = no quiet hours). */
    public string $quietFrom = '';

    public string $quietUntil = '';

    public bool $bundle = true;

    /** Every notification additionally via Pushover. */
    public bool $pushoverAll = false;

    public function mount(): void
    {
        $user = Auth::user();
        $this->quietFrom = $user->push_quiet_from === null ? '' : (string) $user->push_quiet_from;
        $this->quietUntil = $user->push_quiet_until === null ? '' : (string) $user->push_quiet_until;
        $this->bundle = (bool) $user->push_bundle;
        $this->pushoverAll = (bool) $user->pushover_all;
    }

    /**
     * Quiet hours (pushes wait and come bundled afterwards) and whether several pushes become one.
     */
    public function saveQuietHours(): void
    {
        $data = $this->validate([
            'quietFrom' => ['nullable', 'integer', 'between:0,23'],
            'quietUntil' => ['nullable', 'integer', 'between:0,23', 'required_with:quietFrom'],
            'bundle' => ['boolean'],
        ]);
        $off = $data['quietFrom'] === '' || $data['quietFrom'] === null;

        Auth::user()->forceFill([
            'push_quiet_from' => $off ? null : (int) $data['quietFrom'],
            'push_quiet_until' => $off ? null : (int) $data['quietUntil'],
            'push_bundle' => (bool) $data['bundle'],
        ])->save();

        $this->status = __('Saved.');
    }

    public function savePushover(): void
    {
        $this->validate(['pushoverAll' => ['boolean']]);

        if ($this->pushoverAll && ! Pushover::isConfigured(Auth::user())) {
            $this->pushoverAll = false;
            $this->addError('pushoverAll', __('Save a Pushover user key in the portfolio settings first.'));

            return;
        }

        Auth::user()->forceFill(['pushover_all' => $this->pushoverAll])->save();
        $this->status = __('Saved.');
    }

    /**
     * Removes a device from the push list (e.g. an old phone).
     */
    public function removeDevice(int $subscriptionId): void
    {
        Auth::user()->pushSubscriptions()->whereKey($subscriptionId)->delete();
        $this->status = __('Device removed.');
    }

    public function sendTest(WebPush $webPush): void
    {
        // Urgent: comes through the quiet hours and with high priority, like the reminders.
        $reached = $webPush->send(Auth::user(), __('Test notification'), __('Push notifications work on this device.'), route('profile'), urgent: true, pushover: false);

        $this->status = $reached > 0
            ? trans_choice('Sent to :count device.|Sent to :count devices.', $reached)
            : __('No device could be reached. Turn notifications on again on this device.');
    }

    public function with(): array
    {
        return [
            'configured' => WebPush::isConfigured(),
            'publicKey' => WebPush::publicKey(),
            'devices' => Auth::user()->pushSubscriptions()->latest('updated_at')->get(),
            'pushoverReady' => Pushover::isConfigured(Auth::user()),
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
                <span>{{ trans_choice(':count device receives notifications.|:count devices receive notifications.', $devices->count()) }}</span>
                @if ($devices->isNotEmpty())
                    <button type="button" wire:click="sendTest" class="underline hover:text-gray-900 dark:hover:text-gray-200">{{ __('Send test notification') }}</button>
                @endif
            </div>
            @if ($devices->isNotEmpty())
                {{-- Diagnosis: which push service each device uses and when a push last arrived there. --}}
                <ul class="divide-y divide-gray-100 rounded-md border border-gray-100 text-sm dark:divide-gray-700 dark:border-gray-700">
                    @foreach ($devices as $device)
                        <li class="flex items-start justify-between gap-3 p-2.5" wire:key="push-device-{{ $device->id }}">
                            <div class="min-w-0">
                                <p class="font-medium text-gray-900 dark:text-gray-100">{{ $device->deviceName() }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ __('via :service', ['service' => $device->service()]) }}
                                    · {{ $device->canEncrypt() ? '🔒 '.__('text encrypted in the push') : __('text is fetched from the app') }}
                                </p>
                                <p class="text-xs {{ $device->last_error ? 'text-red-700 dark:text-red-300' : 'text-gray-500 dark:text-gray-400' }}">
                                    @if ($device->last_success_at)
                                        ✓ {{ __('Last delivered :time', ['time' => $device->last_success_at->diffForHumans()]) }}
                                    @else
                                        {{ __('Nothing delivered yet') }}
                                    @endif
                                    @if ($device->last_error)
                                        · {{ __('Last error: :error', ['error' => $device->last_error]) }}
                                    @endif
                                </p>
                            </div>
                            <button type="button" wire:click="removeDevice({{ $device->id }})" wire:confirm="{{ __('Remove this device?') }}" class="shrink-0 text-xs text-red-600 underline dark:text-red-400">{{ __('Remove') }}</button>
                        </li>
                    @endforeach
                </ul>
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Devices the push service no longer knows are removed automatically. If a device is missing, turn notifications on there again.') }}</p>
            @endif
            <form wire:submit="savePushover" class="space-y-2 border-t border-gray-100 pt-3 dark:border-gray-700">
                <p class="text-sm font-medium text-gray-900 dark:text-gray-100">📲 {{ __('Pushover as second channel') }}</p>
                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300"><input type="checkbox" wire:model="pushoverAll" class="rounded-sm border-gray-300 text-indigo-600" @disabled(! $pushoverReady && ! $pushoverAll)> {{ __('Send all notifications additionally via Pushover') }}</label>
                <x-input-error :messages="$errors->get('pushoverAll')" />
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    @if ($pushoverReady)
                        {{ __('Uses the Pushover key from the portfolio settings. Reminders come with high priority; during the quiet hours only urgent ones.') }}
                    @else
                        {{ __('Save a Pushover user key in the portfolio settings first.') }}
                        <a href="{{ route('portfolio.settings') }}" wire:navigate class="underline">{{ __('Portfolio settings') }}</a>
                    @endif
                </p>
                <x-secondary-button type="submit">{{ __('Save') }}</x-secondary-button>
            </form>
            <form wire:submit="saveQuietHours" class="space-y-2 border-t border-gray-100 pt-3 dark:border-gray-700">
                <p class="text-sm font-medium text-gray-900 dark:text-gray-100">🌙 {{ __('Quiet hours') }}</p>
                <div class="flex flex-wrap items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    {{ __('from') }}
                    <select wire:model="quietFrom" class="rounded-md border-gray-300 py-1 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" aria-label="{{ __('Quiet hours from') }}">
                        <option value="">{{ __('off') }}</option>
                        @for ($hour = 0; $hour < 24; $hour++)
                            <option value="{{ $hour }}">{{ sprintf('%02d:00', $hour) }}</option>
                        @endfor
                    </select>
                    {{ __('to') }}
                    <select wire:model="quietUntil" class="rounded-md border-gray-300 py-1 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" aria-label="{{ __('Quiet hours until') }}">
                        <option value="">–</option>
                        @for ($hour = 0; $hour < 24; $hour++)
                            <option value="{{ $hour }}">{{ sprintf('%02d:00', $hour) }}</option>
                        @endfor
                    </select>
                </div>
                <x-input-error :messages="$errors->get('quietUntil')" />
                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300"><input type="checkbox" wire:model="bundle" class="rounded-sm border-gray-300 text-indigo-600"> {{ __('Bundle several notifications into one') }}</label>
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('During the quiet hours nothing buzzes; what arrives meanwhile comes as one notification afterwards. Medication and reminders at a chosen time still come right away.') }}</p>
                <x-secondary-button type="submit">{{ __('Save') }}</x-secondary-button>
            </form>
            @if ($status)
                <p class="text-sm text-gray-700 dark:text-gray-300" role="status">{{ $status }}</p>
            @endif
        </div>
    @endif
</section>
