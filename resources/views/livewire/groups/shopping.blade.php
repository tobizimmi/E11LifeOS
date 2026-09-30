<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\ShoppingList;
use App\Models\ShoppingListEntry;
use App\Support\Sharing;
use App\Support\ShoppingLists;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

/**
 * A shopping list another household shared with one of my groups: tick items off and add new ones.
 */
new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    #[Locked]
    public int $listId;

    public string $label = '';

    public function mount(ShoppingList $list): void
    {
        $this->resolveHousehold();
        $found = $this->list($list->id);

        // Lists of the own household open in the normal shopping list.
        if ($found->household_id === $this->householdId) {
            abort(new RedirectResponse(route('shopping-list.index', ['list' => $found->id])));
        }

        $this->listId = $found->id;
    }

    private function list(?int $id = null): ShoppingList
    {
        $list = Sharing::find(Sharing::SHOPPING, $id ?? $this->listId, Auth::user(), (int) $this->householdId);

        return $list instanceof ShoppingList ? $list : abort(404);
    }

    public function toggle(int $id): void
    {
        $entry = ShoppingListEntry::where('shopping_list_id', $this->list()->id)->find($id) ?? abort(404);
        $entry->update(['checked' => ! $entry->checked]);
    }

    public function add(): void
    {
        $data = $this->validate(['label' => ['required', 'string', 'max:255']], attributes: ['label' => __('Item')]);
        ShoppingLists::add($this->list(), $data['label'], null, Auth::id());
        $this->reset('label');
    }

    public function with(): array
    {
        $list = $this->list()->load('household');
        $entries = ShoppingListEntry::where('shopping_list_id', $list->id)->orderBy('checked')->orderBy('label')->get();

        return [
            'list' => $list,
            'open' => $entries->where('checked', false)->values(),
            'done' => $entries->where('checked', true)->values(),
        ];
    }
}; ?>

<div wire:poll.15s>
    <x-slot name="header">
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('groups.index') }}" wire:navigate class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">← {{ __('Groups & sharing') }}</a>
            <h2 class="w-full font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ $list->icon }} {{ $list->name }} <span class="text-sm font-normal text-gray-500">· {{ $list->household?->name }}</span></h2>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-3xl space-y-4 px-4 sm:px-6 lg:px-8">
            <livewire:sharing.panel type="shopping" :id="$list->id" :wire:key="'share-shopping-'.$list->id" />

            <section class="rounded-2xl bg-white p-4 shadow-sm dark:bg-gray-800">
                <form wire:submit="add" class="flex gap-2">
                    <x-text-input wire:model="label" class="block w-full" type="text" placeholder="{{ __('What do you need?') }}" aria-label="{{ __('Item') }}" />
                    <x-primary-button type="submit">{{ __('Add') }}</x-primary-button>
                </form>
                <x-input-error :messages="$errors->get('label')" class="mt-1" />

                <ul class="mt-4 space-y-1.5">
                    @forelse ($open->concat($done) as $entry)
                        <li wire:key="entry-{{ $entry->id }}">
                            <label class="flex cursor-pointer items-center gap-3 rounded-md bg-gray-50 px-3 py-2 dark:bg-gray-900">
                                <input type="checkbox" wire:click="toggle({{ $entry->id }})" @checked($entry->checked) class="h-5 w-5 rounded border-gray-300 text-indigo-600">
                                <span @class(['flex-1', 'text-gray-400 line-through' => $entry->checked, 'text-gray-900 dark:text-gray-100' => ! $entry->checked])>{{ $entry->label }}</span>
                                @if ($entry->quantity)
                                    <span class="text-sm text-gray-500 dark:text-gray-400">{{ $entry->quantity }}</span>
                                @endif
                            </label>
                        </li>
                    @empty
                        <li class="text-sm text-gray-500 dark:text-gray-400">{{ __('Nothing to buy right now.') }}</li>
                    @endforelse
                </ul>
            </section>
        </div>
    </div>
</div>
