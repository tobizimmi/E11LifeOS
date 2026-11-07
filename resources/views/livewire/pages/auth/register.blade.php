<?php

use App\Models\Household;
use App\Models\HouseholdInvite;
use App\Models\HouseholdMember;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public string $name = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';
    public string $invite_code = '';
    public string $household_name = '';

    /**
     * Invite links (/register?code=ABC123) fill in the code.
     */
    /** The invite code came prefilled from an invite link (/register?code=…), so hide that field. */
    public bool $codeFromLink = false;

    public function mount(): void
    {
        $code = request()->query('code');

        if (is_string($code) && preg_match('/^[A-Za-z0-9]{4,32}$/', $code)) {
            $this->invite_code = strtoupper($code);
            $this->codeFromLink = true;
        }
    }

    /**
     * Resolves the entered invite code so the form can hide the household-name field when the
     * invite is for an existing household (asking for it there only confuses people).
     *
     * @return array{needsHouseholdName: bool}
     */
    public function with(): array
    {
        $invite = trim($this->invite_code) !== ''
            ? HouseholdInvite::where('code', strtoupper(trim($this->invite_code)))->first()
            : null;

        // Only a "new household" invite (or a not-yet-recognised code) needs the household name.
        return ['needsHouseholdName' => ! $invite || $invite->isForNewHousehold()];
    }

    /**
     * Handle an incoming registration request.
     */
    public function register(): void
    {
        // Volt pages carry no route-level throttle middleware (the actual submit is a Livewire
        // update, not the GET /register route), so the limit lives here instead.
        $throttleKey = 'register:'.request()->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 10)) {
            event(new Lockout(request()));

            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'email' => trans('auth.throttle', ['seconds' => $seconds, 'minutes' => ceil($seconds / 60)]),
            ]);
        }

        RateLimiter::hit($throttleKey, 60);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
            'invite_code' => ['nullable', 'string'],
        ]);

        // An invite code is optional: without one, this is an open self-registration that creates a
        // new household waiting for an administrator to approve it.
        $invite = trim((string) $validated['invite_code']) !== ''
            ? HouseholdInvite::where('code', strtoupper(trim($validated['invite_code'])))->first()
            : null;

        if (trim((string) $validated['invite_code']) !== '' && (! $invite || ! $invite->isValid())) {
            $this->addError('invite_code', __('This invite code is invalid or has expired.'));

            return;
        }

        // A new household is needed both for a "new household" invite and for open self-registration.
        if (! $invite || $invite->isForNewHousehold()) {
            $this->validate(['household_name' => ['required', 'string', 'max:255']]);
        }

        $pendingApproval = false;

        $user = DB::transaction(function () use ($validated, $invite, &$pendingApproval) {
            // On a fresh installation the first account becomes the administrator.
            $isFirstAccount = ! User::query()->exists();

            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
            ]);

            if ($isFirstAccount) {
                $user->forceFill(['is_admin' => true])->save();
            }

            if (! $invite) {
                // Open self-registration: a new household that an administrator must approve first
                // (the very first account of an installation is trusted and approved at once).
                $household = Household::create([
                    'name' => $this->household_name,
                    'approved_at' => $isFirstAccount ? now() : null,
                ]);
                $role = 'owner';
                $pendingApproval = ! $isFirstAccount;
            } elseif ($invite->isForNewHousehold()) {
                $household = Household::create(['name' => $this->household_name, 'approved_at' => now()]);
                $role = 'owner';
            } else {
                $household = $invite->household;
                // The first person of a household an administrator created becomes its owner.
                $role = $household->members()->where('role', 'owner')->exists() ? 'member' : 'owner';
            }

            HouseholdMember::create([
                'household_id' => $household->id,
                'user_id' => $user->id,
                'role' => $role,
                'joined_at' => now(),
            ]);

            $invite?->update(['used_at' => now()]);

            return $user;
        });

        event(new Registered($user));

        // A household that still needs approval must not get a session yet.
        if ($pendingApproval) {
            session()->flash('status', __('Thanks! Your account was created and is waiting for an administrator to approve it. You will be able to sign in once it is approved.'));
            $this->redirect(route('login'), navigate: true);

            return;
        }

        Auth::login($user);

        $this->redirect(route('dashboard'), navigate: true);
    }
}; ?>

<div>
    <form wire:submit="register">
        <!-- Invite Code — optional; hidden (but still submitted) when it came from an invite link -->
        <div @class(['hidden' => $codeFromLink])>
            <x-input-label for="invite_code" :value="__('Invite code (optional)')" />
            <x-text-input wire:model.live.debounce.400ms="invite_code" id="invite_code" class="block mt-1 w-full uppercase" type="text" name="invite_code" autocomplete="off" />
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Leave empty to register a new household – an administrator approves it before you can sign in.') }}</p>
            <x-input-error :messages="$errors->get('invite_code')" class="mt-2" />
        </div>

        <!-- Household Name: only for "new household" invites; hidden when joining an existing household -->
        @if ($needsHouseholdName)
            <div class="mt-4">
                <x-input-label for="household_name" :value="__('Household name')" />
                <x-text-input wire:model="household_name" id="household_name" class="block mt-1 w-full" type="text" name="household_name" autocomplete="off" />
                <x-input-error :messages="$errors->get('household_name')" class="mt-2" />
            </div>
        @endif

        <!-- Name -->
        <div class="mt-4">
            <x-input-label for="name" :value="__('Name')" />
            <x-text-input wire:model="name" id="name" class="block mt-1 w-full" type="text" name="name" required autocomplete="name" />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <!-- Email Address -->
        <div class="mt-4">
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input wire:model="email" id="email" class="block mt-1 w-full" type="email" name="email" required autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" :value="__('Password')" />

            <x-text-input wire:model="password" id="password" class="block mt-1 w-full"
                            type="password"
                            name="password"
                            required autocomplete="new-password" />

            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Confirm Password -->
        <div class="mt-4">
            <x-input-label for="password_confirmation" :value="__('Confirm Password')" />

            <x-text-input wire:model="password_confirmation" id="password_confirmation" class="block mt-1 w-full"
                            type="password"
                            name="password_confirmation" required autocomplete="new-password" />

            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="flex items-center justify-end mt-4">
            <a class="underline text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 rounded-md focus:outline-hidden focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 dark:focus:ring-offset-gray-800" href="{{ route('login') }}" wire:navigate>
                {{ __('Already registered?') }}
            </a>

            <x-primary-button class="ms-4">
                {{ __('Register') }}
            </x-primary-button>
        </div>
    </form>
</div>
