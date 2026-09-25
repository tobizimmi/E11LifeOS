<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Item;
use App\Models\Tag;
use App\Support\NoteMarkdown;
use App\Support\PageTree;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $tag = '';

    /**
     * The open page (/pages/{item}); null shows the overview of all pages.
     */
    #[Locked]
    public ?int $selectedId = null;

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $title = '';

    public string $icon = '';

    public ?int $parentId = null;

    public string $body = '';

    public string $tagsInput = '';

    public bool $isPrivate = false;

    /**
     * Also reached via ?create=1&parent=ID&private=1 (sidebar "+") and
     * ?new=Title (wiki link to a page that does not exist yet).
     */
    public function mount(?int $item = null): void
    {
        $this->resolveHousehold();

        if ($item !== null) {
            $this->selectedId = $this->findVisibleItem(Item::TYPE_NOTE, $item)->id;
        }

        $newTitle = request()->query('new');
        $newTitle = is_string($newTitle) ? trim($newTitle) : '';

        if ($newTitle !== '' || request()->query('create') === '1') {
            $parent = request()->query('parent');

            if (is_string($parent) && ctype_digit($parent)) {
                $this->createChild((int) $parent);
            } else {
                $this->create();
                $this->isPrivate = request()->query('private') === '1';
            }

            $this->title = mb_substr($newTitle, 0, 255);
        }
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    /**
     * New subpage; it starts with the parent's visibility.
     */
    public function createChild(int $parentId): void
    {
        $parent = $this->findVisibleItem(Item::TYPE_NOTE, $parentId);

        $this->create();
        $this->parentId = $parent->id;
        $this->isPrivate = $parent->isPrivate();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:16'],
            'parentId' => ['nullable', 'integer'],
            'body' => ['nullable', 'string', 'max:100000'],
            'tagsInput' => ['nullable', 'string', 'max:1000'],
        ]);

        if (! $this->parentIsAllowed($validated['parentId'] ?? null)) {
            $this->addError('parentId', __('This page cannot be moved there.'));

            return;
        }

        $attributes = [
            'title' => $validated['title'],
            'icon' => trim((string) ($validated['icon'] ?? '')) ?: null,
            'parent_id' => $validated['parentId'] ?? null,
            'content' => ['body' => $validated['body'] ?? ''],
        ];
        $visibility = $this->isPrivate ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED;

        if ($this->editingId) {
            $page = $this->findVisibleItem(Item::TYPE_NOTE, $this->editingId);

            if ($page->visibilityCanBeChangedBy(Auth::user())) {
                $attributes['visibility'] = $visibility;
            }

            $page->update($attributes);
        } else {
            $page = Item::create($attributes + [
                'household_id' => $this->householdId,
                'type' => Item::TYPE_NOTE,
                'visibility' => $visibility,
                'created_by' => Auth::id(),
            ]);
        }

        $page->syncTagNames(explode(',', $this->tagsInput));

        // A full navigation also refreshes the page tree in the sidebar.
        $this->redirectRoute('pages.index', ['item' => $page->id], navigate: true);
    }

    /**
     * The parent must be a page the user can see, and a page may not become
     * its own ancestor. Descendants are computed over all household pages, so
     * a cycle cannot be built through someone else's private page either.
     */
    private function parentIsAllowed(?int $parentId): bool
    {
        if ($parentId === null) {
            return true;
        }

        if (! $this->visibleItems(Item::TYPE_NOTE)->whereKey($parentId)->exists()) {
            return false;
        }

        if ($this->editingId === null) {
            return true;
        }

        $allPages = new PageTree(
            Item::query()->forHousehold($this->householdId)->ofType(Item::TYPE_NOTE)->get(['id', 'parent_id', 'title'])
        );

        return $parentId !== $this->editingId
            && ! in_array($parentId, $allPages->descendantIds($this->editingId), true);
    }

    public function edit(int $id): void
    {
        $page = $this->findVisibleItem(Item::TYPE_NOTE, $id);

        $this->resetForm();
        $this->editingId = $page->id;
        $this->title = $page->title;
        $this->icon = (string) $page->icon;
        $this->parentId = $page->parent_id;
        $this->body = $page->body();
        $this->tagsInput = $page->tags->sortBy('name')->pluck('name')->implode(', ');
        $this->isPrivate = $page->isPrivate();
        $this->showForm = true;
    }

    public function delete(int $id): void
    {
        $page = $this->findVisibleItem(Item::TYPE_NOTE, $id);
        $parentId = $page->parent_id;

        $page->delete();

        $parentStillVisible = $parentId && $this->visibleItems(Item::TYPE_NOTE)->whereKey($parentId)->exists();

        $this->redirectRoute('pages.index', $parentStillVisible ? ['item' => $parentId] : [], navigate: true);
    }

    /**
     * Ticks or unticks the n-th checklist item of the open page (read view).
     */
    public function toggleTask(int $index): void
    {
        $page = $this->findVisibleItem(Item::TYPE_NOTE, (int) $this->selectedId);
        $body = NoteMarkdown::toggleTask($page->body(), $index);

        if ($body !== null) {
            $page->update(['content' => ['body' => $body]]);
        }
    }

    public function cancelEdit(): void
    {
        $this->resetForm();
    }

    public function filterByTag(string $name): void
    {
        $this->tag = $this->tag === $name ? '' : $name;
    }

    private function resetForm(): void
    {
        $this->reset(['title', 'icon', 'parentId', 'body', 'tagsInput', 'isPrivate', 'editingId', 'showForm']);
        $this->resetValidation();
    }

    public function with(): array
    {
        $tree = new PageTree(
            $this->visibleItems(Item::TYPE_NOTE)->get(['id', 'parent_id', 'title', 'icon', 'visibility', 'created_by'])
        );

        $data = [
            'selectedPage' => null,
            'parentOptions' => [],
            'pageTitles' => [],
            'canChangeVisibility' => true,
        ];

        if ($this->showForm) {
            $excludedIds = $this->editingId ? [$this->editingId, ...$tree->descendantIds($this->editingId)] : [];

            $data['parentOptions'] = array_values(array_filter(
                $tree->flatten(),
                fn (array $row) => ! in_array($row['page']->id, $excludedIds, true),
            ));
            // For the [[ autocomplete in the editor.
            $data['pageTitles'] = collect($tree->flatten())
                ->map(fn (array $row) => $row['page']->title)
                ->reject(fn (string $title) => $this->editingId && $title === $tree->find($this->editingId)?->title)
                ->unique()
                ->values()
                ->all();
            $data['canChangeVisibility'] = ! $this->editingId
                || (bool) $this->visibleItems(Item::TYPE_NOTE)->find($this->editingId)?->visibilityCanBeChangedBy(Auth::user());
        }

        if ($this->selectedId) {
            $page = $this->visibleItems(Item::TYPE_NOTE)->with('tags', 'creator')->find($this->selectedId);

            return [
                'selectedPage' => $page,
                'breadcrumbs' => $page ? $tree->ancestors($page->id) : [],
                'subpages' => $page ? $tree->children($page->id) : [],
                'pageHtml' => $page ? NoteMarkdown::toHtml($page, Auth::user(), interactiveTasks: true) : null,
                'backlinks' => $page ? $page->backlinksVisibleTo(Auth::user()) : collect(),
            ] + $data;
        }

        return $data + [
            'pages' => $this->visibleItems(Item::TYPE_NOTE)
                ->with('tags')
                ->when($this->search !== '', fn ($query) => $query->matching($this->search))
                ->when($this->tag !== '', fn ($query) => $query->whereHas('tags', fn ($tags) => $tags->where('name', $this->tag)))
                ->latest('updated_at')
                ->get(),
            'tags' => Tag::usedOnItemsVisibleTo(Auth::user(), $this->householdId, Item::TYPE_NOTE)
                ->orderBy('name')
                ->pluck('name'),
            'breadcrumbsFor' => fn (Item $page) => $tree->ancestors($page->id),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Pages') }}
        </h2>
    </x-slot>

    <div class="py-6 sm:py-10">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            @if ($showForm)
                {{-- Create / edit --}}
                <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6">
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">
                        {{ $editingId ? __('Edit page') : __('New page') }}
                    </h3>

                    <form wire:submit="save" class="space-y-4">
                        <div class="flex gap-3">
                            <div class="w-20 shrink-0">
                                <x-input-label for="icon" :value="__('Icon')" />
                                <x-text-input wire:model="icon" id="icon" class="block mt-1 w-full text-center text-xl" type="text" maxlength="16" placeholder="{{ \App\Models\Item::DEFAULT_PAGE_ICON }}" />
                            </div>
                            <div class="flex-1">
                                <x-input-label for="title" :value="__('Title')" />
                                <x-text-input wire:model="title" id="title" class="block mt-1 w-full" type="text" required autofocus />
                            </div>
                        </div>
                        <x-input-error :messages="$errors->get('icon')" />
                        <x-input-error :messages="$errors->get('title')" />

                        <div>
                            <x-input-label for="parentId" :value="__('Parent page')" />
                            <select wire:model="parentId" id="parentId" class="block mt-1 w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm">
                                <option value="">{{ __('— None (top level) —') }}</option>
                                @foreach ($parentOptions as $option)
                                    <option value="{{ $option['page']->id }}" wire:key="parent-option-{{ $option['page']->id }}">
                                        {{ str_repeat("\u{00A0}", $option['depth'] * 4) }}{{ $option['page']->displayIcon() }} {{ $option['page']->title }}
                                    </option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('parentId')" class="mt-2" />
                        </div>

                        <div
                            x-data="pageEditor(@js([
                                'pageTitles' => $pageTitles,
                                'translations' => [
                                    'placeholder' => __('Write something, or type “/” for commands …'),
                                    'editorLabel' => __('Content'),
                                    'linkPrompt' => __('Link address (leave empty to remove the link):'),
                                    'newPage' => __('New page'),
                                    'text' => __('Text'),
                                    'heading1' => __('Heading 1'),
                                    'heading2' => __('Heading 2'),
                                    'heading3' => __('Heading 3'),
                                    'bulletList' => __('Bulleted list'),
                                    'orderedList' => __('Numbered list'),
                                    'taskList' => __('Checklist'),
                                    'quote' => __('Quote'),
                                    'codeBlock' => __('Code'),
                                    'divider' => __('Divider'),
                                    'table' => __('Table'),
                                    'pageLink' => __('Link to page'),
                                ],
                            ]))"
                            wire:key="editor-{{ $editingId ?? 'new' }}"
                        >
                            <div class="flex items-center justify-between gap-3">
                                <x-input-label for="body" :value="__('Content')" />
                                <button type="button" x-show="mode !== 'loading'" x-on:click="toggleMode()" class="text-xs text-gray-500 dark:text-gray-400 underline">
                                    <span x-show="mode === 'rich'">{{ __('Edit as Markdown') }}</span>
                                    <span x-show="mode === 'markdown'" x-cloak>{{ __('Back to editor') }}</span>
                                </button>
                            </div>

                            <div wire:ignore x-show="mode !== 'markdown'" class="mt-1">
                                <div x-ref="bubble" class="editor-bubble" style="visibility: hidden" role="toolbar" aria-label="{{ __('Formatting') }}">
                                    <button type="button" x-on:click="run('toggleBold')" :class="isActive('bold') && 'is-active'" title="{{ __('Bold') }}"><strong>B</strong></button>
                                    <button type="button" x-on:click="run('toggleItalic')" :class="isActive('italic') && 'is-active'" title="{{ __('Italic') }}"><em>I</em></button>
                                    <button type="button" x-on:click="run('toggleStrike')" :class="isActive('strike') && 'is-active'" title="{{ __('Strikethrough') }}"><s>S</s></button>
                                    <button type="button" x-on:click="run('toggleCode')" :class="isActive('code') && 'is-active'" title="{{ __('Code') }}"><code>&lt;/&gt;</code></button>
                                    <button type="button" x-on:click="setLink()" :class="isActive('link') && 'is-active'" title="{{ __('Link') }}">🔗</button>
                                    <span class="editor-bubble-divider" aria-hidden="true"></span>
                                    <button type="button" x-on:click="run('toggleHeading', { level: 2 })" :class="isActive('heading', { level: 2 }) && 'is-active'" title="{{ __('Heading 2') }}">H2</button>
                                    <button type="button" x-on:click="run('toggleTaskList')" :class="isActive('taskList') && 'is-active'" title="{{ __('Checklist') }}">☐</button>
                                </div>
                                <div x-ref="editor" class="editor-frame">
                                    <p x-show="mode === 'loading'" class="p-3 text-sm text-gray-400">{{ __('Loading editor …') }}</p>
                                </div>
                            </div>

                            <p x-show="hasUnsupportedContent && mode === 'markdown'" x-cloak class="mt-1 rounded-md bg-amber-50 dark:bg-amber-900/30 px-3 py-2 text-xs text-amber-800 dark:text-amber-300">
                                {{ __('This page contains images or HTML that the editor cannot show, so it opens as Markdown to keep them intact.') }}
                            </p>
                            <textarea x-show="mode === 'markdown'" x-cloak wire:model="body" id="body" rows="14" class="block mt-1 w-full font-mono text-sm border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm"></textarea>

                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {{ __('Type “/” for headings, lists, checklists and tables. Link other pages with [[ – suggestions appear as you type.') }}
                            </p>
                            <x-input-error :messages="$errors->get('body')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="tagsInput" :value="__('Tags (comma-separated)')" />
                            <x-text-input wire:model="tagsInput" id="tagsInput" class="block mt-1 w-full" type="text" placeholder="{{ __('idea, gift, travel') }}" />
                            <x-input-error :messages="$errors->get('tagsInput')" class="mt-2" />
                        </div>

                        @if ($canChangeVisibility)
                            <x-private-checkbox id="isPrivate" wire:model="isPrivate" />
                        @endif

                        <div class="flex items-center gap-3">
                            <x-primary-button type="submit">
                                {{ $editingId ? __('Save changes') : __('Create page') }}
                            </x-primary-button>

                            <button type="button" wire:click="cancelEdit" class="text-sm text-gray-500 dark:text-gray-400 underline">
                                {{ __('Cancel') }}
                            </button>
                        </div>
                    </form>
                </div>
            @elseif ($selectedId)
                {{-- Single page --}}
                @if ($selectedPage)
                    <article class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 sm:p-8 space-y-6">
                        <nav aria-label="{{ __('Breadcrumb') }}" class="flex flex-wrap items-center gap-1 text-sm text-gray-500 dark:text-gray-400">
                            <a href="{{ route('pages.index') }}" wire:navigate class="hover:underline">{{ __('Pages') }}</a>
                            @foreach ($breadcrumbs as $ancestor)
                                <span aria-hidden="true">/</span>
                                <a href="{{ route('pages.index', ['item' => $ancestor->id]) }}" wire:navigate wire:key="crumb-{{ $ancestor->id }}" class="hover:underline">
                                    {{ $ancestor->displayIcon() }} {{ $ancestor->title }}
                                </a>
                            @endforeach
                        </nav>

                        <div class="flex flex-wrap justify-between items-start gap-4">
                            <div>
                                <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100 flex flex-wrap items-center gap-3">
                                    <span aria-hidden="true">{{ $selectedPage->displayIcon() }}</span>
                                    {{ $selectedPage->title }}
                                    @if ($selectedPage->isPrivate())
                                        <x-private-badge />
                                    @endif
                                </h1>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">
                                    {{ __('Updated :time', ['time' => $selectedPage->updated_at->diffForHumans()]) }}
                                    @if ($selectedPage->creator)
                                        · {{ __('by :name', ['name' => $selectedPage->creator->name]) }}
                                    @endif
                                </p>
                            </div>
                            <div class="flex gap-3 shrink-0 text-sm">
                                <button type="button" wire:click="edit({{ $selectedPage->id }})" class="text-indigo-600 dark:text-indigo-400 underline">{{ __('Edit') }}</button>
                                <button type="button" wire:click="delete({{ $selectedPage->id }})" wire:confirm="{{ __('Delete this page? Its subpages move up one level.') }}" class="text-red-600 dark:text-red-400 underline">{{ __('Delete') }}</button>
                            </div>
                        </div>

                        @if ($selectedPage->tags->isNotEmpty())
                            <div class="flex flex-wrap gap-1">
                                @foreach ($selectedPage->tags->sortBy('name') as $pageTag)
                                    <a href="{{ route('pages.index', ['tag' => $pageTag->name]) }}" wire:navigate class="text-xs bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 rounded-full px-2 py-0.5">#{{ $pageTag->name }}</a>
                                @endforeach
                            </div>
                        @endif

                        @if ($selectedPage->body() !== '')
                            <div class="note-content">{!! $pageHtml !!}</div>
                        @else
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('This page has no content yet.') }}</p>
                        @endif

                        <section class="border-t border-gray-100 dark:border-gray-700 pt-4">
                            <div class="flex items-center justify-between gap-3">
                                <h2 class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Subpages') }}</h2>
                                <button type="button" wire:click="createChild({{ $selectedPage->id }})" class="text-sm text-indigo-600 dark:text-indigo-400 underline">
                                    + {{ __('Add subpage') }}
                                </button>
                            </div>
                            @if ($subpages !== [])
                                <ul class="mt-2 space-y-1">
                                    @foreach ($subpages as $subpage)
                                        <li wire:key="subpage-{{ $subpage->id }}">
                                            <a href="{{ route('pages.index', ['item' => $subpage->id]) }}" wire:navigate class="flex items-center gap-2 rounded-md px-2 py-1.5 text-gray-900 dark:text-gray-100 hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                                <span aria-hidden="true">{{ $subpage->displayIcon() }}</span>
                                                <span class="underline decoration-gray-300 dark:decoration-gray-600">{{ $subpage->title }}</span>
                                                @if ($subpage->isPrivate())
                                                    <x-private-badge />
                                                @endif
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </section>

                        <section class="border-t border-gray-100 dark:border-gray-700 pt-4">
                            <h2 class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Linked from') }}</h2>
                            @forelse ($backlinks as $backlink)
                                <a href="{{ route('pages.index', ['item' => $backlink->id]) }}" wire:navigate wire:key="backlink-{{ $backlink->id }}" class="block mt-1 text-sm text-indigo-600 dark:text-indigo-400 underline">
                                    {{ $backlink->displayIcon() }} {{ $backlink->title }}
                                </a>
                            @empty
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('No other page links here yet.') }}</p>
                            @endforelse
                        </section>
                    </article>
                @else
                    <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 text-gray-600 dark:text-gray-400">
                        {{ __('This page no longer exists.') }}
                        <a href="{{ route('pages.index') }}" wire:navigate class="underline">{{ __('All pages') }}</a>
                    </div>
                @endif
            @else
                {{-- Overview --}}
                <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4 space-y-3">
                    <div class="flex gap-3">
                        <x-text-input wire:model.live.debounce.300ms="search" class="block w-full" type="search" placeholder="{{ __('Search pages...') }}" />
                        <x-primary-button type="button" wire:click="create" class="shrink-0">{{ __('New page') }}</x-primary-button>
                    </div>

                    @if ($tags->isNotEmpty())
                        <div class="flex flex-wrap gap-1">
                            @foreach ($tags as $tagName)
                                <button
                                    type="button"
                                    wire:click="filterByTag(@js($tagName))"
                                    wire:key="tag-filter-{{ $tagName }}"
                                    @class([
                                        'text-xs rounded-full px-2 py-0.5',
                                        'bg-indigo-600 text-white' => $tag === $tagName,
                                        'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600' => $tag !== $tagName,
                                    ])
                                >#{{ $tagName }}</button>
                            @endforeach
                        </div>
                    @endif
                </div>

                <ul class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg divide-y divide-gray-100 dark:divide-gray-700" wire:loading.class="opacity-50">
                    @forelse ($pages as $page)
                        <li wire:key="page-{{ $page->id }}">
                            <a href="{{ route('pages.index', ['item' => $page->id]) }}" wire:navigate class="block p-4 hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                @php($ancestors = $breadcrumbsFor($page))
                                @if ($ancestors !== [])
                                    <p class="text-xs text-gray-500 dark:text-gray-400 truncate">
                                        {{ collect($ancestors)->pluck('title')->implode(' / ') }}
                                    </p>
                                @endif
                                <div class="flex items-center gap-2">
                                    <span aria-hidden="true">{{ $page->displayIcon() }}</span>
                                    <h4 class="font-medium text-gray-900 dark:text-gray-100 truncate">{{ $page->title }}</h4>
                                    @if ($page->isPrivate())
                                        <x-private-badge />
                                    @endif
                                </div>
                                @if ($page->body() !== '')
                                    <p class="text-sm text-gray-600 dark:text-gray-400 mt-1 line-clamp-2">{{ \App\Support\NoteMarkdown::excerpt($page->body()) }}</p>
                                @endif
                                @if ($page->tags->isNotEmpty())
                                    <div class="mt-2 flex flex-wrap gap-1">
                                        @foreach ($page->tags->sortBy('name') as $pageTag)
                                            <span class="text-xs bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 rounded-full px-2 py-0.5">#{{ $pageTag->name }}</span>
                                        @endforeach
                                    </div>
                                @endif
                            </a>
                        </li>
                    @empty
                        <li class="p-4 text-gray-500 dark:text-gray-400 text-sm">
                            {{ $search !== '' || $tag !== '' ? __('No pages match your filter.') : __('No pages yet.') }}
                        </li>
                    @endforelse
                </ul>

                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('Tip: write [[Page title]] to link pages. Links to pages that do not exist yet let you create them with one click.') }}
                </p>
            @endif

        </div>
    </div>
</div>
