<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Item;
use App\Models\ShoppingListEntry;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    private const LIST_LIMIT = 5;

    /**
     * @var array<string, string>
     */
    protected $listeners = ['item-captured' => '$refresh'];

    /**
     * Users without a household still get the dashboard, just with a hint.
     */
    protected function requiresHousehold(): bool
    {
        return false;
    }

    public function toggleTodo(int $id): void
    {
        $todo = $this->findVisibleItem(Item::TYPE_TODO, $id);

        $todo->update(['done' => ! $todo->done]);
    }

    public function with(): array
    {
        $hour = now()->hour;

        $data = [
            'greeting' => match (true) {
                $hour < 11 => __('Good morning'),
                $hour < 18 => __('Hello'),
                default => __('Good evening'),
            },
        ];

        if ($this->householdId === null) {
            return $data;
        }

        $openTodos = $this->visibleItems(Item::TYPE_TODO)->where('done', false);
        $openShopping = ShoppingListEntry::where('household_id', $this->householdId)->where('checked', false);

        return $data + [
            'openTodoCount' => (clone $openTodos)->count(),
            'openTodos' => $openTodos->orderBy('position')->limit(self::LIST_LIMIT)->get(),
            'recentNotes' => $this->visibleItems(Item::TYPE_NOTE)->latest('updated_at')->limit(self::LIST_LIMIT)->get(),
            'openShoppingCount' => (clone $openShopping)->count(),
            'openShopping' => $openShopping->latest()->limit(self::LIST_LIMIT)->get(),
            'inboxCount' => $this->visibleItems(Item::TYPE_INBOX)->count(),
            'inboxEntries' => $this->visibleItems(Item::TYPE_INBOX)->latest()->limit(3)->get(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ $greeting }}, {{ Auth::user()->name }}
        </h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ now()->translatedFormat('l, j. F Y') }}</p>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if ($householdId === null)
                <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 text-gray-700 dark:text-gray-300">
                    {{ __('You are not a member of a household yet. Ask a household member for an invite code.') }}
                </div>
            @else
                <section class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6">
                    <div class="flex items-center justify-between gap-3 mb-3">
                        <h3 class="font-medium text-gray-900 dark:text-gray-100">{{ __('Quick capture') }}</h3>
                        <a href="{{ route('inbox.index') }}" wire:navigate class="text-sm text-indigo-600 dark:text-indigo-400 underline">
                            {{ trans_choice(':count entry in the inbox|:count entries in the inbox', $inboxCount) }}
                        </a>
                    </div>
                    <livewire:quick-capture />
                </section>

                <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                    {{-- Tasks --}}
                    <section class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6">
                        <x-dashboard-card-header :title="__('Open tasks')" :count="$openTodoCount" :href="route('todos.index')" />
                        <ul class="mt-3 space-y-2">
                            @forelse ($openTodos as $todo)
                                <li wire:key="dashboard-todo-{{ $todo->id }}" class="flex items-center gap-3">
                                    <input
                                        type="checkbox"
                                        wire:click="toggleTodo({{ $todo->id }})"
                                        aria-label="{{ __('Mark as done') }}"
                                        class="rounded border-gray-300 dark:border-gray-600 text-indigo-600 shadow-sm focus:ring-indigo-500"
                                    />
                                    <span class="text-gray-900 dark:text-gray-100 truncate">{{ $todo->title }}</span>
                                    @if ($todo->isPrivate())
                                        <x-private-badge />
                                    @endif
                                </li>
                            @empty
                                <li class="text-sm text-gray-500 dark:text-gray-400">{{ __('All done!') }}</li>
                            @endforelse
                        </ul>
                    </section>

                    {{-- Shopping list --}}
                    <section class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6">
                        <x-dashboard-card-header :title="__('Shopping List')" :count="$openShoppingCount" :href="route('shopping-list.index')" />
                        <ul class="mt-3 space-y-1">
                            @forelse ($openShopping as $entry)
                                <li wire:key="dashboard-shopping-{{ $entry->id }}" class="text-gray-900 dark:text-gray-100">
                                    {{ $entry->label }}
                                    @if ($entry->quantity)
                                        <span class="text-sm text-gray-500 dark:text-gray-400">({{ $entry->quantity }})</span>
                                    @endif
                                </li>
                            @empty
                                <li class="text-sm text-gray-500 dark:text-gray-400">{{ __('Nothing to buy.') }}</li>
                            @endforelse
                        </ul>
                    </section>

                    {{-- Notes --}}
                    <section class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6">
                        <x-dashboard-card-header :title="__('Recent notes')" :href="route('notes.index')" />
                        <ul class="mt-3 space-y-2">
                            @forelse ($recentNotes as $note)
                                <li wire:key="dashboard-note-{{ $note->id }}" class="flex items-center gap-2">
                                    <a href="{{ route('notes.index', ['note' => $note->id]) }}" wire:navigate class="text-indigo-600 dark:text-indigo-400 hover:underline truncate">
                                        {{ $note->title }}
                                    </a>
                                    @if ($note->isPrivate())
                                        <x-private-badge />
                                    @endif
                                </li>
                            @empty
                                <li class="text-sm text-gray-500 dark:text-gray-400">{{ __('No notes yet.') }}</li>
                            @endforelse
                        </ul>
                    </section>

                    {{-- Inbox --}}
                    @if ($inboxCount > 0)
                        <section class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 md:col-span-2 xl:col-span-3">
                            <x-dashboard-card-header :title="__('Inbox')" :count="$inboxCount" :href="route('inbox.index')" />
                            <ul class="mt-3 space-y-1">
                                @foreach ($inboxEntries as $entry)
                                    <li wire:key="dashboard-inbox-{{ $entry->id }}" class="text-gray-900 dark:text-gray-100 truncate">{{ $entry->title }}</li>
                                @endforeach
                            </ul>
                        </section>
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>
