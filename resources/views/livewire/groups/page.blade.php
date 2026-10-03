<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Item;
use App\Support\NoteMarkdown;
use App\Support\Sharing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

/**
 * A page another household shared with one of my groups: read it and edit its text. Links,
 * embeds and subpages of the other household are not part of the share.
 */
new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    #[Locked]
    public int $pageId;

    public bool $editing = false;

    public string $title = '';

    public string $body = '';

    public function mount(Item $item): void
    {
        $this->resolveHousehold();
        $page = $this->page($item->id);

        // Pages of the own household open in the normal page view.
        if ($page->household_id === $this->householdId) {
            abort(new RedirectResponse(route('pages.index', ['item' => $page->id])));
        }

        $this->pageId = $page->id;
    }

    private function page(?int $id = null): Item
    {
        $page = Sharing::find(Sharing::PAGE, $id ?? $this->pageId, Auth::user(), (int) $this->householdId);

        return $page instanceof Item ? $page : abort(404);
    }

    public function edit(): void
    {
        $page = $this->page();
        $this->title = $page->title;
        $this->body = $page->body();
        $this->editing = true;
    }

    public function save(): void
    {
        $data = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:100000'],
        ], attributes: ['title' => __('Title')]);

        $page = $this->page();
        $page->update(['title' => $data['title'], 'content' => array_merge($page->content ?? [], ['body' => (string) $data['body']])]);
        $this->editing = false;
        $this->dispatch('toast', message: __('Saved.'));
    }

    public function with(): array
    {
        $page = $this->page();

        return [
            'page' => $page,
            'html' => NoteMarkdown::plainToHtml($page->body()),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('groups.index') }}" wire:navigate class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">← {{ __('Groups & sharing') }}</a>
            <h2 class="w-full font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ $page->icon ?: \App\Models\Item::DEFAULT_PAGE_ICON }} {{ $page->title }}</h2>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-4xl space-y-4 px-4 sm:px-6 lg:px-8">
            <livewire:sharing.panel type="page" :id="$page->id" :wire:key="'share-page-'.$page->id" />

            <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                @if ($editing)
                    <form wire:submit="save" class="space-y-3">
                        <label class="block text-sm text-gray-700 dark:text-gray-300">{{ __('Title') }}<input type="text" wire:model="title" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"><x-input-error :messages="$errors->get('title')" /></label>
                        <label class="block text-sm text-gray-700 dark:text-gray-300">{{ __('Text (Markdown)') }}<textarea wire:model="body" rows="16" class="mt-1 block w-full rounded-md border-gray-300 font-mono text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"></textarea></label>
                        <div class="flex gap-2">
                            <x-primary-button>{{ __('Save') }}</x-primary-button>
                            <x-secondary-button type="button" wire:click="$set('editing', false)">{{ __('Cancel') }}</x-secondary-button>
                        </div>
                    </form>
                @else
                    <div class="flex justify-end"><button type="button" wire:click="edit" class="text-sm text-indigo-600 underline dark:text-indigo-400">{{ __('Edit') }}</button></div>
                    <div class="prose prose-sm max-w-none dark:prose-invert">{!! $html !!}</div>
                @endif
            </section>
        </div>
    </div>
</div>
