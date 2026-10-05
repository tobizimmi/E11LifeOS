<?php

use App\Models\SecurityEvent;
use App\Models\User;
use App\Support\Security\SecurityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Admin › Users: every account with its households and roles, the administrators, and the areas
 * released or locked per account. Shows account data only – never content.
 */
new #[Layout('layouts.app')] class extends Component
{
    /** all or admins */
    #[Url]
    public string $view = 'all';

    #[Url(as: 'q')]
    public string $search = '';

    public ?int $openId = null;

    public ?int $adminChangeId = null;

    public string $adminPassword = '';

    public function mount(): void
    {
        $this->authorize('admin');
    }

    public function toggle(int $id): void
    {
        $this->authorize('admin');
        $this->openId = $this->openId === $id ? null : $id;
        $this->reset('adminChangeId', 'adminPassword');
        $this->resetValidation();
    }

    public function startAdminChange(int $id): void
    {
        $this->authorize('admin');
        User::findOrFail($id);
        $this->reset('adminPassword');
        $this->resetValidation();
        $this->adminChangeId = $id;
    }

    /**
     * Makes an account administrator or takes the right away – after the password was entered again.
     */
    public function changeAdmin(): void
    {
        $this->authorize('admin');
        $user = User::findOrFail((int) $this->adminChangeId);
        $this->resetValidation();

        if ($user->is(Auth::user())) {
            throw ValidationException::withMessages(['adminPassword' => __('You cannot take away your own administrator right.')]);
        }

        $key = 'admin-confirm:'.Auth::id();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['adminPassword' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key), 'minutes' => ceil(RateLimiter::availableIn($key) / 60)])]);
        }

        if (! Hash::check($this->adminPassword, (string) Auth::user()->password)) {
            RateLimiter::hit($key, 300);

            throw ValidationException::withMessages(['adminPassword' => __('The password is incorrect.')]);
        }

        RateLimiter::clear($key);
        $user->forceFill(['is_admin' => ! $user->is_admin])->save();
        SecurityLog::record(Auth::user(), 'admin_action', details: ($user->is_admin ? 'admin_granted' : 'admin_revoked').' user #'.$user->id);
        SecurityLog::record($user, 'admin_action', details: $user->is_admin ? 'admin_granted_by #'.Auth::id() : 'admin_revoked_by #'.Auth::id());
        $this->reset('adminChangeId', 'adminPassword');
        $this->dispatch('toast', message: $user->is_admin ? __(':name is now an administrator.', ['name' => $user->name]) : __(':name is no longer an administrator.', ['name' => $user->name]));
    }

    public function with(): array
    {
        $term = trim($this->search);
        $users = User::query()
            ->with(['householdMemberships.household:id,name'])
            ->withCount('passkeys')
            ->when($this->view === 'admins', fn ($query) => $query->where('is_admin', true))
            ->when($term !== '', fn ($query) => $query->where(fn ($query) => $query->where('name', 'like', '%'.$term.'%')->orWhere('email', 'like', '%'.$term.'%')))
            ->orderByDesc('is_admin')->orderBy('name')
            ->limit(300)
            ->get();

        return [
            'users' => $users,
            'lastLogins' => SecurityEvent::whereIn('user_id', $users->modelKeys())->whereIn('event', ['login', 'login_new_device', 'api_login'])
                ->groupBy('user_id')->selectRaw('user_id, max(created_at) as last_at')->pluck('last_at', 'user_id'),
            'counts' => ['all' => User::count(), 'admins' => User::where('is_admin', true)->count(), 'withoutHousehold' => User::doesntHave('householdMemberships')->count()],
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
        <div class="mx-auto max-w-4xl space-y-4 px-4 sm:px-6 lg:px-8">
            <x-section-nav tab-property="view" :active="$view" :label="__('Users')" :groups="['' => ['all' => ['👥', __('All users'), null, $counts['all']], 'admins' => ['🛡', __('Administrators'), null, $counts['admins']]]]" />

            @if ($view === 'admins')
                <p class="rounded-xl bg-indigo-50 p-3 text-xs text-indigo-900 dark:bg-indigo-900/30 dark:text-indigo-100">🛡 {{ __('Administrators manage households, users, modules and server settings. They never see the content of a household. Every administrator should sign in with a second factor.') }}</p>
            @elseif ($counts['withoutHousehold'])
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ trans_choice(':count account belongs to no household.|:count accounts belong to no household.', $counts['withoutHousehold']) }}</p>
            @endif

            <input type="search" wire:model.live.debounce.300ms="search" class="{{ $input }}" placeholder="{{ __('Search name or e-mail') }}" aria-label="{{ __('Search') }}">

            <ul class="divide-y divide-gray-100 rounded-2xl bg-white shadow-xs dark:divide-gray-700 dark:bg-gray-800">
                @forelse ($users as $user)
                    @php($secured = $user->hasTwoFactor() || $user->passkeys_count > 0)
                    <li wire:key="user-{{ $user->id }}" class="px-4 py-3">
                        <button type="button" wire:click="toggle({{ $user->id }})" aria-expanded="{{ $openId === $user->id ? 'true' : 'false' }}" class="flex w-full flex-wrap items-baseline justify-between gap-x-3 gap-y-1 text-start">
                            <span class="min-w-0">
                                <span class="font-medium text-gray-900 dark:text-gray-100">{{ $user->name }}</span>
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $user->email }}</span>
                                @if ($user->is_admin)<span class="ms-1 whitespace-nowrap rounded-sm bg-indigo-50 px-1.5 py-0.5 text-[11px] text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200">{{ __('Admin') }}</span>@endif
                                <span @class(['ms-1 whitespace-nowrap rounded-sm px-1.5 py-0.5 text-[11px]', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-200' => $secured, 'bg-amber-50 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200' => ! $secured])>{{ $secured ? '🔐 '.__('2nd factor') : __('No 2nd factor') }}</span>
                                <span class="mt-0.5 block text-xs text-gray-600 dark:text-gray-300">
                                    @forelse ($user->householdMemberships as $membership)
                                        <span class="me-2">🏠 {{ $membership->household?->name }} <span class="text-gray-400">({{ $membership->role === 'owner' ? __('Owner') : __('Member') }})</span></span>
                                    @empty
                                        <span class="text-amber-700 dark:text-amber-300">{{ __('No household') }}</span>
                                    @endforelse
                                </span>
                            </span>
                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                {{ isset($lastLogins[$user->id]) ? __('signed in :time', ['time' => \Illuminate\Support\Carbon::parse($lastLogins[$user->id])->diffForHumans()]) : __('never signed in') }} · {{ __('since :date', ['date' => $user->created_at?->format('d.m.Y')]) }}
                            </span>
                        </button>

                        @if ($openId === $user->id)
                            <div class="mt-3 space-y-4 rounded-xl bg-gray-50 p-4 text-sm dark:bg-gray-900/40">
                                <div class="flex flex-wrap items-center gap-2">
                                    <a href="{{ route('admin.households') }}" wire:navigate class="text-indigo-600 underline dark:text-indigo-400">{{ __('Manage households') }}</a>
                                    @unless ($user->is(auth()->user()))
                                        <x-secondary-button type="button" wire:click="startAdminChange({{ $user->id }})">{{ $user->is_admin ? '🛡 '.__('Remove administrator right') : '🛡 '.__('Make administrator') }}</x-secondary-button>
                                    @endunless
                                </div>

                                @if ($adminChangeId === $user->id)
                                    <form wire:submit="changeAdmin" class="space-y-2 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                                        <p class="text-xs text-gray-600 dark:text-gray-400">{{ $user->is_admin ? __(':name loses access to the administration.', ['name' => $user->name]) : __(':name gets access to the administration: households, users, modules and server settings.', ['name' => $user->name]) }}</p>
                                        <label class="block max-w-xs text-xs text-gray-700 dark:text-gray-300">{{ __('Your account password') }}<input type="password" wire:model="adminPassword" autocomplete="current-password" class="mt-1 {{ $input }}"></label>
                                        <x-input-error :messages="$errors->get('adminPassword')" />
                                        <div class="flex gap-2"><x-primary-button>{{ __('Confirm') }}</x-primary-button><x-secondary-button type="button" wire:click="$set('adminChangeId', null)">{{ __('Cancel') }}</x-secondary-button></div>
                                    </form>
                                @endif

                                <div>
                                    <p class="mb-1 font-medium text-gray-900 dark:text-gray-100">{{ __('Areas for this account') }}</p>
                                    <livewire:admin.module-access subject-type="user" :subject-id="$user->id" :key="'module-access-user-'.$user->id" />
                                </div>
                            </div>
                        @endif
                    </li>
                @empty
                    <li class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">{{ __('No accounts found.') }}</li>
                @endforelse
            </ul>
        </div>
    </div>
</div>
