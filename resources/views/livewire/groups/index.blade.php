<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\Share;
use App\Models\ShareGroup;
use App\Support\Life\GroupTrip;
use App\Support\Sharing;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Groups beyond the household (friends, family elsewhere, a club) and an overview of what is
 * shared with whom – so it is always clear what leaves the household.
 */
new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    /**
     * @var array{name: string, icon: string}
     */
    public array $form = ['name' => '', 'icon' => '👥'];

    public bool $showForm = false;

    private function findGroup(int $id): ShareGroup
    {
        return ShareGroup::query()->ofMember(Auth::user())->findOrFail($id);
    }

    private function findOwnedGroup(int $id): ShareGroup
    {
        $group = $this->findGroup($id);
        abort_unless($group->isOwner(Auth::user()), 403);

        return $group;
    }

    public function create(): void
    {
        $data = $this->validate([
            'form.name' => ['required', 'string', 'max:80'],
            'form.icon' => ['nullable', 'string', 'max:16'],
        ], attributes: ['form.name' => __('Name')])['form'];

        DB::transaction(function () use ($data) {
            $group = ShareGroup::create(['name' => $data['name'], 'icon' => $data['icon'] ?: '👥', 'created_by' => Auth::id()]);
            $group->members()->create(['user_id' => Auth::id(), 'role' => ShareGroup::ROLE_OWNER]);
        });

        $this->reset(['form', 'showForm']);
        $this->dispatch('toast', message: __('Group created. Invite people with the link.'));
    }

    public function createInvite(int $id): void
    {
        $this->findOwnedGroup($id)->update(['invite_token' => Str::random(40), 'invite_expires_at' => now()->addDays(7)]);
    }

    public function revokeInvite(int $id): void
    {
        $this->findOwnedGroup($id)->update(['invite_token' => null, 'invite_expires_at' => null]);
    }

    public function removeMember(int $groupId, int $userId): void
    {
        $group = $this->findOwnedGroup($groupId);
        abort_if($userId === Auth::id(), 422);
        $member = $group->members()->where('user_id', $userId)->firstOrFail();
        $group->removeMember($member->user);
    }

    /**
     * The group admin takes a household out, or the household's owner takes their own household out.
     */
    public function removeHousehold(int $groupId, int $householdId): void
    {
        $group = $this->findGroup($groupId);
        $household = $group->households()->whereKey($householdId)->firstOrFail();
        $ownsHousehold = HouseholdMember::query()->where('household_id', $household->id)->where('user_id', Auth::id())->where('role', 'owner')->exists();
        abort_unless($group->isOwner(Auth::user()) || $ownsHousehold, 403);
        $group->removeHousehold($household);
    }

    public function leave(int $id): void
    {
        $this->findGroup($id)->removeMember(Auth::user());
        $this->dispatch('toast', message: __('You left the group. What you shared there is no longer shared.'));
    }

    public function deleteGroup(int $id): void
    {
        $this->findOwnedGroup($id)->delete();
    }

    public function unshare(int $shareId): void
    {
        Share::query()->where('shared_by', Auth::id())->findOrFail($shareId)->delete();
    }

    public function with(): array
    {
        $user = Auth::user();
        $groups = ShareGroup::query()->ofMember($user)->with(['members' => fn ($query) => $query->orderBy('id'), 'members.user.households', 'households.members.user'])->orderBy('name')->get();
        $shares = Share::query()->whereIn('share_group_id', $groups->pluck('id'))->with(['shareable', 'group', 'sharer'])->latest()->get();
        $existing = collect(Sharing::existing($shares));

        return [
            'groups' => $groups,
            'myShares' => $existing->where('shared_by', $user->id)->values(),
            'sharedWithMe' => $existing->where('shared_by', '!=', $user->id)->values(),
            'householdId' => (int) $this->householdId,
            'ownedHouseholdIds' => HouseholdMember::query()->where('user_id', $user->id)->where('role', 'owner')->pluck('household_id')->map(fn ($id) => (int) $id)->all(),
            'whatsapp' => fn (ShareGroup $group) => GroupTrip::whatsappUrl(__('Join my group “:name” in E11LifeOS: :link', ['name' => $group->name, 'link' => route('groups.join', $group->invite_token)])),
        ];
    }
}; ?>

@php
    $input = 'mt-1 block w-full rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
@endphp

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Groups & sharing') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-4xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('status'))
                <p class="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:bg-emerald-900/30 dark:text-emerald-100" role="status">✓ {{ session('status') }}</p>
            @endif
            <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Who sees what?') }}</h3>
                <div class="mt-3 grid gap-3 text-sm sm:grid-cols-3">
                    <div class="rounded-xl bg-amber-50 p-3 text-amber-900 dark:bg-amber-900/30 dark:text-amber-100"><p class="font-medium">🔒 {{ __('Only me') }}</p><p class="mt-1 text-xs">{{ __('Private contents – nobody else, not even your household.') }}</p></div>
                    <div class="rounded-xl bg-emerald-50 p-3 text-emerald-900 dark:bg-emerald-900/30 dark:text-emerald-100"><p class="font-medium">🏠 {{ __('Household') }}</p><p class="mt-1 text-xs">{{ __('The standard: everyone in your household.') }}</p></div>
                    <div class="rounded-xl bg-sky-50 p-3 text-sky-900 dark:bg-sky-900/30 dark:text-sky-100"><p class="font-medium">👥 {{ __('Groups') }}</p><p class="mt-1 text-xs">{{ __('Single contents you shared with people or households outside your household.') }}</p></div>
                </div>
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('Trips, pages, cost splits, recipes, shopping lists, places and collections show this badge and a “Who sees this?” box where you choose the groups.') }}</p>
            </section>

            <section class="space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('My groups') }}</h3>
                    <x-secondary-button type="button" wire:click="$toggle('showForm')">+ {{ __('New group') }}</x-secondary-button>
                </div>

                @if ($showForm)
                    <form wire:submit="create" class="grid gap-3 rounded-2xl bg-white p-4 shadow-xs sm:grid-cols-6 dark:bg-gray-800">
                        <label class="text-sm text-gray-700 sm:col-span-1 dark:text-gray-300">{{ __('Icon') }}<input type="text" wire:model="form.icon" class="{{ $input }}" maxlength="16"></label>
                        <label class="text-sm text-gray-700 sm:col-span-5 dark:text-gray-300">{{ __('Name') }}<input type="text" wire:model="form.name" class="{{ $input }}" placeholder="{{ __('e.g. Friends from Munich, Club trip 2027') }}"><x-input-error :messages="$errors->get('form.name')" /></label>
                        <div class="sm:col-span-6"><x-primary-button>{{ __('Create group') }}</x-primary-button></div>
                    </form>
                @endif

                @forelse ($groups as $group)
                    @php($isOwner = $group->members->contains(fn ($member) => $member->user_id === auth()->id() && $member->role === \App\Models\ShareGroup::ROLE_OWNER))
                    <article wire:key="group-{{ $group->id }}" class="rounded-2xl bg-white p-4 shadow-xs dark:bg-gray-800">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h4 class="font-semibold text-gray-900 dark:text-gray-100">{{ $group->icon }} {{ $group->name }}</h4>
                            <div class="flex gap-3 text-sm">
                                @if ($isOwner)
                                    <button type="button" wire:click="deleteGroup({{ $group->id }})" wire:confirm="{{ __('Delete this group? Everything shared in it is no longer shared.') }}" class="text-red-600 underline dark:text-red-400">{{ __('Delete group') }}</button>
                                @elseif ($group->members->contains('user_id', auth()->id()))
                                    <button type="button" wire:click="leave({{ $group->id }})" wire:confirm="{{ __('Leave this group? What you shared there is no longer shared.') }}" class="text-red-600 underline dark:text-red-400">{{ __('Leave group') }}</button>
                                @endif
                            </div>
                        </div>
                        <ul class="mt-2 flex flex-wrap gap-2">
                            @foreach ($group->members as $member)
                                <li wire:key="member-{{ $member->id }}" class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-3 py-1 text-sm text-gray-700 dark:bg-gray-700 dark:text-gray-200">
                                    {{ $member->user->name }}
                                    @if ($member->user->households->pluck('id')->contains($householdId))<span class="text-xs text-emerald-700 dark:text-emerald-300" title="{{ __('In your household') }}">🏠</span>@endif
                                    @if ($member->role === \App\Models\ShareGroup::ROLE_OWNER)<span class="text-xs text-gray-500 dark:text-gray-400">({{ __('Admin') }})</span>@endif
                                    @if ($isOwner && $member->user_id !== auth()->id())
                                        <button type="button" wire:click="removeMember({{ $group->id }}, {{ $member->user_id }})" wire:confirm="{{ __('Remove :name from the group?', ['name' => $member->user->name]) }}" class="ms-1 text-gray-400 hover:text-red-600" aria-label="{{ __('Remove') }}">×</button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        @foreach ($group->households as $groupHousehold)
                            <div wire:key="group-household-{{ $group->id }}-{{ $groupHousehold->id }}" class="mt-2 flex flex-wrap items-center gap-2 rounded-xl bg-violet-50 px-3 py-2 text-sm text-violet-900 dark:bg-violet-900/30 dark:text-violet-100">
                                <span class="font-medium">🏠 {{ $groupHousehold->name }}</span>
                                <span class="text-xs">{{ __('whole household: :names', ['names' => $groupHousehold->members->map(fn ($member) => $member->user?->name)->filter()->implode(', ')]) }}</span>
                                @if ($isOwner || in_array($groupHousehold->id, $ownedHouseholdIds, true))
                                    <button type="button" wire:click="removeHousehold({{ $group->id }}, {{ $groupHousehold->id }})" wire:confirm="{{ __('Take the household :name out of the group? What its people shared there is no longer shared.', ['name' => $groupHousehold->name]) }}" class="ms-auto text-xs text-red-600 underline dark:text-red-400">{{ in_array($groupHousehold->id, $ownedHouseholdIds, true) && ! $isOwner ? __('Leave with household') : __('Remove household') }}</button>
                                @endif
                            </div>
                        @endforeach
                        @if ($isOwner)
                            <div class="mt-3 border-t border-gray-100 pt-3 text-sm dark:border-gray-700">
                                @if ($group->hasValidInvite())
                                    <p class="text-gray-600 dark:text-gray-300">{{ __('Invite link (valid until :date, for people with an account – a household owner can bring the whole household):', ['date' => $group->invite_expires_at?->format('d.m.Y')]) }}</p>
                                    <div class="mt-1 flex flex-wrap items-center gap-2" x-data="{ copied: false }">
                                        <input type="text" readonly value="{{ route('groups.join', $group->invite_token) }}" class="min-w-0 flex-1 rounded-md border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" x-ref="link" @focus="$event.target.select()">
                                        <button type="button" class="text-indigo-600 underline dark:text-indigo-400" @click="navigator.clipboard.writeText($refs.link.value); copied = true" x-text="copied ? @js(__('Copied')) : @js(__('Copy'))"></button>
                                        <a href="{{ $whatsapp($group) }}" target="_blank" rel="noopener" class="text-emerald-700 underline dark:text-emerald-400">WhatsApp</a>
                                        <button type="button" wire:click="revokeInvite({{ $group->id }})" class="text-gray-500 underline dark:text-gray-400">{{ __('Deactivate link') }}</button>
                                    </div>
                                @else
                                    <button type="button" wire:click="createInvite({{ $group->id }})" class="text-indigo-600 underline dark:text-indigo-400">🔗 {{ __('Create invite link') }}</button>
                                @endif
                            </div>
                        @endif
                    </article>
                @empty
                    <p class="rounded-2xl bg-white p-5 text-sm text-gray-500 shadow-xs dark:bg-gray-800 dark:text-gray-400">{{ __('No group yet. Create one to share single contents with friends or other households – everything else stays in your household.') }}</p>
                @endforelse
            </section>

            <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('What I share outside the household') }}</h3>
                <ul class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse ($myShares as $share)
                        @php($kind = \App\Support\Sharing::describe($share->shareable))
                        <li wire:key="my-share-{{ $share->id }}" class="flex flex-wrap items-center gap-2 py-2 text-sm">
                            <a href="{{ \App\Support\Sharing::url($share->shareable, $householdId) }}" wire:navigate class="min-w-0 flex-1 truncate text-gray-900 hover:underline dark:text-gray-100">{{ $kind['icon'] }} {{ \App\Support\Sharing::titleOf($share->shareable) }} <span class="text-xs text-gray-500 dark:text-gray-400">· {{ $kind['label'] }}</span></a>
                            <span class="rounded-full bg-sky-50 px-2 py-0.5 text-xs text-sky-800 dark:bg-sky-900/30 dark:text-sky-200">👥 {{ $share->group->name }}</span>
                            <button type="button" wire:click="unshare({{ $share->id }})" class="text-xs text-red-600 underline dark:text-red-400">{{ __('Stop sharing') }}</button>
                        </li>
                    @empty
                        <li class="py-2 text-sm text-gray-500 dark:text-gray-400">{{ __('Nothing – all your contents stay in your household or private.') }}</li>
                    @endforelse
                </ul>
            </section>

            <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Shared with me') }}</h3>
                <ul class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse ($sharedWithMe as $share)
                        @php($kind = \App\Support\Sharing::describe($share->shareable))
                        <li wire:key="with-me-{{ $share->id }}" class="flex flex-wrap items-center gap-2 py-2 text-sm">
                            <a href="{{ \App\Support\Sharing::url($share->shareable, $householdId) }}" wire:navigate class="min-w-0 flex-1 truncate text-gray-900 hover:underline dark:text-gray-100">{{ $kind['icon'] }} {{ \App\Support\Sharing::titleOf($share->shareable) }} <span class="text-xs text-gray-500 dark:text-gray-400">· {{ $kind['label'] }}</span></a>
                            <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('from :name via :group', ['name' => $share->sharer?->name ?? '–', 'group' => $share->group->name]) }}</span>
                        </li>
                    @empty
                        <li class="py-2 text-sm text-gray-500 dark:text-gray-400">{{ __('Nothing has been shared with you yet.') }}</li>
                    @endforelse
                </ul>
            </section>
        </div>
    </div>
</div>
