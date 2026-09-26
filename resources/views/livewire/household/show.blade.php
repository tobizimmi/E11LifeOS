<?php

use App\Models\HouseholdInvite;
use App\Support\Addons;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public ?string $newInviteCode = null;

    public function generateInvite(): void
    {
        $household = Auth::user()->currentHousehold();

        abort_if(! $household, 403);

        $invite = HouseholdInvite::create([
            'household_id' => $household->id,
            'code' => HouseholdInvite::generateCode(),
            'created_by' => Auth::id(),
        ]);

        $this->newInviteCode = $invite->code;
    }

    /**
     * Only the household owner switches addons on or off.
     */
    public function toggleAddon(string $addon): void
    {
        $household = Auth::user()->currentHousehold();
        abort_if(! $household || ! array_key_exists($addon, Addons::all()), 404);

        $membership = $household->members()->where('user_id', Auth::id())->first();
        abort_unless($membership?->isOwner(), 403);

        $household->setAddon($addon, ! $household->hasAddon($addon));

        // Full navigation so the sidebar reflects the change.
        $this->redirectRoute('household.show', navigate: true);
    }

    public function with(): array
    {
        $household = Auth::user()->currentHousehold();

        abort_if(! $household, 403);

        return [
            'household' => $household,
            'members' => $household->users()->orderBy('household_members.role')->get(),
            'invites' => $household->invites()->latest()->get(),
            'addons' => Addons::all(),
            'isOwner' => (bool) $household->members()->where('user_id', Auth::id())->first()?->isOwner(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Household') }}: {{ $household->name }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6">
                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">{{ __('Members') }}</h3>
                <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach ($members as $member)
                        <li class="py-2 flex justify-between items-center">
                            <span class="text-gray-900 dark:text-gray-100">{{ $member->name }}</span>
                            <span class="text-sm text-gray-500 dark:text-gray-400">{{ $member->pivot->role }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6">
                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">{{ __('Invite someone') }}</h3>

                <x-primary-button wire:click="generateInvite">
                    {{ __('Generate invite code') }}
                </x-primary-button>

                @if ($newInviteCode)
                    <div class="mt-4 p-4 bg-gray-50 dark:bg-gray-900 rounded-md">
                        <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Share this code with the person you want to invite:') }}</p>
                        <p class="mt-1 text-xl font-mono tracking-widest text-gray-900 dark:text-gray-100">{{ $newInviteCode }}</p>
                        @php
                            $inviteLink = route('register', ['code' => $newInviteCode]);
                        @endphp
                        <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">{{ __('Or send the invite link – the code is filled in automatically:') }}</p>
                        <div class="mt-1 flex flex-wrap items-center gap-2" x-data="{ copied: false }">
                            <input type="text" readonly value="{{ $inviteLink }}" aria-label="{{ __('Invite link') }}" class="min-w-0 flex-1 rounded-md border-gray-300 bg-white text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200" x-on:focus="$event.target.select()">
                            <x-secondary-button type="button" x-on:click="navigator.clipboard.writeText({{ \Illuminate\Support\Js::from($inviteLink) }}).then(() => { copied = true; setTimeout(() => copied = false, 2000) })">
                                <span x-show="! copied">📋 {{ __('Copy') }}</span><span x-show="copied" x-cloak>✓ {{ __('Copied') }}</span>
                            </x-secondary-button>
                            <x-secondary-button type="button" x-show="navigator.share" x-cloak x-on:click="navigator.share({ title: {{ \Illuminate\Support\Js::from(config('app.name')) }}, text: {{ \Illuminate\Support\Js::from(__('Join our household in :app:', ['app' => config('app.name')])) }}, url: {{ \Illuminate\Support\Js::from($inviteLink) }} })">↗ {{ __('Share') }}</x-secondary-button>
                        </div>
                    </div>
                @endif

                <div class="mt-6">
                    <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('Invite history') }}</h4>
                    <ul class="divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                        @forelse ($invites as $invite)
                            <li class="py-2 flex justify-between items-center">
                                <span class="font-mono text-gray-900 dark:text-gray-100">{{ $invite->code }}</span>
                                <span class="text-gray-500 dark:text-gray-400">
                                    @if ($invite->used_at)
                                        {{ __('used') }} {{ $invite->used_at->diffForHumans() }}
                                    @else
                                        {{ __('open') }}
                                    @endif
                                </span>
                            </li>
                        @empty
                            <li class="py-2 text-gray-500 dark:text-gray-400">{{ __('No invites yet.') }}</li>
                        @endforelse
                    </ul>
                </div>
            </div>


            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6">
                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ __('Addons') }}</h3>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('Optional modules for your household. Switching one off hides it; its data is kept.') }}</p>
                <ul class="mt-4 divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($addons as $key => $addon)
                        <li wire:key="addon-{{ $key }}" class="flex items-center justify-between gap-4 py-3">
                            <div>
                                <p class="font-medium text-gray-900 dark:text-gray-100">{{ $addon['icon'] }} {{ $addon['label'] }}</p>
                                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $addon['description'] }}</p>
                            </div>
                            @if ($isOwner)
                                <button
                                    type="button"
                                    role="switch"
                                    aria-checked="{{ $household->hasAddon($key) ? 'true' : 'false' }}"
                                    aria-label="{{ $addon['label'] }}"
                                    wire:click="toggleAddon('{{ $key }}')"
                                    @class(['relative inline-flex h-6 w-11 shrink-0 rounded-full transition', 'bg-indigo-600' => $household->hasAddon($key), 'bg-gray-300 dark:bg-gray-600' => ! $household->hasAddon($key)])
                                >
                                    <span @class(['inline-block h-5 w-5 translate-y-0.5 rounded-full bg-white shadow transition', 'translate-x-5' => $household->hasAddon($key), 'translate-x-0.5' => ! $household->hasAddon($key)])></span>
                                </button>
                            @else
                                <span class="text-sm text-gray-500 dark:text-gray-400">{{ $household->hasAddon($key) ? __('On') : __('Off') }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</div>
