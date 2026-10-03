<?php

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\ShareGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

/**
 * Joining a group with its invite link – alone or, for the owner of a household, with the whole
 * household. Shows who is in it before anything is shared.
 */
new #[Layout('layouts.app')] class extends Component
{
    #[Locked]
    public string $token = '';

    public function mount(string $token): void
    {
        $this->token = $token;
        $group = $this->group();

        if ($group->hasMember(Auth::user()) && $this->joinableHousehold($group) === null) {
            abort(new RedirectResponse(route('groups.index')));
        }
    }

    private function group(): ShareGroup
    {
        $group = ShareGroup::query()->whereShareToken('invite_token', $this->token, 'invite_expires_at')->first();

        return $group ?? abort(404);
    }

    /**
     * The own household, when the user owns it and it is not in the group yet.
     */
    private function joinableHousehold(ShareGroup $group): ?Household
    {
        $household = Auth::user()->currentHousehold();
        $isOwner = $household && HouseholdMember::query()->where('household_id', $household->id)->where('user_id', Auth::id())->where('role', 'owner')->exists();

        return $isOwner && ! $group->households()->whereKey($household->id)->exists() ? $household : null;
    }

    public function join(): void
    {
        $group = $this->group();
        $group->members()->firstOrCreate(['user_id' => Auth::id()], ['role' => ShareGroup::ROLE_MEMBER]);
        session()->flash('status', __('You are now a member of :group.', ['group' => $group->name]));
        $this->redirectRoute('groups.index', navigate: true);
    }

    public function joinWithHousehold(): void
    {
        $group = $this->group();
        $household = $this->joinableHousehold($group) ?? abort(403);
        $group->households()->attach($household->id, ['added_by' => Auth::id()]);
        session()->flash('status', __('Your household :household is now in :group – everyone in it, also people joining later.', ['household' => $household->name, 'group' => $group->name]));
        $this->redirectRoute('groups.index', navigate: true);
    }

    public function with(): array
    {
        $group = $this->group()->load('members.user', 'households');
        $household = $this->joinableHousehold($group);

        return [
            'group' => $group,
            'household' => $household,
            'householdSize' => $household ? HouseholdMember::where('household_id', $household->id)->count() : 0,
            'isMember' => $group->hasMember(Auth::user()),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Join group') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-lg px-4 sm:px-6 lg:px-8">
            <section class="rounded-2xl bg-white p-6 text-center shadow-xs dark:bg-gray-800">
                <p class="text-4xl" aria-hidden="true">{{ $group->icon ?: '👥' }}</p>
                <h3 class="mt-2 text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $group->name }}</h3>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __('Members: :names', ['names' => $group->members->map(fn ($member) => $member->user->name)->implode(', ')]) }}</p>
                @if ($group->households->isNotEmpty())
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __('Households: :names', ['names' => $group->households->pluck('name')->implode(', ')]) }}</p>
                @endif
                <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ __('As a member you see what the others share with this group – trips, pages, cost splits, recipes, shopping lists, places or collections. Your own contents stay private or in your household until you share them with the group yourself.') }}</p>
                <div class="mt-5 flex flex-col items-center gap-3">
                    @unless ($isMember)
                        <x-primary-button type="button" wire:click="join">{{ __('Join group') }}</x-primary-button>
                    @endunless
                    @if ($household)
                        <x-secondary-button type="button" wire:click="joinWithHousehold">🏠 {{ __('Join with the whole household “:name”', ['name' => $household->name]) }}</x-secondary-button>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ trans_choice('Everyone in your household becomes a member – also everyone who joins it later. Your household contents still stay private until someone shares them.|All :count people in your household become members – also everyone who joins it later. Your household contents still stay private until someone shares them.', $householdSize) }}</p>
                    @endif
                </div>
            </section>
        </div>
    </div>
</div>
