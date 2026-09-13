<?php

use App\Models\Household;
use App\Models\HouseholdInvite;
use App\Models\HouseholdMember;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
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
     * Handle an incoming registration request.
     */
    public function register(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
            'invite_code' => ['required', 'string'],
        ]);

        $invite = HouseholdInvite::where('code', strtoupper(trim($validated['invite_code'])))->first();

        if (! $invite || ! $invite->isValid()) {
            $this->addError('invite_code', __('This invite code is invalid or has expired.'));

            return;
        }

        if ($invite->isForNewHousehold()) {
            $this->validate([
                'household_name' => ['required', 'string', 'max:255'],
            ]);
        }

        $user = DB::transaction(function () use ($validated, $invite) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
            ]);

            if ($invite->isForNewHousehold()) {
                $household = Household::create(['name' => $this->household_name]);
                $role = 'owner';
            } else {
                $household = $invite->household;
                $role = 'member';
            }

            HouseholdMember::create([
                'household_id' => $household->id,
                'user_id' => $user->id,
                'role' => $role,
                'joined_at' => now(),
            ]);

            $invite->update(['used_at' => now()]);

            return $user;
        });

        event(new Registered($user));

        Auth::login($user);

        $this->redirect(route('dashboard', absolute: false), navigate: true);
    }
}; ?>

<div>
    <form wire:submit="register">
        <!-- Invite Code -->
        <div>
            <x-input-label for="invite_code" :value="__('Invite code')" />
            <x-text-input wire:model="invite_code" id="invite_code" class="block mt-1 w-full uppercase" type="text" name="invite_code" required autofocus autocomplete="off" />
            <x-input-error :messages="$errors->get('invite_code')" class="mt-2" />
        </div>

        <!-- Household Name (only relevant for "new household" invites; harmless to submit otherwise) -->
        <div class="mt-4">
            <x-input-label for="household_name" :value="__('Household name (only needed when starting a new household)')" />
            <x-text-input wire:model="household_name" id="household_name" class="block mt-1 w-full" type="text" name="household_name" autocomplete="off" />
            <x-input-error :messages="$errors->get('household_name')" class="mt-2" />
        </div>

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
            <a class="underline text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 dark:focus:ring-offset-gray-800" href="{{ route('login') }}" wire:navigate>
                {{ __('Already registered?') }}
            </a>

            <x-primary-button class="ms-4">
                {{ __('Register') }}
            </x-primary-button>
        </div>
    </form>
</div>
