<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Recipe;
use App\Models\RecipeCategory;
use App\Models\Tag;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /**
     * Category id as string ("" = all), so a malformed URL can't break the page.
     */
    #[Url(except: '')]
    public string $category = '';

    #[Url(except: '')]
    public string $tag = '';

    #[Url(except: false)]
    public bool $favorites = false;

    #[Url(except: false)]
    public bool $archived = false;

    /**
     * "newest", "title", "time", "cooked" or "rating".
     */
    #[Url(except: 'newest')]
    public string $sort = 'newest';

    /**
     * Comma-separated ingredients at home ("What can I cook?").
     */
    #[Url(except: '')]
    public string $pantry = '';

    public bool $pantryMode = false;

    public function mount(): void
    {
        $this->pantryMode = $this->pantry !== '';
    }

    public function togglePantryMode(): void
    {
        $this->pantryMode = ! $this->pantryMode;

        if (! $this->pantryMode) {
            $this->pantry = '';
        }
    }

    /**
     * @return array<int, string>
     */
    private function pantryTerms(): array
    {
        return collect(explode(',', $this->pantry))
            ->map(fn (string $term) => mb_strtolower(trim($term)))
            ->filter(fn (string $term) => mb_strlen($term) >= 2)
            ->unique()
            ->values()
            ->all();
    }

    public function toggleFavorite(int $recipeId): void
    {
        $recipe = Recipe::query()->visibleTo(Auth::user(), $this->householdId)->findOrFail($recipeId);

        $recipe->favoritedBy()->toggle([Auth::id() => ['created_at' => now()]]);
    }

    public function with(): array
    {
        $user = Auth::user();

        $recipes = Recipe::query()
            ->visibleTo($user, $this->householdId)
            ->with(['categories', 'favoritedBy' => fn ($query) => $query->whereKey($user->id)])
            ->withAvg('ratings', 'rating')
            ->when($this->archived, fn ($query) => $query->whereNotNull('archived_at'), fn ($query) => $query->whereNull('archived_at'))
            ->when($this->search !== '', fn ($query) => $query->matching($this->search))
            ->when(ctype_digit($this->category), fn ($query) => $query->whereHas('categories', fn ($categories) => $categories->whereKey((int) $this->category)))
            ->when($this->tag !== '', fn ($query) => $query->whereHas('tags', fn ($tags) => $tags->where('name', $this->tag)))
            ->when($this->favorites, fn ($query) => $query->whereHas('favoritedBy', fn ($users) => $users->whereKey($user->id)))
            ->when($this->sort === 'title', fn ($query) => $query->orderBy('title'))
            ->when($this->sort === 'time', fn ($query) => $query->orderByRaw('COALESCE(prep_time, 0) + COALESCE(cook_time, 0) = 0')->orderByRaw('COALESCE(prep_time, 0) + COALESCE(cook_time, 0)'))
            ->when($this->sort === 'cooked', fn ($query) => $query->orderByDesc('cooked_count'))
            ->when($this->sort === 'rating', fn ($query) => $query->orderByDesc('ratings_avg_rating'))
            ->latest('id')
            ->get();

        // Pantry search: recipes using at least one of the ingredients, best coverage first.
        $pantryTerms = $this->pantryTerms();
        $pantryMatches = [];

        if ($pantryTerms !== []) {
            $recipes = $recipes
                ->each(function (Recipe $recipe) use ($pantryTerms, &$pantryMatches) {
                    $pantryMatches[$recipe->id] = $recipe->pantryMatch($pantryTerms);
                })
                ->filter(fn (Recipe $recipe) => $pantryMatches[$recipe->id]['matched'] !== [])
                ->sortBy(fn (Recipe $recipe) => [-count($pantryMatches[$recipe->id]['matched']), -$pantryMatches[$recipe->id]['percent']])
                ->values();
        }

        return [
            'recipes' => $recipes,
            'pantryMatches' => $pantryMatches,
            'categories' => RecipeCategory::where('household_id', $this->householdId)->orderBy('position')->orderBy('name')->get(),
            'tags' => Tag::where('household_id', $this->householdId)
                ->whereHas('recipes', fn ($query) => $query->visibleTo($user, $this->householdId))
                ->orderBy('name')
                ->pluck('name'),
            'filtered' => $this->search !== '' || $this->category !== '' || $this->tag !== '' || $this->favorites || $pantryTerms !== [],
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Recipes') }}
        </h2>
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
            <div class="flex flex-wrap items-center gap-3">
                <x-text-input wire:model.live.debounce.300ms="search" type="search" class="block flex-1 min-w-[14rem]" placeholder="{{ __('Search recipes or ingredients...') }}" aria-label="{{ __('Search recipes') }}" />
                <select wire:model.live="sort" aria-label="{{ __('Sort') }}" class="rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 shadow-sm text-sm">
                    <option value="newest">{{ __('Newest first') }}</option>
                    <option value="title">{{ __('A–Z') }}</option>
                    <option value="time">{{ __('Quickest first') }}</option>
                    <option value="cooked">{{ __('Cooked most often') }}</option>
                    <option value="rating">{{ __('Best rated') }}</option>
                </select>
                <a href="{{ route('recipes.plan') }}" wire:navigate class="inline-flex items-center rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700">📅 {{ __('Meal plan') }}</a>
                <a href="{{ route('recipes.import') }}" wire:navigate class="inline-flex items-center rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700">⬇️ {{ __('Import') }}</a>
                <a href="{{ route('recipes.create') }}" wire:navigate class="inline-flex items-center px-4 py-2 bg-gray-800 dark:bg-gray-200 rounded-md font-semibold text-xs text-white dark:text-gray-800 uppercase tracking-widest hover:bg-gray-700 dark:hover:bg-white">
                    + {{ __('New recipe') }}
                </a>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <button type="button" wire:click="togglePantryMode" @class(['rounded-full px-3 py-1 text-sm', 'bg-emerald-600 text-white' => $pantryMode, 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 ring-1 ring-gray-200 dark:ring-gray-700' => ! $pantryMode]) aria-pressed="{{ $pantryMode ? 'true' : 'false' }}">🧺 {{ __('What can I cook?') }}</button>
                <button type="button" wire:click="$toggle('favorites')" @class(['rounded-full px-3 py-1 text-sm', 'bg-rose-600 text-white' => $favorites, 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 ring-1 ring-gray-200 dark:ring-gray-700' => ! $favorites]) aria-pressed="{{ $favorites ? 'true' : 'false' }}">♥ {{ __('Favorites') }}</button>
                @foreach ($categories as $recipeCategory)
                    <button
                        type="button"
                        wire:key="category-filter-{{ $recipeCategory->id }}"
                        wire:click="$set('category', '{{ $category === (string) $recipeCategory->id ? '' : $recipeCategory->id }}')"
                        @class(['rounded-full px-3 py-1 text-sm', 'bg-indigo-600 text-white' => $category === (string) $recipeCategory->id, 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 ring-1 ring-gray-200 dark:ring-gray-700' => $category !== (string) $recipeCategory->id])
                    >{{ $recipeCategory->icon }} {{ $recipeCategory->name }}</button>
                @endforeach
                <a href="{{ route('recipes.categories') }}" wire:navigate class="text-sm text-gray-500 dark:text-gray-400 underline">{{ __('Manage categories') }}</a>
                <span class="flex-1"></span>
                <label class="inline-flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                    <input type="checkbox" wire:model.live="archived" class="rounded border-gray-300 dark:border-gray-600 text-indigo-600" />
                    {{ __('Archive') }}
                </label>
            </div>

            @if ($pantryMode)
                <div class="rounded-lg bg-emerald-50 dark:bg-emerald-900/30 p-3">
                    <x-input-label for="pantry" :value="__('Which ingredients do you have at home?')" />
                    <x-text-input wire:model.live.debounce.400ms="pantry" id="pantry" class="mt-1 block w-full" placeholder="{{ __('e.g. potatoes, eggs, spinach') }}" autofocus />
                    <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">{{ __('Separate with commas. Recipes using the most of them come first.') }}</p>
                </div>
            @endif

            @if ($tags->isNotEmpty())
                <div class="flex flex-wrap gap-1">
                    @foreach ($tags as $tagName)
                        <button type="button" wire:key="tag-filter-{{ $tagName }}" wire:click="$set('tag', @js($tag === $tagName ? '' : $tagName))" @class(['text-xs rounded-full px-2 py-0.5', 'bg-indigo-600 text-white' => $tag === $tagName, 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300' => $tag !== $tagName])>#{{ $tagName }}</button>
                    @endforeach
                </div>
            @endif

            <p class="text-sm text-gray-500 dark:text-gray-400">{{ trans_choice(':count recipe|:count recipes', $recipes->count()) }}</p>

            <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4" wire:loading.class="opacity-50">
                @forelse ($recipes as $recipe)
                    <li wire:key="recipe-{{ $recipe->id }}" class="group relative overflow-hidden rounded-lg bg-white dark:bg-gray-800 shadow-sm ring-1 ring-gray-200/60 dark:ring-gray-700 hover:ring-indigo-300">
                        <a href="{{ route('recipes.show', $recipe) }}" wire:navigate class="block">
                            <div class="aspect-[4/3] bg-gray-100 dark:bg-gray-700">
                                @if ($recipe->imageUrl())
                                    <img src="{{ $recipe->imageUrl() }}" alt="" loading="lazy" class="h-full w-full object-cover" />
                                @else
                                    <div class="flex h-full items-center justify-center text-5xl" aria-hidden="true">{{ $recipe->categories->first()?->icon ?: '🍽️' }}</div>
                                @endif
                            </div>
                            <div class="p-3">
                                <h3 class="font-semibold text-gray-900 dark:text-gray-100 line-clamp-2">{{ $recipe->title }}</h3>
                                <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                                    @if ($recipe->totalTime())
                                        <span>⏱ {{ $recipe->totalTime() }} {{ __('min') }}</span>
                                    @endif
                                    @if ($recipe->servings)
                                        <span>🍽 {{ trans_choice(':count serving|:count servings', $recipe->servings) }}</span>
                                    @endif
                                    @if ($recipe->isPrivate())
                                        <x-private-badge />
                                    @endif
                                </p>
                                @if ($recipe->ratings_avg_rating)
                                    <p class="mt-1 text-xs text-amber-600 dark:text-amber-400">★ {{ number_format((float) $recipe->ratings_avg_rating, 1, ',', '') }}</p>
                                @endif
                                @if (isset($pantryMatches[$recipe->id]))
                                    @php($pantryMatch = $pantryMatches[$recipe->id])
                                    <p class="mt-2 text-xs font-medium text-emerald-700 dark:text-emerald-300">{{ __(':matched of :total ingredients at home', ['matched' => count($pantryMatch['matched']), 'total' => count($pantryMatch['matched']) + count($pantryMatch['missing'])]) }} ({{ $pantryMatch['percent'] }} %)</p>
                                    @if ($pantryMatch['missing'] !== [])
                                        <p class="mt-0.5 line-clamp-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Missing') }}: {{ implode(', ', $pantryMatch['missing']) }}</p>
                                    @endif
                                @endif
                                @if ($recipe->categories->isNotEmpty())
                                    <p class="mt-2 truncate text-xs text-gray-500 dark:text-gray-400">{{ $recipe->categories->map(fn ($c) => trim($c->icon.' '.$c->name))->implode(' · ') }}</p>
                                @endif
                            </div>
                        </a>
                        <button
                            type="button"
                            wire:click="toggleFavorite({{ $recipe->id }})"
                            class="absolute end-2 top-2 rounded-full bg-white/90 dark:bg-gray-900/80 px-2 py-1 text-lg leading-none shadow"
                            aria-pressed="{{ $recipe->favoritedBy->isNotEmpty() ? 'true' : 'false' }}"
                            aria-label="{{ __('Favorite') }}"
                        >{{ $recipe->favoritedBy->isNotEmpty() ? '♥' : '♡' }}</button>
                    </li>
                @empty
                    <li class="col-span-full rounded-lg bg-white dark:bg-gray-800 p-8 text-center text-gray-500 dark:text-gray-400">
                        {{ $filtered ? __('No recipes match your filter.') : ($archived ? __('The archive is empty.') : __('No recipes yet – add your first one!')) }}
                    </li>
                @endforelse
            </ul>
        </div>
    </div>
</div>
