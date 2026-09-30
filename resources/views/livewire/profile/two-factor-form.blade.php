<?php

use App\Support\QrCode;
use App\Support\TwoFactor;
use Illuminate\Support\Facades\Auth;
use App\Support\Security\SecurityLog;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Component;

new class extends Component
{
    /**
     * Secret shown while setting up (not active until a code confirms it).
     */
    public ?string $pendingSecret = null;

    public string $code = '';

    public string $password = '';

    /**
     * Recovery codes, shown once after enabling or regenerating.
     *
     * @var list<string>
     */
    public array $shownRecoveryCodes = [];

    public function start(): void
    {
        $this->validatePassword();
        $this->pendingSecret = TwoFactor::generateSecret();
        $this->reset('code', 'shownRecoveryCodes');
    }

    public function confirm(): void
    {
        $this->validate(['code' => ['required', 'string']]);
        abort_unless($this->pendingSecret, 422);

        if (TwoFactor::matchingStep($this->pendingSecret, $this->code) === null) {
            $this->addError('code', __('The code is not valid.'));

            return;
        }

        $codes = TwoFactor::recoveryCodes();
        Auth::user()->forceFill([
            'two_factor_secret' => $this->pendingSecret,
            'two_factor_recovery_codes' => $codes,
            'two_factor_confirmed_at' => now(),
            'two_factor_last_step' => null,
        ])->save();

        $this->shownRecoveryCodes = $codes;
        $this->reset('pendingSecret', 'code', 'password');
        SecurityLog::record(Auth::user(), 'two_factor_enabled');
    }

    public function regenerateCodes(): void
    {
        $this->validatePassword();
        $codes = TwoFactor::recoveryCodes();
        Auth::user()->forceFill(['two_factor_recovery_codes' => $codes])->save();
        $this->shownRecoveryCodes = $codes;
        $this->reset('password');
    }

    public function disable(): void
    {
        $this->validatePassword();
        Auth::user()->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null, 'two_factor_last_step' => null])->save();
        SecurityLog::record(Auth::user(), 'two_factor_disabled');
        SecurityLog::alert(Auth::user(), __('Two-factor authentication switched off'), [__('Two-factor authentication was switched off for your account.')]);
        $this->reset('pendingSecret', 'code', 'password', 'shownRecoveryCodes');
    }

    private function validatePassword(): void
    {
        $this->validate(['password' => ['required', 'string']]);

        if (! Hash::check($this->password, Auth::user()->password)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['password' => __('The password is incorrect.')]);
        }
    }

    public function with(): array
    {
        $user = Auth::user();

        return [
            'enabled' => $user->hasTwoFactor(),
            'remaining' => count((array) $user->two_factor_recovery_codes),
            'qr' => $this->pendingSecret ? QrCode::svg(TwoFactor::otpauthUrl($user, $this->pendingSecret), 200) : null,
            'otpauth' => $this->pendingSecret ? TwoFactor::otpauthUrl($user, $this->pendingSecret) : null,
        ];
    }
}; ?>

<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">🔐 {{ __('Two-factor authentication') }}</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('Protects your finances, documents and tax data: besides the password, signing in needs a code from an authenticator app (e.g. Google Authenticator, Microsoft Authenticator, 1Password, Aegis).') }}</p>
    </header>

    <p @class(['mt-3 inline-flex items-center gap-2 rounded-full px-3 py-1 text-sm', 'bg-green-50 text-green-800 dark:bg-green-900/30 dark:text-green-200' => $enabled, 'bg-amber-50 text-amber-900 dark:bg-amber-900/30 dark:text-amber-100' => ! $enabled])>
        {{ $enabled ? '✓ '.__('Active') : '⚠ '.__('Not active') }}
        @if ($enabled)
            · {{ trans_choice(':count recovery code left|:count recovery codes left', $remaining) }}
        @endif
    </p>

    @if ($shownRecoveryCodes !== [])
        <div class="mt-4 rounded-lg bg-gray-50 p-4 dark:bg-gray-900/50">
            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ __('Your recovery codes') }}</p>
            <p class="text-xs text-gray-600 dark:text-gray-400">{{ __('Store them safely (e.g. in your password manager). Each code works once if you lose your phone. They are only shown now.') }}</p>
            <ul class="mt-2 grid grid-cols-2 gap-1 font-mono text-sm text-gray-900 dark:text-gray-100">
                @foreach ($shownRecoveryCodes as $recoveryCode)
                    <li>{{ $recoveryCode }}</li>
                @endforeach
            </ul>
            <x-secondary-button type="button" class="mt-2" x-data x-on:click="navigator.clipboard.writeText({{ \Illuminate\Support\Js::from(implode(PHP_EOL, $shownRecoveryCodes)) }})">📋 {{ __('Copy') }}</x-secondary-button>
        </div>
    @endif

    @if ($qr)
        <div class="mt-4 space-y-3 rounded-lg bg-gray-50 p-4 dark:bg-gray-900/50">
            <p class="text-sm text-gray-700 dark:text-gray-300">{{ __('1. Scan the code with your authenticator app (on the phone: tap the link).') }}</p>
            <div class="flex flex-wrap items-center gap-4">
                <div class="rounded bg-white p-1">{!! $qr !!}</div>
                <div class="space-y-1 text-sm">
                    <a href="{{ $otpauth }}" class="text-indigo-600 underline dark:text-indigo-400">{{ __('Open in authenticator app') }}</a>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Or enter the key manually:') }}</p>
                    <p class="break-all font-mono text-sm text-gray-900 dark:text-gray-100">{{ trim(chunk_split($pendingSecret, 4, ' ')) }}</p>
                </div>
            </div>
            <form wire:submit="confirm" class="space-y-2">
                <x-input-label for="two-factor-code" :value="__('2. Enter the 6-digit code shown in the app')" />
                <div class="flex gap-2">
                    <x-text-input id="two-factor-code" wire:model="code" inputmode="numeric" autocomplete="one-time-code" class="block w-40 tracking-widest" />
                    <x-primary-button>{{ __('Activate') }}</x-primary-button>
                    <x-secondary-button type="button" wire:click="$set('pendingSecret', null)">{{ __('Cancel') }}</x-secondary-button>
                </div>
                <x-input-error :messages="$errors->get('code')" />
            </form>
        </div>
    @else
        <form wire:submit="{{ $enabled ? 'regenerateCodes' : 'start' }}" class="mt-4 space-y-3">
            <div>
                <x-input-label for="two-factor-password" :value="__('Current password')" />
                <x-text-input id="two-factor-password" wire:model="password" type="password" autocomplete="current-password" class="mt-1 block w-full sm:w-72" />
                <x-input-error :messages="$errors->get('password')" class="mt-1" />
            </div>
            <div class="flex flex-wrap items-center gap-3">
                @if ($enabled)
                    <x-secondary-button type="submit">{{ __('New recovery codes') }}</x-secondary-button>
                    <x-danger-button type="button" wire:click="disable" wire:confirm="{{ __('Turn off two-factor authentication?') }}">{{ __('Turn off') }}</x-danger-button>
                @else
                    <x-primary-button>{{ __('Set up') }}</x-primary-button>
                @endif
            </div>
        </form>
    @endif
</section>
