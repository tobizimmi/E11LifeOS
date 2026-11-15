<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Bookmark;
use App\Models\BookmarkCategory;
use App\Models\Item;
use App\Support\BookmarkImages;
use App\Support\LinkPreview;
use App\Support\Security\UrlGuard;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    /** Category filter: '' = all, 'none' = without a category, otherwise the category id. */
    #[Url(except: '')]
    public string $filter = '';

    public bool $showRead = true;

    public string $newCategory = '';

    // ── Add / edit form ──────────────────────────────────────────────
    public bool $adding = false;

    public ?int $editingId = null;

    public string $url = '';

    public string $title = '';

    public string $description = '';

    public string $siteName = '';

    public string $comment = '';

    public ?string $categoryId = null;

    public bool $private = false;

    /** @var array<int, string> */
    public array $previewImages = [];

    public string $selectedImage = '';

    public string $faviconUrl = '';

    public string $previewError = '';

    private function find(int $id): Bookmark
    {
        return Bookmark::query()->visibleTo(Auth::user(), (int) $this->householdId)->findOrFail($id);
    }

    private function resetForm(): void
    {
        $this->reset('adding', 'editingId', 'url', 'title', 'description', 'siteName', 'comment', 'categoryId', 'private', 'previewImages', 'selectedImage', 'faviconUrl', 'previewError');
        $this->resetValidation();
    }

    public function startAdding(): void
    {
        $this->resetForm();
        $this->categoryId = $this->filter !== '' && $this->filter !== 'none' ? $this->filter : null;
        $this->adding = true;
    }

    public function startEditing(int $id): void
    {
        $bookmark = $this->find($id);

        $this->editingId = $bookmark->id;
        $this->url = $bookmark->url;
        $this->title = (string) $bookmark->title;
        $this->description = (string) $bookmark->description;
        $this->siteName = (string) $bookmark->site_name;
        $this->comment = (string) $bookmark->comment;
        $this->categoryId = $bookmark->category_id !== null ? (string) $bookmark->category_id : null;
        $this->private = $bookmark->isPrivate();
        $this->faviconUrl = (string) $bookmark->favicon_url;
        $this->selectedImage = (string) ($bookmark->image_url ?? '');
        $this->previewImages = array_values(array_filter([$bookmark->image_url]));
        $this->previewError = '';
        $this->adding = true;
    }

    /**
     * Fetches the web page's metadata (title, description, site name, candidate images).
     */
    public function loadPreview(): void
    {
        $this->previewError = '';
        $url = trim($this->url);

        if (! UrlGuard::hasValidShape($url)) {
            $this->previewError = __('Please enter a valid web address (https://…).');

            return;
        }

        try {
            $meta = LinkPreview::fetch($url);
        } catch (\Throwable $exception) {
            $this->previewError = $exception->getMessage();

            return;
        }

        $this->title = $this->title !== '' ? $this->title : (string) $meta['title'];
        $this->description = $this->description !== '' ? $this->description : (string) $meta['description'];
        $this->siteName = (string) $meta['site_name'];
        $this->faviconUrl = (string) $meta['favicon'];
        $this->previewImages = $meta['images'];
        $this->selectedImage = $meta['images'][0] ?? '';
    }

    public function save(): void
    {
        $this->validate([
            'url' => ['required', 'string', 'max:2048', function (string $attribute, mixed $value, \Closure $fail) {
                if (! UrlGuard::hasValidShape((string) $value)) {
                    $fail(__('Please enter a valid web address (https://…).'));
                }
            }],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'categoryId' => ['nullable'],
        ]);

        $category = $this->categoryId ? BookmarkCategory::where('household_id', $this->householdId)->find((int) $this->categoryId) : null;

        $bookmark = $this->editingId
            ? $this->find($this->editingId)
            : new Bookmark(['household_id' => $this->householdId, 'created_by' => Auth::id()]);

        // Download the chosen preview image (SSRF-guarded); keep the remote URL as a fallback.
        $imagePath = $bookmark->image_path;
        if ($this->selectedImage !== '' && $this->selectedImage !== $bookmark->image_url) {
            BookmarkImages::delete($bookmark->image_path);
            $imagePath = BookmarkImages::download($this->selectedImage, (int) $this->householdId);
        } elseif ($this->selectedImage === '') {
            BookmarkImages::delete($bookmark->image_path);
            $imagePath = null;
        }

        $bookmark->fill([
            'category_id' => $category?->id,
            'visibility' => $this->private ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED,
            'url' => trim($this->url),
            'title' => trim($this->title) !== '' ? trim($this->title) : null,
            'description' => trim($this->description) !== '' ? trim($this->description) : null,
            'site_name' => trim($this->siteName) !== '' ? mb_substr(trim($this->siteName), 0, 120) : null,
            'favicon_url' => $this->faviconUrl !== '' ? $this->faviconUrl : null,
            'image_url' => $this->selectedImage !== '' ? $this->selectedImage : null,
            'image_path' => $imagePath,
            'comment' => trim($this->comment) !== '' ? trim($this->comment) : null,
        ])->save();

        $this->resetForm();
        $this->dispatch('toast', message: __('Saved.'));
    }

    public function delete(int $id): void
    {
        $this->find($id)->delete();
    }

    public function toggleRead(int $id): void
    {
        $bookmark = $this->find($id);
        $bookmark->update(['is_read' => ! $bookmark->is_read, 'read_at' => $bookmark->is_read ? null : now()]);
    }

    // ── Categories ───────────────────────────────────────────────────
    public function addCategory(): void
    {
        $name = trim($this->newCategory);

        if ($name === '') {
            return;
        }

        $colors = ['#2563eb', '#dc2626', '#16a34a', '#d97706', '#7c3aed', '#0891b2', '#db2777'];
        BookmarkCategory::create([
            'household_id' => $this->householdId,
            'created_by' => Auth::id(),
            'name' => mb_substr($name, 0, 80),
            'color' => $colors[BookmarkCategory::where('household_id', $this->householdId)->count() % count($colors)],
            'position' => (int) BookmarkCategory::where('household_id', $this->householdId)->max('position') + 1,
        ]);

        $this->newCategory = '';
    }

    public function deleteCategory(int $id): void
    {
        $category = BookmarkCategory::where('household_id', $this->householdId)->findOrFail($id);
        $category->delete(); // bookmarks keep (category_id set null)

        if ($this->filter === (string) $id) {
            $this->filter = '';
        }
    }

    /**
     * @return \Illuminate\Support\Collection<int, BookmarkCategory>
     */
    #[Computed]
    public function categories(): \Illuminate\Support\Collection
    {
        return BookmarkCategory::where('household_id', $this->householdId)
            ->withCount(['bookmarks' => fn ($query) => $query->visibleTo(Auth::user(), (int) $this->householdId)])
            ->orderBy('position')->orderBy('name')->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $bookmarks = Bookmark::query()
            ->visibleTo(Auth::user(), (int) $this->householdId)
            ->when($this->filter === 'none', fn ($query) => $query->whereNull('category_id'))
            ->when($this->filter !== '' && $this->filter !== 'none', fn ($query) => $query->where('category_id', (int) $this->filter))
            ->when(! $this->showRead, fn ($query) => $query->where('is_read', false))
            ->with('category')
            ->orderBy('is_read')
            ->latest('id')
            ->get();

        return [
            'bookmarks' => $bookmarks,
            'categories' => $this->categories,
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">🔖 {{ __('Bookmarks & read later') }}</h2>
            <x-primary-button type="button" wire:click="startAdding">+ {{ __('New link') }}</x-primary-button>
        </div>
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="mx-auto max-w-5xl space-y-5 px-4 sm:px-6 lg:px-8">
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Save articles, shopping links and online magazines to read or revisit later – sorted into your own areas, with a comment and a preview of the page.') }}</p>

            {{-- Category filter + management --}}
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" wire:click="$set('filter', '')" @class(['rounded-full px-3 py-1 text-sm', 'bg-indigo-600 text-white' => $filter === '', 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200' => $filter !== ''])>{{ __('All') }}</button>
                @foreach ($categories as $category)
                    <span class="inline-flex items-center overflow-hidden rounded-full {{ $filter === (string) $category->id ? 'ring-2 ring-indigo-500' : '' }}">
                        <button type="button" wire:click="$set('filter', '{{ $category->id }}')" class="px-3 py-1 text-sm text-white" style="background: {{ $category->color ?? '#4b5563' }}">{{ $category->icon }} {{ $category->name }} <span class="opacity-80">· {{ $category->bookmarks_count }}</span></button>
                        <button type="button" wire:click="deleteCategory({{ $category->id }})" wire:confirm="{{ __('Delete this area? The links stay.') }}" class="bg-black/15 px-1.5 py-1 text-xs text-white hover:bg-black/30" aria-label="{{ __('Delete') }}">✕</button>
                    </span>
                @endforeach
                <button type="button" wire:click="$set('filter', 'none')" @class(['rounded-full px-3 py-1 text-sm', 'bg-indigo-600 text-white' => $filter === 'none', 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200' => $filter !== 'none'])>{{ __('No area') }}</button>
                <form wire:submit="addCategory" class="inline-flex items-center gap-1">
                    <x-text-input wire:model="newCategory" class="h-8 w-32 text-sm" placeholder="{{ __('New area') }}" />
                    <button type="submit" class="rounded-md border border-gray-300 px-2 py-1 text-sm text-gray-600 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">+</button>
                </form>
                <span class="flex-1"></span>
                <label class="flex items-center gap-1.5 text-sm text-gray-600 dark:text-gray-300">
                    <input type="checkbox" wire:model.live="showRead" class="rounded-sm border-gray-300 text-indigo-600 dark:border-gray-600 dark:bg-gray-900" /> {{ __('Show read') }}
                </label>
            </div>

            {{-- Bookmark cards --}}
            @if ($bookmarks->isEmpty())
                <div class="rounded-2xl border border-dashed border-gray-300 bg-white p-10 text-center dark:border-gray-600 dark:bg-gray-800">
                    <p class="text-4xl">🔖</p>
                    <p class="mt-2 font-medium text-gray-900 dark:text-gray-100">{{ __('Nothing saved here yet') }}</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Paste a link, load its preview and save it to an area.') }}</p>
                </div>
            @else
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($bookmarks as $bookmark)
                        <div wire:key="bm-{{ $bookmark->id }}" @class(['group flex flex-col overflow-hidden rounded-2xl bg-white shadow-xs ring-1 ring-gray-100 dark:bg-gray-800 dark:ring-gray-700', 'opacity-60' => $bookmark->is_read])>
                            <a href="{{ $bookmark->url }}" target="_blank" rel="noopener noreferrer" class="block">
                                @if ($bookmark->previewImageUrl())
                                    <img src="{{ $bookmark->previewImageUrl() }}" alt="" loading="lazy" class="h-36 w-full bg-gray-100 object-cover dark:bg-gray-700" />
                                @else
                                    <div class="flex h-36 w-full items-center justify-center bg-gray-100 text-4xl dark:bg-gray-700">🔗</div>
                                @endif
                            </a>
                            <div class="flex flex-1 flex-col gap-2 p-3">
                                <div class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                                    @if ($bookmark->favicon_url)
                                        <img src="{{ $bookmark->favicon_url }}" alt="" class="h-4 w-4 rounded-sm" onerror="this.style.display='none'" />
                                    @endif
                                    <span class="truncate">{{ $bookmark->host() }}</span>
                                    @if ($bookmark->category)
                                        <span class="ms-auto rounded-full px-1.5 py-0.5 text-[10px] text-white" style="background: {{ $bookmark->category->color ?? '#4b5563' }}">{{ $bookmark->category->icon }} {{ $bookmark->category->name }}</span>
                                    @endif
                                </div>
                                <a href="{{ $bookmark->url }}" target="_blank" rel="noopener noreferrer" class="font-medium text-gray-900 hover:underline dark:text-gray-100 line-clamp-2">{{ $bookmark->title ?: $bookmark->url }}</a>
                                @if ($bookmark->description)
                                    <p class="text-xs text-gray-500 dark:text-gray-400 line-clamp-2">{{ $bookmark->description }}</p>
                                @endif
                                @if ($bookmark->comment)
                                    <p class="rounded-md bg-amber-50 px-2 py-1 text-xs text-amber-800 dark:bg-amber-900/20 dark:text-amber-200">💬 {{ $bookmark->comment }}</p>
                                @endif
                                <div class="mt-auto flex items-center gap-3 pt-1 text-xs">
                                    <button type="button" wire:click="toggleRead({{ $bookmark->id }})" class="{{ $bookmark->is_read ? 'text-emerald-600' : 'text-gray-500 hover:text-emerald-600' }}">{{ $bookmark->is_read ? '✓ '.__('Read') : __('Mark read') }}</button>
                                    <button type="button" wire:click="startEditing({{ $bookmark->id }})" class="text-gray-500 hover:text-indigo-600">{{ __('Edit') }}</button>
                                    <button type="button" wire:click="delete({{ $bookmark->id }})" wire:confirm="{{ __('Delete this link?') }}" class="text-gray-500 hover:text-red-600">{{ __('Delete') }}</button>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- Add / edit dialog --}}
    @if ($adding)
        <div class="fixed inset-0 z-[60] flex items-end justify-center overflow-y-auto bg-gray-900/60 p-4 sm:items-center" x-on:keydown.escape.window="$wire.set('adding', false)" wire:click.self="$set('adding', false)">
            <form wire:submit="save" class="my-auto max-h-[calc(100dvh-2rem)] w-full max-w-lg space-y-4 overflow-y-auto overscroll-contain rounded-2xl bg-white p-5 shadow-xl dark:bg-gray-800" role="dialog" aria-modal="true">
                <div class="flex items-center justify-between gap-2">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $editingId ? __('Edit link') : __('New link') }}</h3>
                    <button type="button" wire:click="$set('adding', false)" class="text-gray-400 hover:text-gray-700 dark:hover:text-gray-200" aria-label="{{ __('Close') }}"><x-icon name="x-mark" class="h-5 w-5" stroke-width="2" /></button>
                </div>

                <div>
                    <x-input-label for="bm-url" :value="__('Web address')" />
                    <div class="mt-1 flex gap-2">
                        <x-text-input wire:model="url" id="bm-url" type="url" inputmode="url" class="block min-w-0 flex-1" placeholder="https://…" />
                        <button type="button" wire:click="loadPreview" wire:loading.attr="disabled" wire:target="loadPreview" class="shrink-0 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-700">
                            <span wire:loading.remove wire:target="loadPreview">{{ __('Load preview') }}</span>
                            <span wire:loading wire:target="loadPreview">{{ __('Loading …') }}</span>
                        </button>
                    </div>
                    <x-input-error :messages="$errors->get('url')" class="mt-1" />
                    @if ($previewError)
                        <p class="mt-1 text-xs text-amber-600 dark:text-amber-400">{{ $previewError }}</p>
                    @endif
                </div>

                {{-- Candidate images from the page --}}
                @if ($previewImages !== [])
                    <div>
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Choose an image') }}</span>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <button type="button" wire:click="$set('selectedImage', '')" @class(['flex h-16 w-24 items-center justify-center rounded-md text-xs ring-2', 'ring-indigo-500' => $selectedImage === '', 'ring-transparent bg-gray-100 dark:bg-gray-700' => $selectedImage !== '']) >{{ __('None') }}</button>
                            @foreach ($previewImages as $image)
                                <button type="button" wire:click="$set('selectedImage', @js($image))" @class(['h-16 w-24 overflow-hidden rounded-md ring-2', 'ring-indigo-500' => $selectedImage === $image, 'ring-transparent' => $selectedImage !== $image])>
                                    <img src="{{ $image }}" alt="" class="h-full w-full object-cover" onerror="this.closest('button').style.display='none'" />
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div>
                    <x-input-label for="bm-title" :value="__('Title')" />
                    <x-text-input wire:model="title" id="bm-title" class="mt-1 block w-full" />
                </div>
                <div>
                    <x-input-label for="bm-desc" :value="__('Description')" />
                    <textarea wire:model="description" id="bm-desc" rows="2" class="mt-1 block w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200"></textarea>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <x-input-label for="bm-cat" :value="__('Area')" />
                        <select wire:model="categoryId" id="bm-cat" class="mt-1 block w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                            <option value="">{{ __('No area') }}</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}">{{ $category->icon }} {{ $category->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex items-end">
                        <x-private-checkbox id="bm-private" wire:model="private" />
                    </div>
                </div>

                <div>
                    <x-input-label for="bm-comment" :value="__('Comment (optional)')" />
                    <textarea wire:model="comment" id="bm-comment" rows="2" class="mt-1 block w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200" placeholder="{{ __('Why did you save this?') }}"></textarea>
                </div>

                <div class="flex items-center gap-3 pt-1">
                    <x-primary-button wire:loading.attr="disabled" wire:target="save">{{ __('Save') }}</x-primary-button>
                    <button type="button" wire:click="$set('adding', false)" class="text-sm text-gray-500 underline">{{ __('Cancel') }}</button>
                </div>
            </form>
        </div>
    @endif
</div>
