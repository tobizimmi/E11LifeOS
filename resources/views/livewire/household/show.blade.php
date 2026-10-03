<?php

use App\Models\HouseholdInvite;
use App\Models\InboundMail;
use App\Support\Mail\MailIntake;
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

    /**
     * The owner switches a module off (or on again) for a single member.
     */
    public function toggleMemberAddon(int $memberId, string $addon): void
    {
        $household = Auth::user()->currentHousehold();
        abort_if(! $household || ! array_key_exists($addon, Addons::all()), 404);
        abort_unless($household->members()->where('user_id', Auth::id())->first()?->isOwner(), 403);

        $member = $household->members()->whereKey($memberId)->firstOrFail();
        abort_if($member->isOwner(), 422);

        $permissions = $member->permissions ?? [];

        if ($member->mayUse($addon)) {
            $permissions[$addon] = false;
        } else {
            unset($permissions[$addon]);
        }

        $member->update(['permissions' => $permissions ?: null]);
    }

    /**
     * Creates the household's mail address or replaces it with a new one (the old one stops working).
     */
    public function assignMailAddress(): void
    {
        $household = Auth::user()->currentHousehold();
        abort_if(! $household || ! MailIntake::domain(), 404);
        abort_unless($household->members()->where('user_id', Auth::id())->first()?->isOwner(), 403);

        MailIntake::assignAddress($household);
        $this->dispatch('toast', message: __('The new address is active.'));
    }

    public function removeMailAddress(): void
    {
        $household = Auth::user()->currentHousehold();
        abort_if(! $household, 404);
        abort_unless($household->members()->where('user_id', Auth::id())->first()?->isOwner(), 403);

        $household->forceFill(['mail_address' => null])->save();
    }

    public function with(): array
    {
        $household = Auth::user()->currentHousehold();

        abort_if(! $household, 403);

        return [
            'household' => $household,
            'members' => $household->members()->with('user')->orderBy('role')->get(),
            'invites' => $household->invites()->latest()->get(),
            'addons' => Addons::all(),
            'isOwner' => (bool) $household->members()->where('user_id', Auth::id())->first()?->isOwner(),
            'load' => \App\Support\Life\HouseholdLoad::for($household),
            'mailDomain' => MailIntake::domain(),
            'mailAddress' => MailIntake::address($household),
            'inboundMails' => InboundMail::where('household_id', $household->id)->with('trip')->latest()->limit(10)->get(),
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
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-xs rounded-2xl p-6">
                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">{{ __('Members') }}</h3>
                <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach ($members as $member)
                        <li wire:key="member-{{ $member->id }}" class="py-3">
                            <div class="flex items-center justify-between">
                                <span class="text-gray-900 dark:text-gray-100">{{ $member->user?->name }}</span>
                                <span class="text-sm text-gray-500 dark:text-gray-400">{{ $member->isOwner() ? __('Owner') : __('Member') }}</span>
                            </div>
                            @if (! $member->isOwner())
                                <div class="mt-2 flex flex-wrap gap-2" role="group" aria-label="{{ __('Modules for :name', ['name' => $member->user?->name]) }}">
                                    @foreach ($addons as $key => $addon)
                                        @continue(! $household->hasAddon($key))
                                        @if ($isOwner)
                                            <button type="button" wire:click="toggleMemberAddon({{ $member->id }}, '{{ $key }}')" aria-pressed="{{ $member->mayUse($key) ? 'true' : 'false' }}" @class(['rounded-full px-2.5 py-1 text-xs ring-1', 'bg-indigo-50 text-indigo-700 ring-indigo-200 dark:bg-indigo-900/40 dark:text-indigo-200 dark:ring-indigo-800' => $member->mayUse($key), 'bg-gray-50 text-gray-400 line-through ring-gray-200 dark:bg-gray-900 dark:ring-gray-700' => ! $member->mayUse($key)])>{{ $addon['icon'] }} {{ $addon['label'] }}</button>
                                        @elseif (! $member->mayUse($key))
                                            <span class="rounded-full bg-gray-50 px-2.5 py-1 text-xs text-gray-400 line-through ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-gray-700">{{ $addon['icon'] }} {{ $addon['label'] }}</span>
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
                @if ($isOwner && $members->count() > 1)
                    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('Click a module to switch it off or on for this member. Owners always see everything. Tasks, pages, shopping list and collections are always shared.') }}</p>
                @endif
            </div>

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-xs rounded-2xl p-6">
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


            @if ($mailDomain)
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-xs rounded-2xl p-6" x-data="{ copied: false }">
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">✉️ {{ __('Mail address of the household') }}</h3>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('Forward booking confirmations, invoices or notes to this address – they land in the right place by themselves.') }}</p>

                    @if ($mailAddress)
                        <div class="mt-4 flex flex-wrap items-center gap-2">
                            <code class="min-w-0 flex-1 truncate rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-900 dark:bg-gray-900 dark:text-gray-100">{{ $mailAddress }}</code>
                            <button type="button" x-on:click="navigator.clipboard.writeText(@js($mailAddress)); copied = true; setTimeout(() => copied = false, 1500)" class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500"><span x-show="! copied">{{ __('Copy') }}</span><span x-show="copied" x-cloak>✓ {{ __('Copied') }}</span></button>
                            @if ($isOwner)
                                <button type="button" wire:click="assignMailAddress" wire:confirm="{{ __('Create a new address? The current one stops working right away.') }}" class="rounded-lg px-3 py-2 text-sm text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700">{{ __('New address') }}</button>
                                <button type="button" wire:click="removeMailAddress" wire:confirm="{{ __('Switch off the mail address?') }}" class="rounded-lg px-3 py-2 text-sm text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-gray-700">{{ __('Switch off') }}</button>
                            @endif
                        </div>
                        <dl class="mt-4 grid gap-x-6 gap-y-1 text-sm text-gray-700 sm:grid-cols-2 dark:text-gray-300">
                            <div><dt class="inline font-medium">🎫 {{ __('Booking confirmation') }}</dt> <dd class="inline text-gray-500 dark:text-gray-400">→ {{ __('the matching trip') }}</dd></div>
                            <div><dt class="inline font-medium">„Aufgabe: …“</dt> <dd class="inline text-gray-500 dark:text-gray-400">→ {{ __('a task') }}</dd></div>
                            <div><dt class="inline font-medium">„Einkauf: …“</dt> <dd class="inline text-gray-500 dark:text-gray-400">→ {{ __('shopping list (one item per line)') }}</dd></div>
                            <div><dt class="inline font-medium">{{ __('Everything else') }}</dt> <dd class="inline text-gray-500 dark:text-gray-400">→ {{ __('inbox') }}</dd></div>
                            <div class="sm:col-span-2"><dt class="inline font-medium">📎 {{ __('PDFs and photos') }}</dt> <dd class="inline text-gray-500 dark:text-gray-400">→ {{ __('paperless, otherwise kept with the entry') }}</dd></div>
                        </dl>
                        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('Keep the address to yourselves: whoever knows it can send mails to your household. Mails from addresses that are not members are marked.') }}</p>

                        @if ($inboundMails->isNotEmpty())
                            <h4 class="mt-5 text-sm font-medium text-gray-900 dark:text-gray-100">{{ __('Received recently') }}</h4>
                            <ul class="mt-2 divide-y divide-gray-100 text-sm dark:divide-gray-700">
                                @foreach ($inboundMails as $inboundMail)
                                    <li wire:key="inbound-{{ $inboundMail->id }}" class="flex flex-wrap items-baseline gap-x-3 py-2">
                                        <span class="w-28 shrink-0 text-xs text-gray-500 dark:text-gray-400">{{ $inboundMail->created_at->translatedFormat('j. M, H:i') }}</span>
                                        <span class="min-w-0 flex-1 truncate text-gray-900 dark:text-gray-100">{{ $inboundMail->subject ?: __('Mail without subject') }}<span class="block truncate text-xs text-gray-500 dark:text-gray-400">{{ $inboundMail->from_address }}@if ($inboundMail->note) · {{ $inboundMail->note }}@endif</span></span>
                                        <span class="text-xs text-gray-600 dark:text-gray-300">{{ \App\Models\InboundMail::statusLabel($inboundMail->status) }}@if ($inboundMail->trip) · <a href="{{ route('trips.show', ['trip' => $inboundMail->trip, 'tab' => 'bookings']) }}" wire:navigate class="text-indigo-600 underline dark:text-indigo-400">{{ $inboundMail->trip->title }}</a>@endif</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    @elseif ($isOwner)
                        <button type="button" wire:click="assignMailAddress" class="mt-4 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">{{ __('Create mail address') }}</button>
                    @else
                        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ __('The owner of the household can create the address.') }}</p>
                    @endif
                </div>
            @endif

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-xs rounded-2xl p-6">
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
                                    <span @class(['inline-block h-5 w-5 translate-y-0.5 rounded-full bg-white shadow-sm transition', 'translate-x-5' => $household->hasAddon($key), 'translate-x-0.5' => ! $household->hasAddon($key)])></span>
                                </button>
                            @else
                                <span class="text-sm text-gray-500 dark:text-gray-400">{{ $household->hasAddon($key) ? __('On') : __('Off') }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>

            @if (count($load['members']) > 1)
                <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow-xs rounded-2xl">
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">⚖️ {{ __('Who does what?') }}</h3>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('Last :days days: completed tasks and entries, what was thought of and planned (tasks, shopping entries, meals) and open tasks assigned.', ['days' => $load['days']]) }}</p>
                    <ul class="mt-4 space-y-3">
                        @foreach ($load['members'] as $row)
                            <li wire:key="load-{{ $row['user_id'] }}">
                                <div class="flex flex-wrap items-baseline justify-between gap-2 text-sm">
                                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ $row['name'] }}</span>
                                    <span class="text-gray-500 dark:text-gray-400">{{ __(':completed done · :planned planned · :open open', ['completed' => $row['completed'], 'planned' => $row['planned'], 'open' => $row['open']]) }} · <span class="font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $row['share'] }} %</span></span>
                                </div>
                                <div class="mt-1 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700"><div class="h-full rounded-full bg-indigo-500" style="width: {{ $row['share'] }}%"></div></div>
                            </li>
                        @endforeach
                    </ul>
                    @if ($load['lopsided'])
                        <p class="mt-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-900/30 dark:text-amber-100">💡 {{ __(':name currently carries most of it. Assign tasks, share the cleaning plan with rotation or take turns planning meals and shopping.', ['name' => $load['lopsided']]) }}</p>
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>
