<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Item;
use App\Support\InboxActions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

/**
 * An inbox entry opened in place (e.g. from the overview): read it, fix the title and sort it
 * into a task, a page or the shopping list without leaving the page.
 * Opened by links with data-open-inbox="5" (see app.js); confirms with inbox-sheet-shown.
 */
new class extends Component
{
    use InteractsWithHousehold;

    public ?int $entryId = null;

    protected function requiresHousehold(): bool
    {
        return false;
    }

    private function entry(): Item
    {
        abort_if($this->householdId === null || $this->entryId === null, 404);

        return $this->visibleItems(Item::TYPE_INBOX)->find($this->entryId) ?? abort(404);
    }

    #[On('open-inbox')]
    public function open(int $id): void
    {
        $this->entryId = $id;
        $this->entry();
        $this->dispatch('inbox-sheet-shown', id: $id);
    }

    public function close(): void
    {
        $this->entryId = null;
    }

    public function updateTitle(string $title): void
    {
        $title = trim($title);

        if ($title !== '') {
            $this->entry()->update(['title' => mb_substr($title, 0, 255)]);
            $this->dispatch('inbox-updated');
        }
    }

    /**
     * Turns the entry into a task and opens it right away in the task window.
     */
    public function toTask(): void
    {
        $entry = $this->entry();
        InboxActions::toTask($entry);
        $this->entryId = null;
        $this->dispatch('inbox-updated');
        $this->dispatch('task-updated');
        $this->dispatch('open-task', id: $entry->id);
    }

    public function toPage(): void
    {
        $entry = $this->entry();
        InboxActions::toPage($entry);

        $this->redirectRoute('pages.index', ['item' => $entry->id], navigate: true);
    }

    public function toShoppingList(): void
    {
        $entry = $this->entry();
        abort_if($entry->isPrivate(), 403);
        InboxActions::toShoppingList($entry, (int) Auth::id());
        $this->entryId = null;
        $this->dispatch('inbox-updated');
        $this->dispatch('toast', message: __('On the shopping list: :title', ['title' => Str::limit($entry->title, 40)]));
    }

    public function delete(): void
    {
        $this->entry()->delete();
        $this->entryId = null;
        $this->dispatch('inbox-updated');
    }

    public function with(): array
    {
        return ['entry' => $this->entryId !== null && $this->householdId !== null ? $this->entry()->load('creator', 'tags') : null];
    }
}; ?>

<div>
    @if ($entry)
        <x-sheet close="close" :label="$entry->title" size="md" wire:key="quick-inbox-{{ $entry->id }}">
            <x-slot:header>
                <span aria-hidden="true">📥</span>
                <span class="truncate">{{ __('Inbox') }}</span>
            </x-slot:header>

            <div class="space-y-4">
                <textarea rows="2" wire:key="quick-inbox-title-{{ $entry->id }}-{{ md5($entry->title) }}"
                    x-on:change="$wire.updateTitle($event.target.value)"
                    x-on:keydown.enter.prevent="$event.target.blur()"
                    class="w-full resize-none border-0 bg-transparent p-0 text-lg font-semibold text-gray-900 focus:ring-0 dark:text-gray-100"
                    aria-label="{{ __('Title') }}">{{ $entry->title }}</textarea>

                <p class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                    {{ $entry->created_at->diffForHumans() }}
                    @if ($entry->creator)
                        · {{ $entry->creator->name }}
                    @endif
                    @if ($entry->isPrivate())
                        <x-private-badge />
                    @endif
                </p>

                @if ($entry->body() !== '')
                    <p class="whitespace-pre-line break-words rounded-lg bg-gray-50 p-3 text-sm text-gray-700 dark:bg-gray-900 dark:text-gray-300">{{ $entry->body() }}</p>
                @endif

                @if ($entry->tags->isNotEmpty())
                    <p class="flex flex-wrap gap-1">
                        @foreach ($entry->tags->sortBy('name') as $entryTag)
                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600 dark:bg-gray-700 dark:text-gray-300">#{{ $entryTag->name }}</span>
                        @endforeach
                    </p>
                @endif

                <div>
                    <h4 class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-gray-400">{{ __('Sort into') }}</h4>
                    <div class="grid gap-2 sm:grid-cols-3">
                        <button type="button" wire:click="toTask" class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">✅ {{ __('As task') }}</button>
                        <button type="button" wire:click="toPage" class="rounded-lg bg-gray-100 px-3 py-2 text-sm font-medium text-gray-800 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-100 dark:hover:bg-gray-600">📄 {{ __('As page') }}</button>
                        @unless ($entry->isPrivate())
                            <button type="button" wire:click="toShoppingList" class="rounded-lg bg-gray-100 px-3 py-2 text-sm font-medium text-gray-800 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-100 dark:hover:bg-gray-600">🛒 {{ __('To shopping list') }}</button>
                        @endunless
                    </div>
                </div>

                {{-- Link the entry to pages, tasks or other items (and show what links here). --}}
                <div class="border-t border-gray-100 pt-3 dark:border-gray-700">
                    <livewire:links-panel type="item" :id="$entry->id" :key="'inbox-links-'.$entry->id" />
                </div>

                <a href="{{ route('inbox.index') }}" wire:navigate class="inline-block text-sm text-indigo-600 hover:underline dark:text-indigo-400">{{ __('Open inbox') }} →</a>
            </div>

            <x-slot:footer>
                <button type="button" wire:click="delete" wire:confirm="{{ __('Delete this entry?') }}" class="rounded-lg px-4 py-2 text-sm font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/30">{{ __('Delete') }}</button>
                <x-secondary-button type="button" wire:click="close">{{ __('Close') }}</x-secondary-button>
            </x-slot:footer>
        </x-sheet>
    @endif
</div>
