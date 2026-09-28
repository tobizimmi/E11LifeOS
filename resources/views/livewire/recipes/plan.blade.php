<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Item;
use App\Models\MealPlan;
use App\Models\Recipe;
use App\Models\ShoppingList;
use App\Support\ShoppingLists;
use App\Support\MealSuggestions;
use App\Support\ShoppingIngredients;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    /**
     * Any date of the shown week (Y-m-d); empty = this week.
     */
    #[Url(except: '')]
    public string $week = '';

    /**
     * Day the add dialog is open for (Y-m-d), null = closed.
     */
    public ?string $addingDate = null;

    public string $addingMealType = 'dinner';

    public string $recipeSearch = '';

    public string $customTitle = '';

    public ?int $addingServings = null;

    public bool $addingPrivate = false;

    public bool $choosingShopping = false;

    public ?int $shoppingListId = null;

    /**
     * Indexes of the combined week ingredients that go onto the shopping list.
     *
     * @var array<int, int>
     */
    public array $selectedShopping = [];

    /**
     * Suggested dinners for the free days of the week, by date.
     *
     * @var array<string, array{id: int, reasons: list<string>}>
     */
    public array $suggestions = [];

    private function weekStart(): Carbon
    {
        try {
            $date = $this->week !== '' ? Carbon::createFromFormat('!Y-m-d', $this->week) : null;
        } catch (Throwable) {
            $date = null;
        }

        return ($date ?: today())->startOfWeek(Carbon::MONDAY);
    }

    public function changeWeek(int $offset): void
    {
        $start = $this->weekStart()->addWeeks($offset);
        $this->week = $start->isSameDay(today()->startOfWeek(Carbon::MONDAY)) ? '' : $start->toDateString();
        $this->choosingShopping = false;
    }

    public function startAdding(string $date, string $mealType = 'dinner'): void
    {
        $this->resetValidation();
        $this->reset('recipeSearch', 'customTitle', 'addingServings', 'addingPrivate');
        $this->addingDate = Carbon::parse($date)->toDateString();
        $this->addingMealType = in_array($mealType, MealPlan::MEAL_TYPES, true) ? $mealType : 'dinner';
    }

    public function cancelAdding(): void
    {
        $this->addingDate = null;
    }

    private function validateSlot(): void
    {
        $this->validate([
            'addingDate' => ['required', 'date'],
            'addingMealType' => ['required', Rule::in(MealPlan::MEAL_TYPES)],
            'addingServings' => ['nullable', 'integer', 'min:1', 'max:99'],
        ]);
    }

    public function addRecipe(int $recipeId): void
    {
        $this->validateSlot();

        $recipe = Recipe::query()->visibleTo(Auth::user(), (int) $this->householdId)->findOrFail($recipeId);

        MealPlan::create([
            'household_id' => $this->householdId,
            'created_by' => Auth::id(),
            'visibility' => $recipe->isPrivate() || $this->addingPrivate ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED,
            'date' => $this->addingDate,
            'meal_type' => $this->addingMealType,
            'recipe_id' => $recipe->id,
            'title' => $recipe->title,
            'servings' => $this->addingServings ?: $recipe->servings,
        ]);

        $this->addingDate = null;
    }

    /**
     * Free-text entry, e.g. "Leftovers" or "Eating out".
     */
    public function addCustom(): void
    {
        $this->validateSlot();
        $this->validate(['customTitle' => ['required', 'string', 'max:500']]);

        MealPlan::create([
            'household_id' => $this->householdId,
            'created_by' => Auth::id(),
            'visibility' => $this->addingPrivate ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED,
            'date' => $this->addingDate,
            'meal_type' => $this->addingMealType,
            'title' => trim($this->customTitle),
            'servings' => $this->addingServings,
        ]);

        $this->addingDate = null;
    }

    public function removeEntry(int $id): void
    {
        MealPlan::query()->visibleTo(Auth::user(), (int) $this->householdId)->findOrFail($id)->delete();
    }

    /**
     * Drag & drop between days: keeps the meal type, only the day changes.
     */
    public function moveEntry(int $id, string $date): void
    {
        $entry = MealPlan::query()->visibleTo(Auth::user(), (int) $this->householdId)->findOrFail($id);

        try {
            $entry->update(['date' => Carbon::createFromFormat('!Y-m-d', $date)]);
        } catch (Throwable) {
            // Ignore malformed dates from the client.
        }
    }

    /**
     * Ingredients of all planned recipes of the week, scaled and combined.
     *
     * @return array<int, array{name: string, quantity: string, recipes: array<int, string>}>
     */
    private function weekIngredients(): array
    {
        $lines = [];

        foreach ($this->entriesOfWeek()->whereNotNull('recipe') as $entry) {
            foreach ($entry->recipe->scaledIngredients($entry->servings) as $ingredient) {
                if (! $ingredient['isSection']) {
                    $lines[] = $ingredient + ['recipe' => $entry->recipe->title];
                }
            }
        }

        return ShoppingIngredients::combine($lines);
    }

    /**
     * Pre-selects what is not in the pantry yet.
     */
    public function chooseShopping(): void
    {
        $stock = MealSuggestions::pantry(Auth::user(), (int) $this->householdId)['stock'];
        $this->selectedShopping = array_keys(array_filter($this->weekIngredients(), fn (array $ingredient) => ! MealSuggestions::inStock($ingredient['name'], $stock)));
        $this->shoppingListId ??= ShoppingList::defaultFor((int) $this->householdId)->id;
        $this->choosingShopping = true;
    }

    // ── Suggestions ──────────────────────────────────────────────────────

    /**
     * Suggests a dinner for every day of the shown week (from today) that has none yet.
     */
    public function suggestWeek(): void
    {
        $planned = $this->entriesOfWeek()->where('meal_type', 'dinner')->map(fn (MealPlan $entry) => $entry->date->toDateString())->all();
        $dates = collect(range(0, 6))->map(fn (int $offset) => $this->weekStart()->addDays($offset)->toDateString())
            ->filter(fn (string $date) => $date >= today()->toDateString() && ! in_array($date, $planned, true))
            ->values()->all();

        $this->suggestions = $this->toSuggestionState(MealSuggestions::forDates(Auth::user(), (int) $this->householdId, $dates));

        if ($this->suggestions === []) {
            $this->dispatch('toast', message: $dates === [] ? __('Every day of this week already has a dinner.') : __('Add some recipes first.'));
        }
    }

    public function rerollSuggestion(string $date): void
    {
        abort_unless(array_key_exists($date, $this->suggestions), 404);

        $other = MealSuggestions::forDates(Auth::user(), (int) $this->householdId, [$date], array_column($this->suggestions, 'id'));

        if ($other !== []) {
            $this->suggestions[$date] = $this->toSuggestionState($other)[$date];
        }
    }

    public function dropSuggestion(string $date): void
    {
        unset($this->suggestions[$date]);
    }

    /**
     * Plans the suggested dinners and offers their ingredients for the shopping list.
     */
    public function applySuggestions(): void
    {
        $recipes = Recipe::query()->visibleTo(Auth::user(), (int) $this->householdId)->whereIn('id', array_column($this->suggestions, 'id'))->get()->keyBy('id');

        foreach ($this->suggestions as $date => $suggestion) {
            $recipe = $recipes->get($suggestion['id']);

            if (! $recipe || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
                continue;
            }

            MealPlan::create([
                'household_id' => $this->householdId,
                'created_by' => Auth::id(),
                'visibility' => $recipe->isPrivate() ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED,
                'date' => $date,
                'meal_type' => 'dinner',
                'recipe_id' => $recipe->id,
                'title' => $recipe->title,
                'servings' => $recipe->servings,
            ]);
        }

        $this->suggestions = [];
        $this->chooseShopping();
    }

    /**
     * @param  array<string, array{recipe: Recipe, reasons: list<string>}>  $suggestions
     * @return array<string, array{id: int, reasons: list<string>}>
     */
    private function toSuggestionState(array $suggestions): array
    {
        return array_map(fn (array $row) => ['id' => $row['recipe']->id, 'reasons' => $row['reasons']], $suggestions);
    }

    public function addWeekToShoppingList(): void
    {
        $list = ShoppingList::where('household_id', $this->householdId)->find($this->shoppingListId) ?? ShoppingList::defaultFor((int) $this->householdId);
        $ingredients = $this->weekIngredients();
        $added = 0;

        foreach ($this->selectedShopping as $index) {
            $ingredient = $ingredients[(int) $index] ?? null;

            if (! $ingredient) {
                continue;
            }

            ShoppingLists::add($list, $ingredient['name'], $ingredient['quantity'] !== '' ? mb_substr($ingredient['quantity'], 0, 255) : null, Auth::id());
            $added++;
        }

        $this->choosingShopping = false;
        $this->dispatch('toast', message: trans_choice(':count item added to the shopping list.|:count items added to the shopping list.', $added));
    }

    /**
     * Visible entries of the shown week; recipes the user can't see are hidden from the entry.
     *
     * @return \Illuminate\Support\Collection<int, MealPlan>
     */
    private function entriesOfWeek(): \Illuminate\Support\Collection
    {
        $start = $this->weekStart();
        $entries = MealPlan::query()
            ->visibleTo(Auth::user(), (int) $this->householdId)
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $start->copy()->addDays(6)->toDateString())
            ->with('recipe')
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        return MealPlan::withVisibleRecipes($entries, Auth::user(), (int) $this->householdId);
    }

    public function with(): array
    {
        $start = $this->weekStart();
        $entries = $this->entriesOfWeek();
        $mealOrder = array_flip(MealPlan::MEAL_TYPES);

        $days = collect(range(0, 6))->map(fn (int $offset) => $start->copy()->addDays($offset))
            ->map(fn (Carbon $day) => [
                'date' => $day,
                'entries' => $entries
                    ->filter(fn (MealPlan $entry) => $entry->date->isSameDay($day))
                    ->sortBy(fn (MealPlan $entry) => [$mealOrder[$entry->meal_type] ?? 9, $entry->id])
                    ->values(),
            ]);

        $recipeResults = $this->addingDate !== null
            ? Recipe::query()->visibleTo(Auth::user(), (int) $this->householdId)
                ->whereNull('archived_at')
                ->when(trim($this->recipeSearch) !== '', fn ($query) => $query->matching($this->recipeSearch))
                ->orderByDesc('cooked_count')
                ->orderBy('title')
                ->limit(8)
                ->get()
            : collect();

        return [
            'start' => $start,
            'days' => $days,
            'isCurrentWeek' => $start->isSameDay(today()->startOfWeek(Carbon::MONDAY)),
            'hasRecipes' => $entries->contains(fn (MealPlan $entry) => $entry->recipe !== null),
            'recipeResults' => $recipeResults,
            'weekIngredients' => $this->choosingShopping ? $this->weekIngredients() : [],
            'pantryStock' => $this->choosingShopping ? MealSuggestions::pantry(Auth::user(), (int) $this->householdId)['stock'] : [],
            'suggestedRecipes' => $this->suggestions !== []
                ? Recipe::query()->visibleTo(Auth::user(), (int) $this->householdId)->whereIn('id', array_column($this->suggestions, 'id'))->get()->keyBy('id')
                : collect(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Meal plan') }}
        </h2>
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
            <a href="{{ route('recipes.index') }}" wire:navigate class="text-sm text-gray-500 dark:text-gray-400 hover:underline">&larr; {{ __('All recipes') }}</a>

            <div class="flex flex-wrap items-center gap-2">
                <button type="button" wire:click="changeWeek(-1)" class="rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-1.5 text-gray-700 dark:text-gray-200" aria-label="{{ __('Previous week') }}">&larr;</button>
                <h1 class="min-w-[14rem] text-center text-lg font-semibold text-gray-900 dark:text-gray-100">
                    {{ $start->translatedFormat('d. M') }} – {{ $start->copy()->addDays(6)->translatedFormat('d. M Y') }}
                    <span class="block text-xs font-normal text-gray-500 dark:text-gray-400">{{ __('Week :number', ['number' => $start->isoWeek()]) }}</span>
                </h1>
                <button type="button" wire:click="changeWeek(1)" class="rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-1.5 text-gray-700 dark:text-gray-200" aria-label="{{ __('Next week') }}">&rarr;</button>
                @unless ($isCurrentWeek)
                    <button type="button" wire:click="$set('week', '')" class="text-sm text-indigo-600 dark:text-indigo-400 underline">{{ __('This week') }}</button>
                @endunless
                <span class="flex-1"></span>
                <button type="button" wire:click="suggestWeek" wire:loading.attr="disabled" wire:target="suggestWeek" class="rounded-md px-3 py-2 text-sm font-medium text-indigo-700 ring-1 ring-indigo-200 hover:bg-indigo-50 dark:text-indigo-300 dark:ring-indigo-800 dark:hover:bg-gray-700">✨ {{ __('Suggest the week') }}</button>
                @if ($hasRecipes)
                    <button type="button" wire:click="chooseShopping" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">🛒 {{ __('Ingredients of the week to shopping list') }}</button>
                @endif
            </div>

            @if ($suggestions !== [])
                <section class="rounded-lg bg-white p-5 shadow-sm dark:bg-gray-800" aria-labelledby="suggestions-heading">
                    <h3 id="suggestions-heading" class="font-medium text-gray-900 dark:text-gray-100">✨ {{ __('Suggestions for dinner') }}</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('From your favorites, well-rated recipes and what is in the pantry – recently planned recipes come last.') }}</p>
                    <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($suggestions as $date => $suggestion)
                            @php
                                $suggested = $suggestedRecipes->get($suggestion['id']);
                            @endphp
                            @continue(! $suggested)
                            <li wire:key="suggestion-{{ $date }}" class="flex flex-wrap items-center gap-3 py-2">
                                <span class="w-24 shrink-0 text-sm text-gray-500 dark:text-gray-400">{{ \Illuminate\Support\Carbon::parse($date)->translatedFormat('D, j. M') }}</span>
                                <span class="min-w-0 flex-1">
                                    <a href="{{ route('recipes.show', $suggested) }}" wire:navigate class="font-medium text-gray-900 hover:underline dark:text-gray-100">{{ $suggested->title }}</a>
                                    @if ($suggestion['reasons'] !== [])
                                        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ implode(' · ', $suggestion['reasons']) }}</span>
                                    @endif
                                </span>
                                <button type="button" wire:click="rerollSuggestion('{{ $date }}')" class="rounded-md px-2 py-1 text-sm ring-1 ring-gray-300 hover:bg-gray-50 dark:ring-gray-600 dark:hover:bg-gray-700" title="{{ __('Another recipe') }}" aria-label="{{ __('Another recipe') }}">🔄</button>
                                <button type="button" wire:click="dropSuggestion('{{ $date }}')" class="rounded-md px-2 py-1 text-sm text-gray-500 hover:text-red-600" title="{{ __('No suggestion for this day') }}" aria-label="{{ __('No suggestion for this day') }}">✕</button>
                            </li>
                        @endforeach
                    </ul>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <x-primary-button type="button" wire:click="applySuggestions">{{ __('Plan these and pick ingredients') }}</x-primary-button>
                        <x-secondary-button type="button" wire:click="$set('suggestions', [])">{{ __('Cancel') }}</x-secondary-button>
                    </div>
                </section>
            @endif

            @if ($choosingShopping)
                <form wire:submit="addWeekToShoppingList" class="rounded-lg bg-white dark:bg-gray-800 p-5 shadow-sm space-y-2">
                    <p class="font-medium text-gray-900 dark:text-gray-100">{{ __('Which ingredients do you need?') }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Scaled to the planned servings; equal ingredients are added up.') }}</p>
                    @php
                        $shoppingLists = \App\Models\ShoppingList::where('household_id', $this->householdId)->orderBy('position')->orderBy('id')->get();
                    @endphp
                    @if ($shoppingLists->count() > 1)
                        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                            {{ __('List') }}
                            <select wire:model="shoppingListId" class="rounded-md border-gray-300 py-1 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                                @foreach ($shoppingLists as $shoppingList)
                                    <option value="{{ $shoppingList->id }}">{{ $shoppingList->icon }} {{ $shoppingList->name }}</option>
                                @endforeach
                            </select>
                        </label>
                    @endif
                    <div class="grid gap-x-6 gap-y-1 sm:grid-cols-2 lg:grid-cols-3">
                        @forelse ($weekIngredients as $index => $ingredient)
                            <label class="flex items-baseline gap-2 text-sm text-gray-800 dark:text-gray-200" wire:key="week-ingredient-{{ $index }}">
                                <input type="checkbox" wire:model="selectedShopping" value="{{ $index }}" class="translate-y-0.5 rounded border-gray-300 dark:border-gray-600 text-indigo-600" />
                                <span>
                                    <span class="font-medium">{{ $ingredient['quantity'] }}</span> {{ $ingredient['name'] }}
                                    @if (\App\Support\MealSuggestions::inStock($ingredient['name'], $pantryStock))
                                        <span class="ms-1 rounded bg-emerald-50 px-1 text-xs text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">🧺 {{ __('in stock') }}</span>
                                    @endif
                                    <span class="block text-xs text-gray-500 dark:text-gray-400">{{ implode(', ', $ingredient['recipes']) }}</span>
                                </span>
                            </label>
                        @empty
                            <p class="text-sm text-gray-500">{{ __('The planned recipes have no ingredients.') }}</p>
                        @endforelse
                    </div>
                    <div class="flex items-center gap-3 pt-2">
                        <x-primary-button>{{ __('Add to shopping list') }}</x-primary-button>
                        <button type="button" wire:click="$set('choosingShopping', false)" class="text-sm text-gray-500 underline">{{ __('Cancel') }}</button>
                    </div>
                </form>
            @endif

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 2xl:grid-cols-7">
                @foreach ($days as $day)
                    <section
                        wire:key="day-{{ $day['date']->toDateString() }}"
                        @class(['flex flex-col rounded-lg bg-white dark:bg-gray-800 p-3 shadow-sm', 'ring-2 ring-indigo-500' => $day['date']->isToday()])
                    >
                        <h2 class="flex flex-wrap items-baseline justify-between gap-x-2">
                            <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $day['date']->translatedFormat('l') }}</span>
                            <span class="text-xs text-gray-500 dark:text-gray-400">{{ $day['date']->format('d.m.') }}</span>
                        </h2>
                        <ul
                            class="mt-2 flex min-h-[3rem] flex-1 flex-col gap-2"
                            x-data
                            x-sort="$wire.moveEntry($item, @js($day['date']->toDateString()))"
                            x-sort:group="meal-plan"
                        >
                            @foreach ($day['entries'] as $entry)
                                <li wire:key="entry-{{ $entry->id }}" x-sort:item="{{ $entry->id }}" class="group cursor-grab rounded-md bg-gray-50 dark:bg-gray-900/60 p-2 text-sm ring-1 ring-gray-200 dark:ring-gray-700">
                                    <p class="flex items-center justify-between gap-1 text-xs text-gray-500 dark:text-gray-400">
                                        <span>{{ \App\Models\MealPlan::mealTypeLabel($entry->meal_type) }}@if ($entry->servings) · {{ trans_choice(':count serving|:count servings', $entry->servings) }}@endif</span>
                                        <button type="button" wire:click="removeEntry({{ $entry->id }})" class="opacity-60 hover:text-red-600 hover:opacity-100" aria-label="{{ __('Remove from meal plan') }}">✕</button>
                                    </p>
                                    @if ($entry->recipe)
                                        <a href="{{ route('recipes.show', $entry->recipe) }}" wire:navigate class="mt-0.5 flex items-center gap-2 font-medium text-gray-900 dark:text-gray-100 hover:underline">
                                            @if ($entry->recipe->imageUrl())
                                                <img src="{{ $entry->recipe->imageUrl() }}" alt="" loading="lazy" class="h-8 w-8 shrink-0 rounded object-cover" />
                                            @endif
                                            <span class="min-w-0 line-clamp-2 break-words">{{ $entry->recipe->title }}</span>
                                        </a>
                                    @else
                                        <p class="mt-0.5 break-words font-medium text-gray-900 dark:text-gray-100">{{ $entry->title }}</p>
                                    @endif
                                    @if ($entry->isPrivate())
                                        <x-private-badge class="mt-1" />
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        <button type="button" wire:click="startAdding('{{ $day['date']->toDateString() }}')" class="mt-2 rounded-md border border-dashed border-gray-300 dark:border-gray-600 px-2 py-1 text-sm text-gray-500 dark:text-gray-400 hover:border-indigo-400 hover:text-indigo-600">+ {{ __('Add') }}</button>
                    </section>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Add dialog --}}
    @if ($addingDate)
        <div class="fixed inset-0 z-[60] flex items-end justify-center bg-gray-900/50 p-4 sm:items-center" x-data x-on:keydown.escape.window="$wire.cancelAdding()" wire:click.self="cancelAdding">
            <div class="w-full max-w-lg space-y-4 rounded-lg bg-white dark:bg-gray-800 p-5 shadow-xl" role="dialog" aria-modal="true" aria-labelledby="add-meal-title">
                <div class="flex items-center justify-between gap-2">
                    <h3 id="add-meal-title" class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ \Illuminate\Support\Carbon::parse($addingDate)->translatedFormat('l, d.m.') }}</h3>
                    <button type="button" wire:click="cancelAdding" class="text-gray-400 hover:text-gray-700" aria-label="{{ __('Close') }}">✕</button>
                </div>

                <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="{{ __('Meal') }}">
                    @foreach (\App\Models\MealPlan::MEAL_TYPES as $mealType)
                        <button type="button" wire:click="$set('addingMealType', '{{ $mealType }}')" role="radio" aria-checked="{{ $addingMealType === $mealType ? 'true' : 'false' }}" @class(['rounded-full px-3 py-1 text-sm', 'bg-indigo-600 text-white' => $addingMealType === $mealType, 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300' => $addingMealType !== $mealType])>{{ \App\Models\MealPlan::mealTypeLabel($mealType) }}</button>
                    @endforeach
                </div>

                <div class="flex items-end gap-3">
                    <div class="flex-1">
                        <x-input-label for="recipeSearch" :value="__('Recipe')" />
                        <x-text-input wire:model.live.debounce.250ms="recipeSearch" id="recipeSearch" type="search" class="mt-1 block w-full" placeholder="{{ __('Search recipes or ingredients...') }}" autofocus />
                    </div>
                    <div class="w-24">
                        <x-input-label for="addingServings" :value="__('Servings')" />
                        <x-text-input wire:model="addingServings" id="addingServings" type="number" min="1" max="99" class="mt-1 block w-full" placeholder="{{ __('auto') }}" />
                    </div>
                </div>
                <x-input-error :messages="$errors->get('addingServings')" />

                <ul class="max-h-64 divide-y divide-gray-100 overflow-y-auto dark:divide-gray-700">
                    @forelse ($recipeResults as $result)
                        <li wire:key="result-{{ $result->id }}">
                            <button type="button" wire:click="addRecipe({{ $result->id }})" class="flex w-full items-center gap-3 px-1 py-2 text-start hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                @if ($result->imageUrl())
                                    <img src="{{ $result->imageUrl() }}" alt="" class="h-10 w-10 rounded object-cover" />
                                @else
                                    <span class="flex h-10 w-10 items-center justify-center rounded bg-gray-100 dark:bg-gray-700" aria-hidden="true">🍽️</span>
                                @endif
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-medium text-gray-900 dark:text-gray-100">{{ $result->title }}</span>
                                    <span class="text-xs text-gray-500 dark:text-gray-400">
                                        @if ($result->totalTime()) ⏱ {{ $result->totalTime() }} {{ __('min') }} @endif
                                        @if ($result->servings) · {{ trans_choice(':count serving|:count servings', $result->servings) }} @endif
                                    </span>
                                </span>
                            </button>
                        </li>
                    @empty
                        <li class="py-2 text-sm text-gray-500 dark:text-gray-400">{{ __('No recipes match your filter.') }}</li>
                    @endforelse
                </ul>

                <form wire:submit="addCustom" class="space-y-2 border-t border-gray-200 dark:border-gray-700 pt-4">
                    <x-input-label for="customTitle" :value="__('Or free text')" />
                    <div class="flex gap-2">
                        <x-text-input wire:model="customTitle" id="customTitle" class="block flex-1" placeholder="{{ __('e.g. Leftovers, eating out') }}" />
                        <x-secondary-button type="submit">{{ __('Add') }}</x-secondary-button>
                    </div>
                    <x-input-error :messages="$errors->get('customTitle')" />
                    <x-private-checkbox id="addingPrivate" wire:model="addingPrivate" />
                </form>
            </div>
        </div>
    @endif
</div>
