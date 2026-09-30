<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\ExpenseGroup;
use App\Models\Item;
use App\Models\ShareGroup;
use App\Models\Trip;
use App\Support\Sharing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

/**
 * "Who sees this?" – the audience of one content (only me, household, groups) and, for its
 * creator, the switches to share it with their groups beyond the household.
 */
new class extends Component
{
    use InteractsWithHousehold;

    #[Locked]
    public string $type = Sharing::TRIP;

    #[Locked]
    public int $modelId;

    public function mount(string $type, int $id): void
    {
        abort_unless(in_array($type, Sharing::TYPES, true), 404);
        $this->resolveHousehold();
        $this->type = $type;
        $this->modelId = $this->content($id)->id;
    }

    /**
     * @return Trip|ExpenseGroup|Item
     */
    private function content(?int $id = null): Model
    {
        return Sharing::find($this->type, $id ?? $this->modelId, Auth::user(), (int) $this->householdId) ?? abort(404);
    }

    public function toggle(int $groupId): void
    {
        $content = $this->content();
        abort_unless($content->canManageSharing(Auth::user()), 403);
        $group = ShareGroup::query()->ofMember(Auth::user())->findOrFail($groupId);

        if ($content->shareGroups()->whereKey($group->id)->exists()) {
            $content->shares()->where('share_group_id', $group->id)->delete();
            $this->dispatch('toast', message: __('No longer shared with :group.', ['group' => $group->name]));
        } else {
            $content->shares()->create(['share_group_id' => $group->id, 'shared_by' => Auth::id()]);
            $this->dispatch('toast', message: __('Shared with :group.', ['group' => $group->name]));
        }
    }

    public function with(): array
    {
        $content = $this->content();
        $content->load('shareGroups', 'creator');
        $canManage = $content->canManageSharing(Auth::user());

        return [
            'content' => $content,
            'canManage' => $canManage,
            'groups' => $canManage ? ShareGroup::query()->ofMember(Auth::user())->withCount('members')->orderBy('name')->get() : collect(),
            'sharedIds' => $content->shareGroups->pluck('id')->all(),
            'creator' => $canManage ? null : $content->creator?->name,
            'foreign' => (int) $content->household_id !== (int) $this->householdId,
        ];
    }
}; ?>

<section class="rounded-2xl bg-white p-4 shadow-sm dark:bg-gray-800" aria-label="{{ __('Who sees this?') }}">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('Who sees this?') }}</h3>
        <x-audience-badge :model="$content" />
    </div>

    <ul class="mt-2 space-y-1 text-sm text-gray-600 dark:text-gray-300">
        @if ($foreign)
            <li>↪ {{ __('From another household: :name shared it with you via :groups.', ['name' => $creator ?? __('someone'), 'groups' => $content->shareGroups->pluck('name')->implode(', ')]) }}</li>
            <li>{{ $content->visibility === \App\Models\Item::VISIBILITY_PRIVATE ? '🔒 '.__('Not visible to their household.') : '🏠 '.__('Their household sees it too.') }}</li>
        @else
            <li>{{ $content->visibility === \App\Models\Item::VISIBILITY_PRIVATE ? '🔒 '.__('Not your household – private.') : '🏠 '.__('Everyone in your household.') }}</li>
            @if ($content->shareGroups->isNotEmpty())
                <li>👥 {{ __('Groups outside the household: :groups', ['groups' => $content->shareGroups->pluck('name')->implode(', ')]) }}</li>
            @endif
        @endif
    </ul>

    @if ($canManage)
        @if ($groups->isEmpty())
            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">{{ __('To share with people outside your household, create a group first.') }} <a href="{{ route('groups.index') }}" wire:navigate class="text-indigo-600 underline dark:text-indigo-400">{{ __('Groups') }}</a></p>
        @else
            <fieldset class="mt-3">
                <legend class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Share with groups') }}</legend>
                <div class="mt-1 flex flex-wrap gap-2">
                    @foreach ($groups as $group)
                        <label wire:key="share-group-{{ $group->id }}" class="inline-flex cursor-pointer items-center gap-2 rounded-full px-3 py-1 text-sm ring-1 {{ in_array($group->id, $sharedIds, true) ? 'bg-sky-50 text-sky-900 ring-sky-300 dark:bg-sky-900/30 dark:text-sky-100 dark:ring-sky-700' : 'text-gray-700 ring-gray-200 dark:text-gray-200 dark:ring-gray-700' }}">
                            <input type="checkbox" class="rounded border-gray-300 text-sky-600" @checked(in_array($group->id, $sharedIds, true)) wire:click="toggle({{ $group->id }})">
                            {{ $group->icon }} {{ $group->name }} <span class="text-xs text-gray-500 dark:text-gray-400">({{ trans_choice(':count person|:count people', $group->members_count) }})</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Group members can view and edit it, but not delete it or share it further.') }}</p>
        @endif
    @elseif ($content->shareGroups->isNotEmpty())
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Only :name can change who sees it. You can view and edit it, but not delete it.', ['name' => $creator ?? __('someone')]) }}</p>
    @endif
</section>
