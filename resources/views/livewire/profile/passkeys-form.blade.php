<?php

use App\Support\Security\Passkeys\RegisterOptions;
use App\Support\Security\SecurityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;
use Spatie\LaravelPasskeys\Actions\StorePasskeyAction;

new class extends Component
{
    public string $name = '';

    public string $password = '';

    /**
     * Checks name and password and returns the registration options for the browser (JSON).
     */
    public function prepare(RegisterOptions $options): string
    {
        $this->validate(['name' => ['required', 'string', 'max:60'], 'password' => ['required', 'string']], attributes: ['name' => __('Name of the passkey')]);

        if (! Hash::check($this->password, Auth::user()->password)) {
            SecurityLog::record(Auth::user(), 'login_failed', details: 'passkey');

            throw ValidationException::withMessages(['password' => __('The password is incorrect.')]);
        }

        $json = $options->execute(Auth::user());
        session()->put('passkey-registration-options', $json);

        return $json;
    }

    /**
     * Stores the passkey the browser created (checked against the options from prepare()).
     */
    public function store(string $credential, StorePasskeyAction $storePasskey): void
    {
        $options = session()->pull('passkey-registration-options');

        try {
            abort_unless($options, 422);
            $storePasskey->execute(Auth::user(), $credential, $options, request()->getHost(), ['name' => $this->name]);
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('name', __('The passkey could not be saved. Please try again.'));

            return;
        }

        SecurityLog::record(Auth::user(), 'passkey_added', details: $this->name);
        SecurityLog::alert(Auth::user(), __('A passkey was added to your account'), [__('Name: :name', ['name' => $this->name]), __('If this was not you, remove the passkey and change your password.')]);
        $this->reset('name', 'password');
        $this->dispatch('toast', message: __('Passkey saved. You can now sign in with it.'));
    }

    public function remove(int $passkeyId): void
    {
        $passkey = Auth::user()->passkeys()->findOrFail($passkeyId);
        $passkey->delete();

        SecurityLog::record(Auth::user(), 'passkey_removed', details: $passkey->name);
        SecurityLog::alert(Auth::user(), __('A passkey was removed from your account'), [__('Name: :name', ['name' => $passkey->name])]);
    }

    public function with(): array
    {
        return ['passkeys' => Auth::user()->passkeys()->latest()->get()];
    }
}; ?>

<section x-data="passkeyRegister" id="passkeys" data-cancelled="{{ __('Cancelled – no passkey was created.') }}" data-failed="{{ __('The passkey could not be created. Please try again.') }}">
    <header>
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">🔑 {{ __('Passkeys') }}</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            {{ __('Sign in with Face ID, fingerprint, your device PIN or a security key such as a YubiKey – without a password. Passkeys cannot be phished: they only work on this site. Once you have a passkey, signing in with the password also asks for it as the second step.') }}
        </p>
    </header>

    @if ($passkeys->isNotEmpty())
        <ul class="mt-4 divide-y divide-gray-100 dark:divide-gray-700">
            @foreach ($passkeys as $passkey)
                <li wire:key="passkey-{{ $passkey->id }}" class="flex items-center justify-between gap-3 py-2 text-sm">
                    <span class="min-w-0">
                        <span class="block truncate font-medium text-gray-900 dark:text-gray-100">🔑 {{ $passkey->name }}</span>
                        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ __('Added :date', ['date' => $passkey->created_at->format('d.m.Y')]) }} · {{ $passkey->last_used_at ? __('last used :time', ['time' => $passkey->last_used_at->diffForHumans()]) : __('not used yet') }}</span>
                    </span>
                    <button type="button" wire:click="remove({{ $passkey->id }})" wire:confirm="{{ __('Remove this passkey? It can no longer be used to sign in.') }}" class="shrink-0 text-red-600 underline dark:text-red-400">{{ __('Remove') }}</button>
                </li>
            @endforeach
        </ul>
    @endif

    <p x-show="! supported" x-cloak class="mt-4 rounded-md bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">{{ __('This browser does not support passkeys.') }}</p>

    <form x-show="supported" x-on:submit.prevent="register" class="mt-4 grid gap-3 sm:grid-cols-2">
        <div>
            <x-input-label for="passkey-name" :value="__('Name of the passkey')" />
            <x-text-input id="passkey-name" wire:model="name" class="mt-1 block w-full" placeholder="{{ __('e.g. iPhone, MacBook, YubiKey') }}" maxlength="60" />
            <x-input-error :messages="$errors->get('name')" class="mt-1" />
        </div>
        <div>
            <x-input-label for="passkey-password" :value="__('Your current password')" />
            <x-text-input id="passkey-password" type="password" wire:model="password" class="mt-1 block w-full" autocomplete="current-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-1" />
        </div>
        <div class="flex flex-wrap items-center gap-3 sm:col-span-2">
            <x-primary-button x-bind:disabled="busy">{{ __('Add passkey') }}</x-primary-button>
            <span x-show="error" x-text="error" class="text-sm text-red-600 dark:text-red-400"></span>
        </div>
    </form>
</section>
