<?php

use App\Models\Household;
use App\Models\HouseholdInvite;
use App\Models\HouseholdMember;
use App\Models\User;
use App\Support\Admin\HouseholdAdmin;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Admin › Households: create households, manage members, export and delete them. Shows names,
 * members and amounts only – never the content of a household.
 */
new #[Layout('layouts.app')] class extends Component
{
    public string $newName = '';

    public string $newOwnerEmail = '';

    /** Invite link created last (shown once). */
    public ?string $inviteLink = null;

    public ?int $openId = null;

    public string $memberEmail = '';

    public string $renameTo = '';

    public ?int $exportId = null;

    public string $adminPassword = '';

    public string $exportPassword = '';

    public string $exportPassword_confirmation = '';

    public ?int $deleteId = null;

    public string $deleteConfirmName = '';

    public bool $deleteAccounts = false;

    public function mount(): void
    {
        $this->authorize('admin');
    }

    private function household(int $id): Household
    {
        $this->authorize('admin');

        return Household::findOrFail($id);
    }

    public function create(): void
    {
        $this->authorize('admin');
        $this->validate(['newName' => ['required', 'string', 'max:255'], 'newOwnerEmail' => ['nullable', 'email', 'max:255']], attributes: ['newName' => __('Name'), 'newOwnerEmail' => __('E-mail')]);
        $result = HouseholdAdmin::create(Auth::user(), $this->newName, $this->newOwnerEmail ?: null);
        $this->inviteLink = $result['invite'] ? route('register', ['code' => $result['invite']->code]) : null;
        $this->openId = $result['household']->id;
        $this->reset('newName', 'newOwnerEmail');
        $this->dispatch('toast', message: $result['owner'] ? __(':name is the owner of the new household.', ['name' => $result['owner']->name]) : __('Household created – send the invite link to the first owner.'));
    }

    /**
     * A code with which someone registers and creates a household of their own.
     */
    public function registrationCode(): void
    {
        $this->authorize('admin');
        $invite = HouseholdInvite::create(['household_id' => null, 'code' => HouseholdInvite::generateCode(), 'created_by' => Auth::id(), 'expires_at' => now()->addDays(14)]);
        \App\Support\Security\SecurityLog::record(Auth::user(), 'admin_action', details: 'registration_code');
        $this->inviteLink = route('register', ['code' => $invite->code]);
    }

    public function toggle(int $id): void
    {
        $this->openId = $this->openId === $id ? null : $id;
        $this->renameTo = $this->openId ? $this->household($id)->name : '';
        $this->reset('memberEmail');
        $this->resetValidation();
    }

    public function rename(int $id): void
    {
        $this->validate(['renameTo' => ['required', 'string', 'max:255']], attributes: ['renameTo' => __('Name')]);
        $household = $this->household($id);
        $household->update(['name' => $this->renameTo]);
        \App\Support\Security\SecurityLog::record(Auth::user(), 'admin_action', details: 'household_renamed #'.$household->id);
        $this->dispatch('toast', message: __('Saved.'));
    }

    public function invite(int $id): void
    {
        $household = $this->household($id);
        $invite = HouseholdInvite::create(['household_id' => $household->id, 'code' => HouseholdInvite::generateCode(), 'created_by' => Auth::id(), 'expires_at' => now()->addDays(14)]);
        \App\Support\Security\SecurityLog::record(Auth::user(), 'admin_action', details: 'invite_created #'.$household->id);
        $this->inviteLink = route('register', ['code' => $invite->code]);
    }

    public function revokeInvite(int $inviteId): void
    {
        $invite = HouseholdInvite::whereNull('used_at')->findOrFail($inviteId);
        $invite->forceFill(['expires_at' => now()->subSecond()])->save();
        \App\Support\Security\SecurityLog::record(Auth::user(), 'admin_action', details: 'invite_revoked #'.($invite->household_id ?? 0));
        $this->dispatch('toast', message: __('The invite link no longer works.'));
    }

    public function addMember(int $id): void
    {
        $household = $this->household($id);
        $this->validate(['memberEmail' => ['required', 'email']], attributes: ['memberEmail' => __('E-mail')]);
        $user = User::where('email', mb_strtolower(trim($this->memberEmail)))->first();

        if (! $user) {
            throw ValidationException::withMessages(['memberEmail' => __('There is no account with this e-mail – create an invite link instead.')]);
        }

        if ($household->members()->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['memberEmail' => __(':name is already a member.', ['name' => $user->name])]);
        }

        $hasOwner = $household->members()->where('role', 'owner')->exists();
        HouseholdMember::create(['household_id' => $household->id, 'user_id' => $user->id, 'role' => $hasOwner ? 'member' : 'owner', 'joined_at' => now()]);
        HouseholdAdmin::logMemberChange(Auth::user(), $household, $user, 'member_added');
        $this->reset('memberEmail');
    }

    public function setRole(int $id, int $userId, string $role): void
    {
        abort_unless(in_array($role, ['owner', 'member'], true), 422);
        $household = $this->household($id);
        $member = $household->members()->where('user_id', $userId)->firstOrFail();

        if ($role === 'member' && $member->role === 'owner' && $household->members()->where('role', 'owner')->count() === 1) {
            $this->dispatch('toast', message: __('A household needs at least one owner.'));

            return;
        }

        $member->update(['role' => $role]);
        HouseholdAdmin::logMemberChange(Auth::user(), $household, $member->user, 'role_'.$role);
    }

    public function removeMember(int $id, int $userId): void
    {
        $household = $this->household($id);
        $member = $household->members()->where('user_id', $userId)->firstOrFail();

        if ($member->role === 'owner' && $household->members()->where('role', 'owner')->count() === 1 && $household->members()->count() > 1) {
            $this->dispatch('toast', message: __('Make another member the owner first.'));

            return;
        }

        $user = $member->user;
        $member->delete();
        HouseholdAdmin::logMemberChange(Auth::user(), $household, $user, 'member_removed');
    }

    /**
     * Asks for the admin's password again (re-authentication) before data leaves the server or is deleted.
     */
    private function confirmPassword(): void
    {
        $key = 'admin-confirm:'.Auth::id();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['adminPassword' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key), 'minutes' => ceil(RateLimiter::availableIn($key) / 60)])]);
        }

        if (! \Illuminate\Support\Facades\Hash::check($this->adminPassword, (string) Auth::user()->password)) {
            RateLimiter::hit($key, 300);

            throw ValidationException::withMessages(['adminPassword' => __('The password is incorrect.')]);
        }

        RateLimiter::clear($key);
    }

    public function startExport(int $id): void
    {
        $this->household($id);
        $this->reset('adminPassword', 'exportPassword', 'exportPassword_confirmation', 'deleteId');
        $this->resetValidation();
        $this->exportId = $id;
    }

    public function export(): ?BinaryFileResponse
    {
        $household = $this->household((int) $this->exportId);
        $this->validate(['exportPassword' => ['required', 'string', 'min:12', 'max:128', 'confirmed']], attributes: ['exportPassword' => __('Password for the file')]);
        $this->confirmPassword();
        $path = HouseholdAdmin::export(Auth::user(), $household, $this->exportPassword);
        $this->reset('exportId', 'adminPassword', 'exportPassword', 'exportPassword_confirmation');

        return response()->download($path, 'household-'.$household->id.'-'.now()->format('Y-m-d').'.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    public function startDelete(int $id): void
    {
        $this->household($id);
        $this->reset('adminPassword', 'deleteConfirmName', 'deleteAccounts', 'exportId');
        $this->resetValidation();
        $this->deleteId = $id;
    }

    public function delete(): void
    {
        $household = $this->household((int) $this->deleteId);
        $this->resetValidation();

        if ($household->members()->where('user_id', Auth::id())->exists()) {
            throw ValidationException::withMessages(['deleteConfirmName' => __('You are a member of this household – leave it first or let another administrator delete it.')]);
        }

        if (trim($this->deleteConfirmName) !== $household->name) {
            throw ValidationException::withMessages(['deleteConfirmName' => __('Type the name of the household exactly.')]);
        }

        $this->confirmPassword();
        $accounts = HouseholdAdmin::delete(Auth::user(), $household, $this->deleteAccounts);
        $this->reset('deleteId', 'adminPassword', 'deleteConfirmName', 'deleteAccounts', 'openId');
        $this->dispatch('toast', message: __('“:name” was deleted.', ['name' => $household->name]).($accounts ? ' '.trans_choice(':count account deleted.|:count accounts deleted.', $accounts) : ''));
    }

    public function with(): array
    {
        $households = Household::with(['members' => fn ($query) => $query->orderByDesc('role')->orderBy('joined_at'), 'members.user:id,name,email,is_admin'])->orderBy('name')->get();

        return [
            'households' => $households,
            'stats' => HouseholdAdmin::stats($households),
            'openInvites' => HouseholdInvite::whereNull('used_at')->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->orderByDesc('id')->get()->groupBy(fn (HouseholdInvite $invite) => $invite->household_id ?? 0),
        ];
    }
}; ?>

@php
    $input = 'block w-full rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
@endphp

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Administration') }}</h2>
        <x-admin-nav />
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-4xl space-y-6 px-4 sm:px-6 lg:px-8">
            <p class="rounded-xl bg-indigo-50 p-3 text-xs text-indigo-900 dark:bg-indigo-900/30 dark:text-indigo-100">🔒 {{ __('Data protection: as administrator you see names, members and amounts – never the content of a household. Exports contain only what the household shares, are password protected and the owners are told about them. Every action is recorded in the admin log.') }}</p>

            <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('New household') }}</h3>
                <form wire:submit="create" class="mt-3 grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                    <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Name') }}<input type="text" wire:model="newName" class="mt-1 {{ $input }}" placeholder="{{ __('e.g. Family Müller') }}"><x-input-error :messages="$errors->get('newName')" /></label>
                    <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Owner (e-mail, optional)') }}<input type="email" wire:model="newOwnerEmail" class="mt-1 {{ $input }}" placeholder="name@example.com"><x-input-error :messages="$errors->get('newOwnerEmail')" /></label>
                    <x-primary-button class="justify-center">{{ __('Create') }}</x-primary-button>
                </form>
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('With the e-mail of an existing account that person becomes the owner; otherwise you get an invite link for the first owner (valid for 14 days).') }}
                    <button type="button" wire:click="registrationCode" class="text-indigo-600 underline dark:text-indigo-400">{{ __('Or: create a registration link with which someone sets up a household of their own') }}</button></p>
                @if ($inviteLink)
                    <div class="mt-3 rounded-lg bg-emerald-50 p-3 text-sm dark:bg-emerald-900/30" x-data>
                        <p class="text-emerald-900 dark:text-emerald-100">{{ __('Invite link (valid for 14 days, can be used once):') }}</p>
                        <div class="mt-1 flex gap-2"><input type="text" readonly value="{{ $inviteLink }}" class="{{ $input }} font-mono" x-ref="link"><x-secondary-button type="button" x-on:click="navigator.clipboard.writeText($refs.link.value)">{{ __('Copy') }}</x-secondary-button></div>
                    </div>
                @endif
            </section>

            <section class="rounded-2xl bg-white shadow-xs dark:bg-gray-800">
                <h3 class="px-5 pt-5 font-semibold text-gray-900 dark:text-gray-100">{{ trans_choice(':count household|:count households', $households->count()) }}</h3>
                <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($households as $household)
                        @php($stat = $stats[$household->id])
                        <li wire:key="household-{{ $household->id }}" class="px-5 py-3">
                            <button type="button" wire:click="toggle({{ $household->id }})" aria-expanded="{{ $openId === $household->id ? 'true' : 'false' }}" class="flex w-full flex-wrap items-baseline justify-between gap-2 text-start">
                                <span class="font-medium text-gray-900 dark:text-gray-100">{{ $household->name }}</span>
                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ trans_choice(':count member|:count members', $household->members->count()) }} · {{ trans_choice(':count entry|:count entries', $stat['entries']) }} · {{ trans_choice(':count recipe|:count recipes', $stat['recipes']) }} · {{ trans_choice(':count file|:count files', $stat['files']) }} ({{ number_format($stat['bytes'] / 1048576, 1, ',', '.') }} MB)
                                    @if ($stat['last_activity']) · {{ __('active :time', ['time' => \Illuminate\Support\Carbon::parse($stat['last_activity'])->diffForHumans()]) }} @endif
                                </span>
                            </button>

                            @if ($openId === $household->id)
                                <div class="mt-3 space-y-4 rounded-xl bg-gray-50 p-4 text-sm dark:bg-gray-900/40">
                                    <form wire:submit="rename({{ $household->id }})" class="flex flex-wrap items-end gap-2">
                                        <label class="flex-1 text-gray-700 dark:text-gray-300">{{ __('Name') }}<input type="text" wire:model="renameTo" class="mt-1 {{ $input }}"></label>
                                        <x-secondary-button type="submit">{{ __('Rename') }}</x-secondary-button>
                                    </form>
                                    <x-input-error :messages="$errors->get('renameTo')" />

                                    <div>
                                        <p class="font-medium text-gray-900 dark:text-gray-100">{{ __('Members') }}</p>
                                        <ul class="mt-1 space-y-1">
                                            @forelse ($household->members as $member)
                                                <li class="flex flex-wrap items-center gap-2" wire:key="member-{{ $household->id }}-{{ $member->user_id }}">
                                                    <span class="min-w-0 flex-1 text-gray-800 dark:text-gray-200">{{ $member->user?->name }} <span class="text-xs text-gray-500">{{ $member->user?->email }}</span>@if ($member->user?->is_admin) <span class="text-xs text-indigo-600">· {{ __('Admin') }}</span>@endif</span>
                                                    <select wire:change="setRole({{ $household->id }}, {{ $member->user_id }}, $event.target.value)" class="rounded-md border-gray-300 py-1 text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" aria-label="{{ __('Role') }}">
                                                        <option value="owner" @selected($member->role === 'owner')>{{ __('Owner') }}</option>
                                                        <option value="member" @selected($member->role !== 'owner')>{{ __('Member') }}</option>
                                                    </select>
                                                    <button type="button" wire:click="removeMember({{ $household->id }}, {{ $member->user_id }})" wire:confirm="{{ __('Remove :name from the household? The account and its data stay.', ['name' => $member->user?->name]) }}" class="text-xs text-red-600 underline dark:text-red-400">{{ __('Remove') }}</button>
                                                </li>
                                            @empty
                                                <li class="text-gray-500">{{ __('No members yet.') }}</li>
                                            @endforelse
                                        </ul>
                                        @php($householdInvites = $openInvites[$household->id] ?? collect())
                                        @if ($householdInvites->isNotEmpty())
                                            <div class="mt-2 rounded-lg bg-indigo-50 p-2 text-xs dark:bg-indigo-900/20">
                                                <p class="mb-1 font-medium text-indigo-900 dark:text-indigo-100">🔗 {{ trans_choice(':count active invite link|:count active invite links', $householdInvites->count()) }}</p>
                                                <ul class="space-y-1">
                                                    @foreach ($householdInvites as $openInvite)
                                                        @php($openInviteUrl = route('register', ['code' => $openInvite->code]))
                                                        <li wire:key="invite-{{ $openInvite->id }}" class="flex flex-wrap items-center gap-2" x-data="{ copied: false }">
                                                            <input type="text" readonly value="{{ $openInviteUrl }}" class="{{ $input }} min-w-0 flex-1 text-xs" x-on:focus="$el.select()" aria-label="{{ __('Invite link') }}">
                                                            <button type="button" x-on:click="navigator.clipboard.writeText({{ \Illuminate\Support\Js::from($openInviteUrl) }}).then(() => { copied = true; setTimeout(() => copied = false, 2000) })" class="shrink-0 rounded-md px-2 py-1 ring-1 ring-gray-300 dark:ring-gray-600"><span x-text="copied ? @js(__('Copied!')) : @js(__('Copy'))"></span></button>
                                                            <span class="shrink-0 text-gray-500 dark:text-gray-400">{{ $openInvite->expires_at ? __('until :date', ['date' => $openInvite->expires_at->format('d.m.Y')]) : __('no expiry') }}</span>
                                                            <button type="button" wire:click="revokeInvite({{ $openInvite->id }})" wire:confirm="{{ __('Deactivate this invite link?') }}" class="shrink-0 text-red-600 underline dark:text-red-400">{{ __('Deactivate') }}</button>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            </div>
                                        @endif
                                        <form wire:submit="addMember({{ $household->id }})" class="mt-2 flex flex-wrap gap-2">
                                            <input type="email" wire:model="memberEmail" class="{{ $input }} max-w-xs" placeholder="{{ __('E-mail of an existing account') }}" aria-label="{{ __('E-mail') }}">
                                            <x-secondary-button type="submit">+ {{ __('Add') }}</x-secondary-button>
                                            <x-secondary-button type="button" wire:click="invite({{ $household->id }})">🔗 {{ __('Invite link') }}</x-secondary-button>
                                        </form>
                                        <x-input-error :messages="$errors->get('memberEmail')" />
                                    </div>

                                    <div>
                                        <p class="mb-1 font-medium text-gray-900 dark:text-gray-100">{{ __('Areas for this household') }}</p>
                                        <livewire:admin.module-access subject-type="household" :subject-id="$household->id" :key="'module-access-household-'.$household->id" />
                                    </div>

                                    <div class="flex flex-wrap gap-2 border-t border-gray-200 pt-3 dark:border-gray-700">
                                        <x-secondary-button type="button" wire:click="startExport({{ $household->id }})">📦 {{ __('Export data') }}</x-secondary-button>
                                        <x-danger-button type="button" wire:click="startDelete({{ $household->id }})">🗑 {{ __('Delete household') }}</x-danger-button>
                                    </div>

                                    @if ($exportId === $household->id)
                                        <form wire:submit="export" class="space-y-2 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                                            <p class="text-xs text-gray-600 dark:text-gray-400">{{ __('A ZIP file encrypted with AES-256 with the data the household shares (entries, recipes, lists, finances of the household …). Private entries of members, personal areas and access credentials are not included. The owners get a notice.') }}</p>
                                            <div class="grid gap-2 sm:grid-cols-3">
                                                <label class="text-xs text-gray-700 dark:text-gray-300">{{ __('Password for the file') }}<input type="password" wire:model="exportPassword" autocomplete="new-password" class="mt-1 {{ $input }}"></label>
                                                <label class="text-xs text-gray-700 dark:text-gray-300">{{ __('Repeat password') }}<input type="password" wire:model="exportPassword_confirmation" autocomplete="new-password" class="mt-1 {{ $input }}"></label>
                                                <label class="text-xs text-gray-700 dark:text-gray-300">{{ __('Your account password') }}<input type="password" wire:model="adminPassword" autocomplete="current-password" class="mt-1 {{ $input }}"></label>
                                            </div>
                                            <x-input-error :messages="$errors->get('exportPassword')" /><x-input-error :messages="$errors->get('adminPassword')" />
                                            <div class="flex gap-2"><x-primary-button>{{ __('Download export') }}</x-primary-button><x-secondary-button type="button" wire:click="$set('exportId', null)">{{ __('Cancel') }}</x-secondary-button></div>
                                        </form>
                                    @endif

                                    @if ($deleteId === $household->id)
                                        <form wire:submit="delete" class="space-y-2 rounded-lg border border-red-200 bg-red-50 p-3 dark:border-red-800 dark:bg-red-900/20">
                                            <p class="text-xs text-red-800 dark:text-red-200">{{ __('Deletes the household with all entries, files, recipes, finances and settings for good. Backups are overwritten as usual after their retention period. Create an export first if the data is still needed.') }}</p>
                                            <div class="grid gap-2 sm:grid-cols-2">
                                                <label class="text-xs text-gray-700 dark:text-gray-300">{{ __('Type “:name” to confirm', ['name' => $household->name]) }}<input type="text" wire:model="deleteConfirmName" class="mt-1 {{ $input }}"></label>
                                                <label class="text-xs text-gray-700 dark:text-gray-300">{{ __('Your account password') }}<input type="password" wire:model="adminPassword" autocomplete="current-password" class="mt-1 {{ $input }}"></label>
                                            </div>
                                            <label class="flex items-center gap-2 text-xs text-gray-700 dark:text-gray-300"><input type="checkbox" wire:model="deleteAccounts" class="rounded-sm border-gray-300 text-red-600"> {{ __('Also delete the accounts of members who then belong to no household (with their personal data)') }}</label>
                                            <x-input-error :messages="$errors->get('deleteConfirmName')" /><x-input-error :messages="$errors->get('adminPassword')" />
                                            <div class="flex gap-2"><x-danger-button>{{ __('Delete for good') }}</x-danger-button><x-secondary-button type="button" wire:click="$set('deleteId', null)">{{ __('Cancel') }}</x-secondary-button></div>
                                        </form>
                                    @endif
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        </div>
    </div>
</div>
