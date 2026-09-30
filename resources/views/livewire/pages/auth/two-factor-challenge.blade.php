<?php

use App\Models\User;
use App\Support\TwoFactor;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use App\Support\Security\SecurityLog;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public string $code = '';

    public bool $useRecovery = false;

    public function mount(): void
    {
        if (! $this->pendingUser()) {
            $this->redirectRoute('login', navigate: true);
        }
    }

    private function pendingUser(): ?User
    {
        if (! session('login.id') || session('login.expires', 0) < now()->timestamp) {
            return null;
        }

        return User::find(session('login.id'));
    }

    public function verify(): void
    {
        $this->validate(['code' => ['required', 'string', 'max:30']]);
        $user = $this->pendingUser();

        if (! $user) {
            $this->redirectRoute('login', navigate: true);

            return;
        }

        $key = 'two-factor:'.$user->id.'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            SecurityLog::lockout($user);
            $this->addError('code', trans('auth.throttle', ['seconds' => RateLimiter::availableIn($key), 'minutes' => ceil(RateLimiter::availableIn($key) / 60)]));

            return;
        }

        if (! TwoFactor::verify($user, $this->code)) {
            RateLimiter::hit($key);
            SecurityLog::record($user, 'two_factor_failed');
            $this->addError('code', __('The code is not valid.'));

            return;
        }

        RateLimiter::clear($key);
        Auth::login($user, (bool) session('login.remember'));
        session()->forget(['login.id', 'login.remember', 'login.expires']);
        Session::regenerate();
        SecurityLog::login($user);

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
    }

    public function with(): array
    {
        $user = $this->pendingUser();

        return ['hasCode' => (bool) $user?->hasTwoFactor(), 'hasPasskeys' => (bool) $user?->passkeys()->exists()];
    }
}; ?>

<div>
    <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">🔐 {{ __('Two-factor authentication') }}</h1>
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
        @if (! $hasCode)
            {{ __('Confirm the sign-in with your passkey.') }}
        @elseif ($useRecovery)
            {{ __('Enter one of your recovery codes.') }}
        @else
            {{ $hasPasskeys ? __('Confirm with your passkey or enter the 6-digit code from your authenticator app.') : __('Enter the 6-digit code from your authenticator app.') }}
        @endif
    </p>

    @if ($hasPasskeys)
        <x-passkey-login class="mt-4 space-y-2" :label="__('Confirm with a passkey')" />
    @endif

    @if ($hasCode)
    <form wire:submit="verify" class="mt-4 space-y-4">
        <div>
            <x-input-label for="code" :value="$useRecovery ? __('Recovery code') : __('Code')" />
            <x-text-input wire:model="code" id="code" class="mt-1 block w-full text-lg tracking-widest" type="text" :inputmode="$useRecovery ? 'text' : 'numeric'" autocomplete="one-time-code" autofocus required />
            <x-input-error :messages="$errors->get('code')" class="mt-2" />
        </div>

        <div class="flex items-center justify-between">
            <button type="button" wire:click="$toggle('useRecovery')" class="text-sm text-gray-600 underline hover:text-gray-900 dark:text-gray-400">
                {{ $useRecovery ? __('Use the authenticator app') : __('Use a recovery code') }}
            </button>
            <x-primary-button>{{ __('Log in') }}</x-primary-button>
        </div>
    </form>
    @endif
</div>
