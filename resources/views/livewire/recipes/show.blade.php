<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Item;
use App\Models\MealPlan;
use App\Models\Recipe;
use App\Models\ShoppingList;
use App\Models\ShoppingListEntry;
use App\Support\IngredientUnits;
use App\Support\Pantry;
use App\Support\ShoppingLists;
use App\Support\RecipeAi;
use App\Support\RecipeAiException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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

    /**
     * What cooking takes from the pantry (shown for confirmation after "Cooked it").
     *
     * @var list<array{item_id: int, name: string, ingredient: string, before: float, used: float, after: float, unit: string, low: bool}>
     */
    #[Locked]
    public array $pantryUsage = [];

    /**
     * Ingredients covered by the pantry (indexes), shown in the shopping choice.
     *
     * @var list<int>
     */
    public array $inPantry = [];

    public ?int $shoppingListId = null;

    public bool $planning = false;

    public string $planDate = '';

    public string $planMealType = 'dinner';

    public bool $sharing = false;

    public int $myRating = 0;

    public string $myComment = '';

    public function mount(int $recipe): void
    {
        $this->resolveHousehold();

        $model = $this->findRecipe($recipe);
        $this->recipeId = $model->id;
        $this->servings = max(1, (int) $model->servings);
        $this->planDate = today()->toDateString();

        $ownRating = $model->ratings()->where('user_id', Auth::id())->first();
        $this->myRating = (int) $ownRating?->rating;
        $this->myComment = (string) $ownRating?->comment;
    }

    private function findRecipe(?int $id = null): Recipe
    {
        return Recipe::query()->visibleTo(Auth::user(), (int) $this->householdId)->findOrFail($id ?? $this->recipeId);
    }

    /**
     * Recipes of another household shared with a group: members may use them, not change them.
     */
    private function ownRecipe(): Recipe
    {
        $recipe = $this->findRecipe();
        abort_unless($recipe->household_id === $this->householdId, 403);

        return $recipe;
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
        $this->shoppingListId ??= ShoppingList::defaultFor((int) $this->householdId)->id;
        $onList = $this->openOnList();
        $recipe = $this->findRecipe();
        $this->inPantry = Pantry::covered($recipe, $this->servings, Auth::user(), (int) $this->householdId);
        // Items already open on the list or enough in the pantry start unticked.
        $this->selectedIngredients = collect($recipe->scaledIngredients($this->servings))
            ->reject(fn (array $ingredient, int $index) => $ingredient['isSection'] || in_array(IngredientUnits::nameKey($ingredient['name']), $onList, true) || in_array($index, $this->inPantry, true))
            ->keys()
            ->all();
        $this->choosingIngredients = true;
    }

    /**
     * Lower-case names of the open items on the chosen shopping list.
     *
     * @return list<string>
     */
    private function openOnList(): array
    {
        return ShoppingListEntry::where('household_id', $this->householdId)->where('shopping_list_id', $this->shoppingListId)->where('checked', false)
            ->pluck('label')->map(fn (string $label) => IngredientUnits::nameKey($label))->all();
    }

    /**
     * Adds the selected ingredients (scaled to the chosen servings) to the chosen shopping list;
     * items already on it get the amount added.
     */
    public function addToShoppingList(): void
    {
        $list = ShoppingList::where('household_id', $this->householdId)->find($this->shoppingListId) ?? ShoppingList::defaultFor((int) $this->householdId);
        $recipe = $this->findRecipe();
        $ingredients = $recipe->scaledIngredients($this->servings);
        $added = 0;

        foreach ($this->selectedIngredients as $index) {
            $ingredient = $ingredients[(int) $index] ?? null;

            if (! $ingredient || $ingredient['isSection']) {
                continue;
            }

            ShoppingLists::add($list, $ingredient['name'], trim($ingredient['amount'].' '.$ingredient['unit']) ?: null, Auth::id(), ['note' => mb_substr($recipe->title, 0, 200)]);
            $added++;
        }

        $this->choosingIngredients = false;
        $this->dispatch('toast', message: trans_choice(':count item added to the shopping list.|:count items added to the shopping list.', $added).' ('.$list->name.')');
    }

    /**
     * Puts the recipe on the household meal plan (private recipes stay private there).
     */
    public function planMeal(): void
    {
        $this->validate([
            'planDate' => ['required', 'date'],
            'planMealType' => ['required', Rule::in(MealPlan::MEAL_TYPES)],
        ]);

        $recipe = $this->findRecipe();

        MealPlan::create([
            'household_id' => $this->householdId,
            'created_by' => Auth::id(),
            'visibility' => $recipe->isPrivate() ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED,
            'date' => $this->planDate,
            'meal_type' => $this->planMealType,
            'recipe_id' => $recipe->id,
            'title' => $recipe->title,
            'servings' => $this->servings,
        ]);

        $this->planning = false;
        $this->dispatch('toast', message: __('Planned for :day.', [
            'day' => Carbon::parse($this->planDate)->translatedFormat('D, d.m.').' · '.MealPlan::mealTypeLabel($this->planMealType),
        ]));
    }

    public function saveRating(): void
    {
        $this->validate([
            'myRating' => ['required', 'integer', 'min:1', 'max:5'],
            'myComment' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->findRecipe()->rateBy(Auth::user(), $this->myRating, $this->myComment);
        $this->dispatch('toast', message: __('Rating saved.'));
    }

    public function removeRating(): void
    {
        $this->findRecipe()->ratings()->where('user_id', Auth::id())->delete();
        $this->reset('myRating', 'myComment');
    }

    /**
     * Rough values per serving from the ingredient list (Claude API).
     */
    public function estimateNutrition(RecipeAi $ai): void
    {
        $recipe = $this->ownRecipe();

        try {
            $recipe->update(['nutrition' => $ai->estimateNutrition($recipe) + ['estimated' => true]]);
        } catch (RecipeAiException $exception) {
            $this->dispatch('toast', message: $exception->getMessage());
        }
    }

    public function createPublicLink(): void
    {
        $recipe = $this->ownRecipe();

        if (! $recipe->public_token) {
            $recipe->update(['public_token' => Str::random(40)]);
        }

        $this->sharing = true;
    }

    /**
     * Validity of the share link in days from now; empty = unlimited.
     */
    public function setPublicLinkExpiry(string $days = ''): void
    {
        abort_unless(in_array($days, ['', '7', '30', '90'], true), 422);
        $this->ownRecipe()->forceFill(['public_expires_at' => $days === '' ? null : now()->addDays((int) $days)])->save();
    }

    public function revokePublicLink(): void
    {
        $this->ownRecipe()->update(['public_token' => null, 'public_expires_at' => null]);
        $this->dispatch('toast', message: __('The public link no longer works.'));
    }

    public function duplicate(): void
    {
        $copy = $this->findRecipe()->duplicateFor(Auth::user(), (int) $this->householdId);

        $this->redirectRoute('recipes.edit', ['recipe' => $copy->id], navigate: true);
    }

    public function markCooked(): void
    {
        $recipe = $this->findRecipe();
        $recipe->markCooked();
        // Ingredients from the pantry: shown for confirmation, nothing is taken without it.
        $this->pantryUsage = Pantry::usage($recipe, $this->servings, Auth::user(), (int) $this->householdId);

        if ($this->pantryUsage === []) {
            $this->dispatch('toast', message: __('Enjoy your meal!'));
        }
    }

    public function skipPantry(): void
    {
        $this->pantryUsage = [];
    }

    /**
     * Takes the ingredients of the cooked recipe out of the pantry; items now low go onto the shopping list on request.
     */
    public function takeFromPantry(bool $addLowToList = false): void
    {
        $low = Pantry::take($this->pantryUsage, (int) $this->householdId);
        $this->pantryUsage = [];

        if ($addLowToList && $low !== []) {
            $list = ShoppingList::defaultFor((int) $this->householdId);

            foreach ($low as $name) {
                ShoppingLists::add($list, $name, null, Auth::id(), ['note' => __('Pantry')]);
            }
        }

        $this->dispatch('toast', message: __('Enjoy your meal!').' '.__('The pantry is updated.').($addLowToList && $low !== [] ? ' '.trans_choice(':count item is on the shopping list.|:count items are on the shopping list.', count($low)) : ''));
    }

    public function toggleArchive(): void
    {
        $recipe = $this->ownRecipe();
        $recipe->update(['archived_at' => $recipe->isArchived() ? null : now()]);
    }

    public function delete(): void
    {
        $this->ownRecipe()->delete();

        $this->redirectRoute('recipes.index', navigate: true);
    }

    public function with(): array
    {
        $recipe = $this->findRecipe()->load(['categories', 'tags', 'creator', 'ratings.user', 'shareGroups']);

        return [
            'recipe' => $recipe,
            'foreign' => $recipe->household_id !== $this->householdId,
            'averageRating' => $recipe->averageRating(),
            'otherRatings' => $recipe->ratings->where('user_id', '!=', Auth::id())->sortByDesc('updated_at')->values(),
            'photos' => $recipe->additionalImageUrls(),
            'nutritionValues' => $recipe->nutritionValues(),
            'aiAvailable' => RecipeAi::isConfigured(),
            'upcomingPlans' => MealPlan::query()->visibleTo(Auth::user(), (int) $this->householdId)
                ->where('recipe_id', $recipe->id)
                ->whereDate('date', '>=', today())
                ->orderBy('date')
                ->limit(3)
                ->get(),
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
                            @if ($averageRating)
                                <a href="#ratings" class="mt-1 inline-flex items-center gap-1 text-sm text-gray-600 dark:text-gray-400">
                                    <span class="text-amber-500" aria-hidden="true">{{ str_repeat('★', (int) round($averageRating)) }}<span class="text-gray-300 dark:text-gray-600">{{ str_repeat('★', 5 - (int) round($averageRating)) }}</span></span>
                                    <span>{{ number_format($averageRating, 1, ',', '') }} ({{ trans_choice(':count rating|:count ratings', $recipe->ratings->count()) }})</span>
                                </a>
                            @endif
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <x-audience-badge :model="$recipe" />
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
                            @if ($steps !== [])
                                <a href="{{ route('recipes.cook', ['recipe' => $recipe, 'servings' => $servings]) }}" wire:navigate class="rounded-md bg-indigo-600 px-3 py-1.5 font-medium text-white hover:bg-indigo-700">👩‍🍳 {{ __('Cooking mode') }}</a>
                            @endif
                            <button type="button" wire:click="$toggle('planning')" class="rounded-md border border-gray-300 px-3 py-1.5 text-gray-700 dark:border-gray-600 dark:text-gray-200" aria-expanded="{{ $planning ? 'true' : 'false' }}">📅 {{ __('Plan') }}</button>
                            <button type="button" wire:click="toggleFavorite" class="rounded-md border border-gray-300 px-3 py-1.5 text-gray-700 dark:border-gray-600 dark:text-gray-200" aria-pressed="{{ $isFavorite ? 'true' : 'false' }}">{{ $isFavorite ? '♥' : '♡' }} {{ __('Favorite') }}</button>
                            @unless ($foreign)
                                <a href="{{ route('recipes.edit', $recipe) }}" wire:navigate class="rounded-md border border-gray-300 px-3 py-1.5 text-gray-700 dark:border-gray-600 dark:text-gray-200">✎ {{ __('Edit') }}</a>
                            @endunless
                            <div x-data="{ open: false }" class="relative">
                                <button type="button" x-on:click="open = ! open" class="rounded-md border border-gray-300 px-3 py-1.5 text-gray-700 dark:border-gray-600 dark:text-gray-200" aria-label="{{ __('More') }}">⋯</button>
                                <div x-show="open" x-cloak x-on:click.outside="open = false" class="absolute end-0 z-10 mt-1 w-48 rounded-md bg-white dark:bg-gray-800 py-1 shadow-lg ring-1 ring-gray-200 dark:ring-gray-700">
                                    <a href="{{ route('recipes.pdf', ['recipe' => $recipe, 'servings' => $servings]) }}" class="block w-full px-3 py-1.5 text-start hover:bg-gray-100 dark:hover:bg-gray-700">📄 {{ __('Download as PDF') }}</a>
                                    @if ($foreign)
                                        <button type="button" wire:click="duplicate" class="block w-full px-3 py-1.5 text-start hover:bg-gray-100 dark:hover:bg-gray-700">{{ __('Save a copy to my recipes') }}</button>
                                    @else
                                        <button type="button" wire:click="$toggle('sharing')" class="block w-full px-3 py-1.5 text-start hover:bg-gray-100 dark:hover:bg-gray-700">🔗 {{ __('Public link') }}</button>
                                        <button type="button" wire:click="duplicate" class="block w-full px-3 py-1.5 text-start hover:bg-gray-100 dark:hover:bg-gray-700">{{ __('Duplicate') }}</button>
                                        <button type="button" wire:click="toggleArchive" class="block w-full px-3 py-1.5 text-start hover:bg-gray-100 dark:hover:bg-gray-700">{{ $recipe->isArchived() ? __('Restore from archive') : __('Move to archive') }}</button>
                                        <button type="button" wire:click="delete" wire:confirm="{{ __('Delete this recipe?') }}" class="block w-full px-3 py-1.5 text-start text-red-600 dark:text-red-400 hover:bg-gray-100 dark:hover:bg-gray-700">{{ __('Delete') }}</button>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4"><livewire:sharing.panel type="recipe" :id="$recipe->id" :wire:key="'share-recipe-'.$recipe->id" /></div>

                    @if ($sharing && ! $foreign)
                        <div class="rounded-md bg-emerald-50 dark:bg-emerald-900/30 p-3 space-y-2 text-sm">
                            @if ($recipe->publicUrl())
                                <p class="text-gray-700 dark:text-gray-300">{{ __('Anyone with this link can view the recipe (without ratings, notes or the meal plan).') }}</p>
                                <div class="flex flex-wrap items-center gap-2" x-data="{ copied: false }">
                                    <x-text-input readonly value="{{ $recipe->publicUrl() }}" class="block flex-1 min-w-0 text-sm" aria-label="{{ __('Public link') }}" x-on:focus="$el.select()" />
                                    <button type="button" x-on:click="navigator.clipboard.writeText(@js($recipe->publicUrl())).then(() => { copied = true; setTimeout(() => copied = false, 2000) })" class="rounded-md bg-emerald-600 px-3 py-2 font-medium text-white hover:bg-emerald-700" x-text="copied ? @js(__('Copied!')) : @js(__('Copy'))"></button>
                                    <button type="button" wire:click="revokePublicLink" wire:confirm="{{ __('Turn off the public link?') }}" class="text-red-600 dark:text-red-400 underline">{{ __('Turn off link') }}</button>
                                </div>
                                <x-share-expiry :expires="$recipe->public_expires_at" action="setPublicLinkExpiry" class="mt-2" />
                            @else
                                <p class="text-gray-700 dark:text-gray-300">{{ __('Share the recipe with people outside the household via a link. You can turn it off at any time.') }}</p>
                                <button type="button" wire:click="createPublicLink" class="rounded-md bg-emerald-600 px-3 py-2 font-medium text-white hover:bg-emerald-700">🔗 {{ __('Create public link') }}</button>
                            @endif
                        </div>
                    @endif

                    @if ($planning)
                        <form wire:submit="planMeal" class="flex flex-wrap items-end gap-3 rounded-md bg-indigo-50 dark:bg-indigo-900/30 p-3">
                            <div>
                                <x-input-label for="planDate" :value="__('Date')" />
                                <x-text-input wire:model="planDate" id="planDate" type="date" class="mt-1 block" required />
                            </div>
                            <div>
                                <x-input-label for="planMealType" :value="__('Meal')" />
                                <select wire:model="planMealType" id="planMealType" class="mt-1 block rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm">
                                    @foreach (\App\Models\MealPlan::MEAL_TYPES as $mealType)
                                        <option value="{{ $mealType }}">{{ \App\Models\MealPlan::mealTypeLabel($mealType) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <p class="pb-2 text-sm text-gray-600 dark:text-gray-400">{{ trans_choice(':count serving|:count servings', $servings) }}</p>
                            <x-primary-button>{{ __('Add to meal plan') }}</x-primary-button>
                            <a href="{{ route('recipes.plan') }}" wire:navigate class="pb-2 text-sm text-indigo-700 dark:text-indigo-300 underline">{{ __('Open meal plan') }}</a>
                            <x-input-error :messages="$errors->get('planDate')" class="w-full" />
                        </form>
                    @endif

                    @if ($upcomingPlans->isNotEmpty())
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            📅 {{ __('On the meal plan') }}:
                            {{ $upcomingPlans->map(fn ($plan) => $plan->date->translatedFormat('D, d.m.').' ('.\App\Models\MealPlan::mealTypeLabel($plan->meal_type).')')->implode(', ') }}
                        </p>
                    @endif

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
                            @php
                                $shoppingLists = \App\Models\ShoppingList::where('household_id', $this->householdId)->orderBy('position')->orderBy('id')->get();
                                $alreadyOnList = \App\Models\ShoppingListEntry::where('shopping_list_id', $shoppingListId)->where('checked', false)->pluck('label')->map(fn ($label) => \App\Support\IngredientUnits::nameKey($label))->all();
                            @endphp
                            @if ($shoppingLists->count() > 1)
                                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                                    {{ __('List') }}
                                    <select wire:model.live="shoppingListId" class="rounded-md border-gray-300 py-1 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                                        @foreach ($shoppingLists as $shoppingList)
                                            <option value="{{ $shoppingList->id }}">{{ $shoppingList->icon }} {{ $shoppingList->name }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            @endif
                            @foreach ($ingredients as $index => $ingredient)
                                @if ($ingredient['isSection'])
                                    <p class="pt-2 text-xs font-semibold uppercase text-gray-500">{{ $ingredient['name'] }}</p>
                                @else
                                    <label class="flex items-center gap-2 text-sm text-gray-800 dark:text-gray-200" wire:key="choose-{{ $index }}">
                                        <input type="checkbox" wire:model="selectedIngredients" value="{{ $index }}" class="rounded border-gray-300 dark:border-gray-600 text-indigo-600" />
                                        <span><span class="font-medium">{{ trim($ingredient['amount'].' '.$ingredient['unit']) }}</span> {{ $ingredient['name'] }}
                                            @if (in_array(\App\Support\IngredientUnits::nameKey($ingredient['name']), $alreadyOnList, true))
                                                <span class="ms-1 rounded bg-indigo-50 px-1 text-xs text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300">🛒 {{ __('already on the list') }}</span>
                                            @elseif (in_array($index, $inPantry, true))
                                                <span class="ms-1 rounded bg-emerald-50 px-1 text-xs text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">🥫 {{ __('in the pantry') }}</span>
                                            @endif
                                        </span>
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
                    @if ($pantryUsage !== [])
                        <div class="mt-4 rounded-2xl bg-emerald-50 p-4 ring-1 ring-emerald-100 dark:bg-emerald-900/20 dark:ring-emerald-900/40">
                            <p class="text-sm font-semibold text-emerald-900 dark:text-emerald-200">🥫 {{ __('Take from the pantry?') }}</p>
                            <ul class="mt-2 space-y-1 text-sm text-emerald-900 dark:text-emerald-100">
                                @foreach ($pantryUsage as $use)
                                    <li class="flex flex-wrap justify-between gap-2" wire:key="pantry-use-{{ $use['item_id'] }}">
                                        <span>{{ $use['name'] }}</span>
                                        <span class="tabular-nums">{{ \App\Support\IngredientAmount::format($use['before']) }} → <strong>{{ \App\Support\IngredientAmount::format($use['after']) }}</strong> {{ $use['unit'] }} @if ($use['low'])<span class="ms-1 rounded bg-amber-100 px-1 text-xs text-amber-800 dark:bg-amber-900/50 dark:text-amber-200">{{ __('low') }}</span>@endif</span>
                                    </li>
                                @endforeach
                            </ul>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <x-primary-button type="button" wire:click="takeFromPantry">{{ __('Take from the pantry') }}</x-primary-button>
                                @if (collect($pantryUsage)->contains('low', true))
                                    <x-secondary-button type="button" wire:click="takeFromPantry(true)">{{ __('Take and put low items on the list') }}</x-secondary-button>
                                @endif
                                <button type="button" wire:click="skipPantry" class="text-sm text-gray-500 underline">{{ __('Not now') }}</button>
                            </div>
                        </div>
                    @endif
                </section>
            </div>

            @if ($nutritionValues !== [] || ($aiAvailable && collect($ingredients)->contains('isSection', false)))
                <section class="rounded-lg bg-white dark:bg-gray-800 p-5 shadow-sm">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                            {{ __('Nutrition per serving') }}
                            @if (($recipe->nutrition['estimated'] ?? false) && $nutritionValues !== [])
                                <span class="text-sm font-normal text-gray-500">({{ __('estimated') }})</span>
                            @endif
                        </h2>
                        @if ($aiAvailable && ! $foreign && collect($ingredients)->contains('isSection', false))
                            <button type="button" wire:click="estimateNutrition" wire:loading.attr="disabled" class="text-sm text-indigo-600 dark:text-indigo-400 underline disabled:opacity-50">
                                <span wire:loading.remove wire:target="estimateNutrition">✨ {{ $nutritionValues === [] ? __('Estimate with AI') : __('Estimate again') }}</span>
                                <span wire:loading wire:target="estimateNutrition">{{ __('Estimating …') }}</span>
                            </button>
                        @endif
                    </div>
                    @if ($nutritionValues !== [])
                        <dl class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-5">
                            @foreach ($nutritionValues as $key => $value)
                                <div class="rounded-md bg-gray-50 dark:bg-gray-900/60 p-3 text-center">
                                    <dt class="text-xs text-gray-500 dark:text-gray-400">{{ \App\Models\Recipe::nutritionLabel($key)[0] }}</dt>
                                    <dd class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ str_replace('.', ',', (string) $value) }}<span class="text-sm font-normal text-gray-500"> {{ \App\Models\Recipe::nutritionLabel($key)[1] }}</span></dd>
                                </div>
                            @endforeach
                        </dl>
                    @else
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('No nutrition values yet. Enter them when editing or let the AI estimate them from the ingredients.') }}</p>
                    @endif
                </section>
            @endif

            @if ($photos !== [])
                <section class="rounded-lg bg-white dark:bg-gray-800 p-5 shadow-sm" x-data="{ open: null, photos: @js(array_values($photos)) }">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('More photos') }}</h2>
                    <div class="mt-3 grid grid-cols-3 gap-2 sm:grid-cols-4 md:grid-cols-6">
                        @foreach (array_values($photos) as $photoIndex => $photoUrl)
                            <button type="button" x-on:click="open = {{ $photoIndex }}" class="aspect-square overflow-hidden rounded-md" aria-label="{{ __('Show photo :number', ['number' => $photoIndex + 1]) }}">
                                <img src="{{ $photoUrl }}" alt="" loading="lazy" class="h-full w-full object-cover hover:opacity-90" />
                            </button>
                        @endforeach
                    </div>
                    <div
                        x-show="open !== null"
                        x-cloak
                        x-on:keydown.escape.window="open = null"
                        x-on:keydown.arrow-right.window="open !== null && (open = (open + 1) % photos.length)"
                        x-on:keydown.arrow-left.window="open !== null && (open = (open + photos.length - 1) % photos.length)"
                        x-on:click.self="open = null"
                        class="fixed inset-0 z-[60] flex items-center justify-center bg-black/90 p-4"
                        role="dialog"
                        aria-modal="true"
                    >
                        <img :src="open !== null ? photos[open] : ''" alt="" class="max-h-full max-w-full rounded-md object-contain" />
                        <button type="button" x-on:click="open = null" class="absolute end-4 top-4 text-3xl text-white" aria-label="{{ __('Close') }}"><x-icon name="x-mark" class="h-4 w-4" stroke-width="2" /></button>
                        <template x-if="photos.length > 1">
                            <div>
                                <button type="button" x-on:click="open = (open + photos.length - 1) % photos.length" class="absolute start-2 top-1/2 -translate-y-1/2 px-3 text-4xl text-white" aria-label="{{ __('Previous photo') }}">‹</button>
                                <button type="button" x-on:click="open = (open + 1) % photos.length" class="absolute end-2 top-1/2 -translate-y-1/2 px-3 text-4xl text-white" aria-label="{{ __('Next photo') }}">›</button>
                            </div>
                        </template>
                    </div>
                </section>
            @endif

            @unless ($foreign)
                <div class="rounded-lg bg-white dark:bg-gray-800 p-5 shadow-sm">
                    <livewire:links-panel type="recipe" :id="$recipe->id" :wire:key="'links-recipe-'.$recipe->id" />
                </div>
            @endunless

            {{-- Ratings --}}
            <section id="ratings" class="rounded-lg bg-white dark:bg-gray-800 p-5 shadow-sm space-y-4">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('Ratings') }}</h2>
                    @if ($averageRating)
                        <p class="text-sm text-gray-600 dark:text-gray-400">Ø {{ number_format($averageRating, 1, ',', '') }} · {{ trans_choice(':count rating|:count ratings', $recipe->ratings->count()) }}</p>
                    @endif
                </div>

                <form wire:submit="saveRating" class="space-y-2">
                    <div class="flex items-center gap-1" role="radiogroup" aria-label="{{ __('Your rating') }}">
                        <span class="me-2 text-sm text-gray-600 dark:text-gray-400">{{ __('Your rating') }}:</span>
                        @for ($star = 1; $star <= 5; $star++)
                            <button type="button" wire:click="$set('myRating', {{ $star }})" role="radio" aria-checked="{{ $myRating === $star ? 'true' : 'false' }}" aria-label="{{ trans_choice(':count star|:count stars', $star) }}" @class(['text-2xl leading-none', 'text-amber-500' => $star <= $myRating, 'text-gray-300 dark:text-gray-600' => $star > $myRating])>★</button>
                        @endfor
                    </div>
                    <x-input-error :messages="$errors->get('myRating')" />
                    <textarea wire:model="myComment" rows="2" class="block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm text-sm" placeholder="{{ __('Note for next time (optional)') }}" aria-label="{{ __('Comment') }}"></textarea>
                    <div class="flex items-center gap-3">
                        <x-secondary-button type="submit">{{ __('Save rating') }}</x-secondary-button>
                        @if ($recipe->ratings->contains('user_id', auth()->id()))
                            <button type="button" wire:click="removeRating" class="text-sm text-gray-500 underline">{{ __('Remove my rating') }}</button>
                        @endif
                    </div>
                </form>

                @if ($otherRatings->isNotEmpty())
                    <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($otherRatings as $otherRating)
                            <li wire:key="rating-{{ $otherRating->id }}" class="py-2 text-sm">
                                <p class="flex flex-wrap items-center gap-2">
                                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ $otherRating->user?->name ?? __('Former member') }}</span>
                                    <span class="text-amber-500" aria-label="{{ trans_choice(':count star|:count stars', $otherRating->rating) }}">{{ str_repeat('★', $otherRating->rating) }}<span class="text-gray-300 dark:text-gray-600">{{ str_repeat('★', 5 - $otherRating->rating) }}</span></span>
                                    @if ($otherRating->cooked_at)
                                        <span class="text-xs text-gray-500">{{ __('cooked on :date', ['date' => $otherRating->cooked_at->format('d.m.Y')]) }}</span>
                                    @endif
                                </p>
                                @if ($otherRating->comment)
                                    <p class="mt-1 whitespace-pre-line text-gray-700 dark:text-gray-300">{{ $otherRating->comment }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

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
