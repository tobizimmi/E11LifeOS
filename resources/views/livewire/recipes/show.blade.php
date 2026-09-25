<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Recipe;
use App\Models\ShoppingListEntry;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    #[Locked]
    public int $recipeId;

    /**
     * Servings the ingredient list is scaled to (portion calculator).
     */
    public int $servings = 1;

    /**
     * Indexes of the scaled ingredient list that go onto the shopping list.
     *
     * @var array<int, int>
     */
    public array $selectedIngredients = [];

    public bool $choosingIngredients = false;

    public function mount(int $recipe): void
    {
        $this->resolveHousehold();

        $model = $this->findRecipe($recipe);
        $this->recipeId = $model->id;
        $this->servings = max(1, (int) $model->servings);
    }

    private function findRecipe(?int $id = null): Recipe
    {
        return Recipe::query()->visibleTo(Auth::user(), (int) $this->householdId)->findOrFail($id ?? $this->recipeId);
    }

    public function changeServings(int $delta): void
    {
        $this->servings = max(1, min(99, $this->servings + $delta));
    }

    public function toggleFavorite(): void
    {
        $this->findRecipe()->favoritedBy()->toggle([Auth::id() => ['created_at' => now()]]);
    }

    public function chooseIngredients(): void
    {
        $this->selectedIngredients = collect($this->findRecipe()->scaledIngredients($this->servings))
            ->reject(fn (array $ingredient) => $ingredient['isSection'])
            ->keys()
            ->all();
        $this->choosingIngredients = true;
    }

    /**
     * Adds the selected ingredients (scaled to the chosen servings) to the household shopping list.
     */
    public function addToShoppingList(): void
    {
        $recipe = $this->findRecipe();
        $ingredients = $recipe->scaledIngredients($this->servings);
        $added = 0;

        foreach ($this->selectedIngredients as $index) {
            $ingredient = $ingredients[(int) $index] ?? null;

            if (! $ingredient || $ingredient['isSection']) {
                continue;
            }

            ShoppingListEntry::create([
                'household_id' => $this->householdId,
                'label' => mb_substr($ingredient['name'], 0, 255),
                'quantity' => trim($ingredient['amount'].' '.$ingredient['unit']) ?: null,
                'created_by' => Auth::id(),
            ]);
            $added++;
        }

        $this->choosingIngredients = false;
        $this->dispatch('toast', message: trans_choice(':count item added to the shopping list.|:count items added to the shopping list.', $added));
    }

    public function markCooked(): void
    {
        $this->findRecipe()->increment('cooked_count');
        $this->dispatch('toast', message: __('Enjoy your meal!'));
    }

    public function toggleArchive(): void
    {
        $recipe = $this->findRecipe();
        $recipe->update(['archived_at' => $recipe->isArchived() ? null : now()]);
    }

    public function delete(): void
    {
        $this->findRecipe()->delete();

        $this->redirectRoute('recipes.index', navigate: true);
    }

    public function with(): array
    {
        $recipe = $this->findRecipe()->load(['categories', 'tags', 'creator']);

        return [
            'recipe' => $recipe,
            'ingredients' => $recipe->scaledIngredients($this->servings),
            'steps' => $recipe->steps(),
            'isFavorite' => $recipe->isFavoriteOf(Auth::user()),
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
        <article class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            <a href="{{ route('recipes.index') }}" wire:navigate class="text-sm text-gray-500 dark:text-gray-400 hover:underline">&larr; {{ __('All recipes') }}</a>

            <div class="overflow-hidden rounded-lg bg-white dark:bg-gray-800 shadow-sm">
                @if ($recipe->imageUrl())
                    <img src="{{ $recipe->imageUrl() }}" alt="{{ $recipe->title }}" class="max-h-[26rem] w-full object-cover" />
                @endif

                <div class="p-5 sm:p-6 space-y-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100">{{ $recipe->title }}</h1>
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                @if ($recipe->isPrivate())
                                    <x-private-badge />
                                @endif
                                @if ($recipe->isArchived())
                                    <span class="rounded-full bg-gray-200 dark:bg-gray-700 px-2 py-0.5 text-xs text-gray-700 dark:text-gray-300">{{ __('Archived') }}</span>
                                @endif
                                @foreach ($recipe->categories as $recipeCategory)
                                    <a href="{{ route('recipes.index', ['category' => $recipeCategory->id]) }}" wire:navigate class="rounded-full bg-indigo-50 dark:bg-indigo-900/40 px-2 py-0.5 text-xs text-indigo-700 dark:text-indigo-200">{{ $recipeCategory->icon }} {{ $recipeCategory->name }}</a>
                                @endforeach
                                @foreach ($recipe->tags->sortBy('name') as $recipeTag)
                                    <a href="{{ route('recipes.index', ['tag' => $recipeTag->name]) }}" wire:navigate class="rounded-full bg-gray-100 dark:bg-gray-700 px-2 py-0.5 text-xs text-gray-600 dark:text-gray-300">#{{ $recipeTag->name }}</a>
                                @endforeach
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <button type="button" wire:click="toggleFavorite" class="rounded-md border border-gray-300 dark:border-gray-600 px-3 py-1.5" aria-pressed="{{ $isFavorite ? 'true' : 'false' }}">{{ $isFavorite ? '♥' : '♡' }} {{ __('Favorite') }}</button>
                            <a href="{{ route('recipes.edit', $recipe) }}" wire:navigate class="rounded-md border border-gray-300 dark:border-gray-600 px-3 py-1.5">✎ {{ __('Edit') }}</a>
                            <div x-data="{ open: false }" class="relative">
                                <button type="button" x-on:click="open = ! open" class="rounded-md border border-gray-300 dark:border-gray-600 px-3 py-1.5" aria-label="{{ __('More') }}">⋯</button>
                                <div x-show="open" x-cloak x-on:click.outside="open = false" class="absolute end-0 z-10 mt-1 w-48 rounded-md bg-white dark:bg-gray-800 py-1 shadow-lg ring-1 ring-gray-200 dark:ring-gray-700">
                                    <button type="button" wire:click="toggleArchive" class="block w-full px-3 py-1.5 text-start hover:bg-gray-100 dark:hover:bg-gray-700">{{ $recipe->isArchived() ? __('Restore from archive') : __('Move to archive') }}</button>
                                    <button type="button" wire:click="delete" wire:confirm="{{ __('Delete this recipe?') }}" class="block w-full px-3 py-1.5 text-start text-red-600 dark:text-red-400 hover:bg-gray-100 dark:hover:bg-gray-700">{{ __('Delete') }}</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    @if ($recipe->description)
                        <p class="whitespace-pre-line text-gray-700 dark:text-gray-300">{{ $recipe->description }}</p>
                    @endif

                    <dl class="flex flex-wrap gap-x-6 gap-y-2 text-sm text-gray-600 dark:text-gray-400">
                        @if ($recipe->prep_time)
                            <div><dt class="inline">{{ __('Prep') }}:</dt> <dd class="inline font-medium text-gray-900 dark:text-gray-100">{{ $recipe->prep_time }} {{ __('min') }}</dd></div>
                        @endif
                        @if ($recipe->cook_time)
                            <div><dt class="inline">{{ __('Cooking') }}:</dt> <dd class="inline font-medium text-gray-900 dark:text-gray-100">{{ $recipe->cook_time }} {{ __('min') }}</dd></div>
                        @endif
                        @if ($recipe->totalTime())
                            <div><dt class="inline">{{ __('Total') }}:</dt> <dd class="inline font-medium text-gray-900 dark:text-gray-100">{{ $recipe->totalTime() }} {{ __('min') }}</dd></div>
                        @endif
                        @if ($recipe->difficulty)
                            <div><dt class="inline">{{ __('Difficulty') }}:</dt> <dd class="inline font-medium text-gray-900 dark:text-gray-100">{{ $recipe->difficultyLabel() }}</dd></div>
                        @endif
                        @if ($recipe->cooked_count > 0)
                            <div><dt class="inline">{{ __('Cooked') }}:</dt> <dd class="inline font-medium text-gray-900 dark:text-gray-100">{{ trans_choice(':count time|:count times', $recipe->cooked_count) }}</dd></div>
                        @endif
                    </dl>
                </div>
            </div>

            <div class="grid gap-6 md:grid-cols-5">
                {{-- Ingredients --}}
                <section class="md:col-span-2 rounded-lg bg-white dark:bg-gray-800 p-5 shadow-sm">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('Ingredients') }}</h2>
                        <div class="flex items-center gap-1" role="group" aria-label="{{ __('Servings') }}">
                            <button type="button" wire:click="changeServings(-1)" class="h-8 w-8 rounded-full border border-gray-300 dark:border-gray-600" aria-label="{{ __('Fewer servings') }}">−</button>
                            <span class="min-w-[5.5rem] text-center text-sm text-gray-700 dark:text-gray-300" aria-live="polite">{{ trans_choice(':count serving|:count servings', $servings) }}</span>
                            <button type="button" wire:click="changeServings(1)" class="h-8 w-8 rounded-full border border-gray-300 dark:border-gray-600" aria-label="{{ __('More servings') }}">+</button>
                        </div>
                    </div>
                    @unless ($recipe->servings)
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Add the number of servings to the recipe to scale the amounts.') }}</p>
                    @endunless

                    @if ($choosingIngredients)
                        <form wire:submit="addToShoppingList" class="mt-3 space-y-2">
                            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Which ingredients do you need?') }}</p>
                            @foreach ($ingredients as $index => $ingredient)
                                @if ($ingredient['isSection'])
                                    <p class="pt-2 text-xs font-semibold uppercase text-gray-500">{{ $ingredient['name'] }}</p>
                                @else
                                    <label class="flex items-center gap-2 text-sm text-gray-800 dark:text-gray-200" wire:key="choose-{{ $index }}">
                                        <input type="checkbox" wire:model="selectedIngredients" value="{{ $index }}" class="rounded border-gray-300 dark:border-gray-600 text-indigo-600" />
                                        <span><span class="font-medium">{{ trim($ingredient['amount'].' '.$ingredient['unit']) }}</span> {{ $ingredient['name'] }}</span>
                                    </label>
                                @endif
                            @endforeach
                            <div class="flex items-center gap-3 pt-2">
                                <x-primary-button>{{ __('Add to shopping list') }}</x-primary-button>
                                <button type="button" wire:click="$set('choosingIngredients', false)" class="text-sm text-gray-500 underline">{{ __('Cancel') }}</button>
                            </div>
                        </form>
                    @else
                        <ul class="mt-3 space-y-1.5" x-data="{ ticked: [] }">
                            @forelse ($ingredients as $index => $ingredient)
                                @if ($ingredient['isSection'])
                                    <li class="pt-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400" wire:key="ingredient-{{ $index }}">{{ $ingredient['name'] }}</li>
                                @else
                                    <li wire:key="ingredient-{{ $index }}">
                                        {{-- Ticking off while cooking is local to this device only. --}}
                                        <label class="flex cursor-pointer items-baseline gap-2 text-gray-800 dark:text-gray-200" :class="ticked.includes({{ $index }}) && 'line-through text-gray-400'">
                                            <input type="checkbox" class="translate-y-0.5 rounded border-gray-300 dark:border-gray-600 text-indigo-600" x-on:change="ticked.includes({{ $index }}) ? ticked.splice(ticked.indexOf({{ $index }}), 1) : ticked.push({{ $index }})" />
                                            <span><span class="font-medium">{{ trim($ingredient['amount'].' '.$ingredient['unit']) }}</span> {{ $ingredient['name'] }}</span>
                                        </label>
                                    </li>
                                @endif
                            @empty
                                <li class="text-sm text-gray-500 dark:text-gray-400">{{ __('No ingredients yet.') }}</li>
                            @endforelse
                        </ul>
                        @if (collect($ingredients)->contains('isSection', false))
                            <button type="button" wire:click="chooseIngredients" class="mt-4 w-full rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">🛒 {{ __('Add to shopping list') }}</button>
                        @endif
                    @endif
                </section>

                {{-- Steps --}}
                <section class="md:col-span-3 rounded-lg bg-white dark:bg-gray-800 p-5 shadow-sm">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('Preparation') }}</h2>
                    <ol class="mt-3 space-y-4">
                        @forelse ($steps as $number => $step)
                            <li class="flex gap-3" wire:key="step-{{ $number }}">
                                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-indigo-600 text-sm font-semibold text-white">{{ $number + 1 }}</span>
                                <p class="whitespace-pre-line pt-0.5 text-gray-800 dark:text-gray-200">{{ $step }}</p>
                            </li>
                        @empty
                            <li class="text-sm text-gray-500 dark:text-gray-400">{{ __('No steps yet.') }}</li>
                        @endforelse
                    </ol>
                    <button type="button" wire:click="markCooked" class="mt-6 rounded-md border border-gray-300 dark:border-gray-600 px-3 py-1.5 text-sm">✅ {{ __('Cooked it') }}</button>
                </section>
            </div>

            @if ($recipe->source_url)
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('Source') }}:
                    @if (preg_match('#^https?://#i', $recipe->source_url))
                        <a href="{{ $recipe->source_url }}" target="_blank" rel="noopener noreferrer" class="underline break-all">{{ $recipe->source_url }}</a>
                    @else
                        {{ $recipe->source_url }}
                    @endif
                </p>
            @endif
        </article>
    </div>
</div>
