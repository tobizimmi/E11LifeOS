<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Item;
use App\Models\ShoppingListEntry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    /**
     * @var array<string, string>
     */
    protected $listeners = ['item-captured' => '$refresh'];

    public function convertToTodo(int $id): void
    {
        $item = $this->findVisibleItem(Item::TYPE_INBOX, $id);

        $maxPosition = (int) $this->visibleItems(Item::TYPE_TODO)->max('position');

        $item->update([
            'type' => Item::TYPE_TODO,
            'content' => null,
            'done' => false,
            'position' => $maxPosition + 1,
        ]);
    }

    /**
     * Turns the entry into a page and opens it, keeping its visibility.
     */
    public function convertToNote(int $id): void
    {
        $item = $this->findVisibleItem(Item::TYPE_INBOX, $id);

        $item->update(['type' => Item::TYPE_NOTE, 'content' => ['body' => '']]);

        $this->redirectRoute('pages.index', ['item' => $item->id], navigate: true);
    }

    /**
     * Moves the entry onto the (always shared) shopping list.
     */
    public function moveToShoppingList(int $id): void
    {
        $item = $this->findVisibleItem(Item::TYPE_INBOX, $id);

        DB::transaction(function () use ($item) {
            ShoppingListEntry::create([
                'household_id' => $this->householdId,
                'label' => $item->title,
                'created_by' => Auth::id(),
            ]);

            $item->delete();
        });
    }

    public function delete(int $id): void
    {
        $this->findVisibleItem(Item::TYPE_INBOX, $id)->delete();
    }

    public function with(): array
    {
        return [
            'entries' => $this->visibleItems(Item::TYPE_INBOX)
                ->with('creator', 'tags')
                ->latest()
                ->get(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            📥 {{ __('Inbox') }}
        </h2>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <div class="bg-white dark:bg-gray-800 shadow-sm rounded-2xl p-6">
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                    {{ __('Capture first, sort later: turn each entry into a task, a note or a shopping list item.') }}
                </p>
                <livewire:quick-capture />
            </div>

            @if (auth()->user()->hasPaperless())
                <livewire:paperless-inbox lazy />
            @endif

            <div class="bg-white dark:bg-gray-800 shadow-sm rounded-2xl p-6">
                <ul class="space-y-3">
                    @forelse ($entries as $entry)
                        <li wire:key="inbox-{{ $entry->id }}" class="border border-gray-200 dark:border-gray-700 rounded-md p-3">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-gray-900 dark:text-gray-100 break-words">{{ $entry->title }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 flex items-center gap-2">
                                        {{ $entry->created_at->diffForHumans() }}
                                        @if ($entry->creator)
                                            · {{ $entry->creator->name }}
                                        @endif
                                        @if ($entry->isPrivate())
                                            <x-private-badge />
                                        @endif
                                    </p>
                                    @if ($entry->tags->isNotEmpty())
                                        <p class="mt-1 flex flex-wrap gap-1">
                                            @foreach ($entry->tags->sortBy('name') as $entryTag)
                                                <span class="rounded-full bg-gray-100 dark:bg-gray-700 px-2 py-0.5 text-xs text-gray-600 dark:text-gray-300">#{{ $entryTag->name }}</span>
                                            @endforeach
                                        </p>
                                    @endif
                                </div>
                                <button type="button" wire:click="delete({{ $entry->id }})" wire:confirm="{{ __('Delete this entry?') }}" class="text-sm text-red-600 dark:text-red-400 underline shrink-0">
                                    {{ __('Delete') }}
                                </button>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2 text-sm">
                                <x-secondary-button type="button" wire:click="convertToTodo({{ $entry->id }})">{{ __('As task') }}</x-secondary-button>
                                <x-secondary-button type="button" wire:click="convertToNote({{ $entry->id }})">{{ __('As page') }}</x-secondary-button>
                                @unless ($entry->isPrivate())
                                    <x-secondary-button type="button" wire:click="moveToShoppingList({{ $entry->id }})">{{ __('To shopping list') }}</x-secondary-button>
                                @endunless
                            </div>
                        </li>
                    @empty
                        <li class="text-gray-500 dark:text-gray-400 text-sm">{{ __('Your inbox is empty.') }}</li>
                    @endforelse
                </ul>
            </div>

        </div>
    </div>
</div>
