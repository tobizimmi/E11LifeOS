<?php

use App\Models\ShareGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

/**
 * Joining a group with its invite link – shows who is in it before anything is shared.
 */
new #[Layout('layouts.app')] class extends Component
{
    #[Locked]
    public string $token = '';

    public function mount(string $token): void
    {
        $this->token = $token;
        $group = $this->group();

        if ($group->hasMember(Auth::user())) {
            abort(new RedirectResponse(route('groups.index')));
        }
    }

    private function group(): ShareGroup
    {
        $group = ShareGroup::query()->whereShareToken('invite_token', $this->token, 'invite_expires_at')->first();

        return $group ?? abort(404);
    }

    public function join(): void
    {
        $group = $this->group();
        $group->members()->firstOrCreate(['user_id' => Auth::id()], ['role' => ShareGroup::ROLE_MEMBER]);
        session()->flash('status', __('You are now a member of :group.', ['group' => $group->name]));
        $this->redirectRoute('groups.index', navigate: true);
    }

    public function with(): array
    {
        return ['group' => $this->group()->load('members.user')];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">👥 {{ __('Join group') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-lg px-4 sm:px-6 lg:px-8">
            <section class="rounded-2xl bg-white p-6 text-center shadow-sm dark:bg-gray-800">
                <p class="text-4xl" aria-hidden="true">{{ $group->icon ?: '👥' }}</p>
                <h3 class="mt-2 text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $group->name }}</h3>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __('Members: :names', ['names' => $group->members->map(fn ($member) => $member->user->name)->implode(', ')]) }}</p>
                <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ __('As a member you see the trips, pages and cost splits the others share with this group. Your own contents stay private or in your household until you share them with the group yourself.') }}</p>
                <x-primary-button type="button" wire:click="join" class="mt-5">{{ __('Join group') }}</x-primary-button>
            </section>
        </div>
    </div>
</div>
