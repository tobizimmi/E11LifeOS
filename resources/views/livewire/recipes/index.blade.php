<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Recipe;
use App\Models\RecipeCategory;
use App\Models\Tag;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Session;
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

    /**
     * "cards", "list" or "table"; remembered for the session.
     */
    #[Session]
    public string $view = 'cards';

    public function mount(): void
    {
        $this->pantryMode = $this->pantry !== '';

        if (! in_array($this->view, ['cards', 'list', 'table'], true)) {
            $this->view = 'cards';
        }
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
            ->with(['categories', 'shareGroups', 'creator', 'favoritedBy' => fn ($query) => $query->whereKey($user->id)])
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
                <a href="{{ route('recipes.create') }}" wire:navigate class="inline-flex items-center px-4 py-2 bg-indigo-600 dark:bg-indigo-500 rounded-md font-semibold text-sm text-white hover:bg-indigo-500 dark:hover:bg-indigo-400">
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

            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ trans_choice(':count recipe|:count recipes', $recipes->count()) }}</p>
                <div class="inline-flex rounded-lg bg-gray-100 p-0.5 text-sm dark:bg-gray-800" role="group" aria-label="{{ __('View') }}">
                    @foreach (['cards' => '▦ '.__('Cards'), 'list' => '☰ '.__('List'), 'table' => '▤ '.__('Table')] as $key => $label)
                        <button type="button" wire:click="$set('view', '{{ $key }}')" aria-pressed="{{ $view === $key ? 'true' : 'false' }}" @class(['rounded-md px-2.5 py-1', 'bg-white font-medium text-gray-900 shadow-sm dark:bg-gray-700 dark:text-gray-100' => $view === $key, 'text-gray-600 dark:text-gray-400' => $view !== $key])>{{ $label }}</button>
                    @endforeach
                </div>
            </div>

            @if ($view === 'list')
                <ul class="divide-y divide-gray-100 overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200/60 dark:divide-gray-700 dark:bg-gray-800 dark:ring-gray-700" wire:loading.class="opacity-50">
                    @forelse ($recipes as $recipe)
                        <li wire:key="recipe-list-{{ $recipe->id }}" class="flex items-center gap-3 px-3 py-2">
                            <a href="{{ route('recipes.show', $recipe) }}" wire:navigate class="flex min-w-0 flex-1 items-center gap-3">
                                <span class="h-12 w-16 shrink-0 overflow-hidden rounded bg-gray-100 dark:bg-gray-700">
                                    @if ($recipe->imageUrl())
                                        <img src="{{ $recipe->imageUrl() }}" alt="" loading="lazy" class="h-full w-full object-cover" />
                                    @else
                                        <span class="flex h-full items-center justify-center text-2xl" aria-hidden="true">{{ $recipe->categories->first()?->icon ?: '🍽️' }}</span>
                                    @endif
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-medium text-gray-900 dark:text-gray-100">{{ $recipe->title }}</span>
                                    <span class="flex flex-wrap gap-x-3 text-xs text-gray-500 dark:text-gray-400">
                                        @if ($recipe->totalTime())<span>⏱ {{ $recipe->totalTime() }} {{ __('min') }}</span>@endif
                                        @if ($recipe->servings)<span>🍽 {{ trans_choice(':count serving|:count servings', $recipe->servings) }}</span>@endif
                                        @if ($recipe->ratings_avg_rating)<span class="text-amber-600 dark:text-amber-400">★ {{ number_format((float) $recipe->ratings_avg_rating, 1, ',', '') }}</span>@endif
                                        @if (isset($pantryMatches[$recipe->id]))<span class="text-emerald-700 dark:text-emerald-300">{{ $pantryMatches[$recipe->id]['percent'] }} % {{ __('at home') }}</span>@endif
                                        @if ($recipe->categories->isNotEmpty())<span class="truncate">{{ $recipe->categories->map(fn ($c) => trim($c->icon.' '.$c->name))->implode(' · ') }}</span>@endif
                                    </span>
                                </span>
                                <x-audience-badge :model="$recipe" quiet />
                            </a>
                            <button type="button" wire:click="toggleFavorite({{ $recipe->id }})" class="shrink-0 px-1 text-lg leading-none" aria-pressed="{{ $recipe->favoritedBy->isNotEmpty() ? 'true' : 'false' }}" aria-label="{{ __('Favorite') }}">{{ $recipe->favoritedBy->isNotEmpty() ? '♥' : '♡' }}</button>
                        </li>
                    @empty
                        <li class="p-8 text-center text-gray-500 dark:text-gray-400">{{ $filtered ? __('No recipes match your filter.') : ($archived ? __('The archive is empty.') : __('No recipes yet – add your first one!')) }}</li>
                    @endforelse
                </ul>
            @elseif ($view === 'table')
                <div class="overflow-x-auto rounded-lg bg-white shadow-sm ring-1 ring-gray-200/60 dark:bg-gray-800 dark:ring-gray-700" wire:loading.class="opacity-50">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-start text-xs uppercase tracking-wide text-gray-500 dark:bg-gray-900/40 dark:text-gray-400">
                            <tr>
                                <th class="px-3 py-2 text-start font-medium">{{ __('Title') }}</th>
                                <th class="px-3 py-2 text-start font-medium">{{ __('Categories') }}</th>
                                <th class="px-3 py-2 text-end font-medium">{{ __('Total time') }}</th>
                                <th class="px-3 py-2 text-end font-medium">{{ __('Servings') }}</th>
                                <th class="px-3 py-2 text-end font-medium">{{ __('Rating') }}</th>
                                <th class="px-3 py-2 text-end font-medium">{{ __('Cooked') }}</th>
                                <th class="px-3 py-2 text-end font-medium">{{ __('Added') }}</th>
                                <th class="px-3 py-2"><span class="sr-only">{{ __('Favorite') }}</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse ($recipes as $recipe)
                                <tr wire:key="recipe-row-{{ $recipe->id }}" class="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                    <td class="max-w-xs px-3 py-2"><a href="{{ route('recipes.show', $recipe) }}" wire:navigate class="block truncate font-medium text-gray-900 hover:underline dark:text-gray-100">{{ $recipe->title }}</a></td>
                                    <td class="max-w-[14rem] truncate px-3 py-2 text-gray-600 dark:text-gray-400">{{ $recipe->categories->map(fn ($c) => trim($c->icon.' '.$c->name))->implode(' · ') }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-end tabular-nums text-gray-600 dark:text-gray-400">{{ $recipe->totalTime() ? $recipe->totalTime().' '.__('min') : '–' }}</td>
                                    <td class="px-3 py-2 text-end tabular-nums text-gray-600 dark:text-gray-400">{{ $recipe->servings ?: '–' }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-end tabular-nums text-amber-600 dark:text-amber-400">{{ $recipe->ratings_avg_rating ? '★ '.number_format((float) $recipe->ratings_avg_rating, 1, ',', '') : '–' }}</td>
                                    <td class="px-3 py-2 text-end tabular-nums text-gray-600 dark:text-gray-400">{{ $recipe->cooked_count ?: '–' }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-end tabular-nums text-gray-600 dark:text-gray-400">{{ $recipe->created_at?->format('d.m.Y') }}</td>
                                    <td class="px-3 py-2 text-end"><button type="button" wire:click="toggleFavorite({{ $recipe->id }})" class="text-lg leading-none" aria-pressed="{{ $recipe->favoritedBy->isNotEmpty() ? 'true' : 'false' }}" aria-label="{{ __('Favorite') }}">{{ $recipe->favoritedBy->isNotEmpty() ? '♥' : '♡' }}</button></td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="p-8 text-center text-gray-500 dark:text-gray-400">{{ $filtered ? __('No recipes match your filter.') : ($archived ? __('The archive is empty.') : __('No recipes yet – add your first one!')) }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @else
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
                                    <x-audience-badge :model="$recipe" quiet />
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
            @endif
        </div>
    </div>
</div>
