<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\MealPlan;
use App\Models\MealPlanPreference;
use App\Support\AiException;
use App\Support\MealPlanAi;
use App\Support\MealPlanBuilder;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    public int $people = 2;

    public int $daysPerWeek = 5;

    public string $budget = '';

    public string $postalCode = '';

    public string $diet = '';

    public bool $mealPrep = false;

    /** @var array<int, string> */
    public array $meals = ['dinner'];

    /** @var array<int, string> */
    public array $dishTypes = [];

    /** @var array<int, string> */
    public array $allergens = [];

    /** @var array<int, string> */
    public array $appliances = [];

    public string $supermarketsText = '';

    public string $cuisinesText = '';

    public string $dislikedText = '';

    public string $offersText = '';

    public string $week = '';

    /** @var array<string, mixed>|null */
    public ?array $result = null;

    public bool $generating = false;

    public string $error = '';

    /**
     * Canonical indexes of the generated recipes the user wants to keep permanently (import into
     * the cookbook). The rest are added to the plan only and pruned after the week.
     *
     * @var array<int, int>
     */
    public array $keep = [];

    public function mount(): void
    {
        $this->resolveHousehold();
        $preference = MealPlanPreference::for($this->currentUser());

        $this->people = $preference->people ?: 2;
        $this->daysPerWeek = $preference->days_per_week ?: 5;
        $this->budget = $preference->weekly_budget !== null ? rtrim(rtrim(number_format((float) $preference->weekly_budget, 2, '.', ''), '0'), '.') : '';
        $this->postalCode = (string) ($preference->postal_code ?? '');
        $this->diet = (string) ($preference->diet ?? '');
        $this->mealPrep = (bool) $preference->meal_prep;
        $this->meals = $preference->meals ?: ['dinner'];
        $this->dishTypes = $preference->dish_types ?: [];
        $this->allergens = $preference->allergens ?: [];
        $this->appliances = $preference->appliances ?: [];
        $this->supermarketsText = implode(', ', $preference->supermarkets ?? []);
        $this->cuisinesText = implode(', ', $preference->cuisines ?? []);
        $this->dislikedText = implode(', ', $preference->disliked_ingredients ?? []);
    }

    /**
     * @return array<int, string>
     */
    private function toList(string $text): array
    {
        return collect(explode(',', $text))->map(fn (string $item) => trim($item))->filter()->values()->all();
    }

    public function savePreferences(): void
    {
        $this->validate([
            'people' => ['integer', 'min:1', 'max:20'],
            'daysPerWeek' => ['integer', 'min:1', 'max:7'],
            'budget' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'postalCode' => ['nullable', 'string', 'max:12'],
            'diet' => ['nullable', 'string', 'max:40'],
            'meals' => ['array', 'min:1'],
            'meals.*' => ['in:'.implode(',', MealPlan::MEAL_TYPES)],
        ]);

        MealPlanPreference::for($this->currentUser())->fill([
            'people' => $this->people,
            'days_per_week' => $this->daysPerWeek,
            'weekly_budget' => $this->budget === '' ? null : (float) $this->budget,
            'postal_code' => $this->postalCode ?: null,
            'diet' => $this->diet ?: null,
            'meal_prep' => $this->mealPrep,
            'meals' => $this->meals,
            'dish_types' => $this->dishTypes,
            'allergens' => $this->allergens,
            'appliances' => $this->appliances,
            'supermarkets' => $this->toList($this->supermarketsText),
            'cuisines' => $this->toList($this->cuisinesText),
            'disliked_ingredients' => $this->toList($this->dislikedText),
        ])->save();

        $this->dispatch('toast', message: __('Saved.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function prefsArray(): array
    {
        return [
            'people' => $this->people,
            'days_per_week' => $this->daysPerWeek,
            'weekly_budget' => $this->budget === '' ? null : (float) $this->budget,
            'postal_code' => $this->postalCode ?: null,
            'diet' => $this->diet ?: null,
            'meal_prep' => $this->mealPrep,
            'meals' => $this->meals,
            'dish_types' => $this->dishTypes,
            'allergens' => $this->allergens,
            'appliances' => $this->appliances,
            'supermarkets' => $this->toList($this->supermarketsText),
            'cuisines' => $this->toList($this->cuisinesText),
            'disliked_ingredients' => $this->toList($this->dislikedText),
        ];
    }

    public function generate(MealPlanAi $ai): void
    {
        $this->savePreferences();
        $this->error = '';
        $this->result = null;
        $this->keep = [];

        try {
            $this->result = $ai->generate($this->prefsArray(), $this->offersText !== '' ? $this->offersText : null);
        } catch (AiException $exception) {
            $this->error = $exception->getMessage();
        }
    }

    private function weekStart(): Carbon
    {
        return ($this->week !== '' ? Carbon::parse($this->week) : now())->startOfWeek();
    }

    /**
     * Adds the generated recipes to the week plan. By default they are temporary: viewable through
     * the plan, pruned once the week is over. Recipes the user ticked ($keep) – or all, with
     * $keepAll – are saved to the cookbook permanently instead.
     */
    public function addToPlan(bool $keepAll = false): void
    {
        if ($this->result === null) {
            return;
        }

        $user = $this->currentUser();
        // Temporary recipes linger one day past the planned week, then the pruner removes them.
        $expiresAt = $keepAll ? null : $this->weekStart()->copy()->addDays(8)->startOfDay();
        $keepIndexes = $keepAll ? [] : array_map('intval', $this->keep);

        $saved = MealPlanBuilder::createRecipes($user, (int) $this->householdId, $this->result, $expiresAt, $keepIndexes);
        $count = MealPlanBuilder::addToPlan($user, (int) $this->householdId, $this->weekStart(), $this->result, $saved);

        $keptCount = $keepAll ? count($saved) : count($keepIndexes);
        $this->dispatch('toast', message: $keptCount > 0
            ? __(':count meals added to the plan, :kept recipe(s) saved to the cookbook.', ['count' => $count, 'kept' => $keptCount])
            : __(':count meals added to the plan (recipes are kept for this week).', ['count' => $count]));
    }

    public function createList(): void
    {
        if ($this->result === null) {
            return;
        }

        MealPlanBuilder::createShoppingList($this->currentUser(), (int) $this->householdId, $this->result, __('Meal plan').' '.$this->weekStart()->format('d.m.'));
        $this->dispatch('toast', message: __('Shopping list created.'));
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [
            'aiReady' => MealPlanAi::isConfigured(),
            'planRecipes' => $this->result !== null ? MealPlanBuilder::plannedRecipes($this->result) : [],
        ];
    }
}; ?>

@php
    $mealOptions = ['breakfast' => __('Breakfast'), 'lunch' => __('Lunch'), 'dinner' => __('Dinner'), 'snack' => __('Snack')];
    $dietOptions = ['' => __('No preference'), 'omnivore' => __('Omnivore'), 'flexitarian' => __('Flexitarian'), 'vegetarian' => __('Vegetarian'), 'vegan' => __('Vegan'), 'pescetarian' => __('Pescetarian')];
    $dishTypeOptions = ['healthy' => __('Healthy'), 'high_protein' => __('High protein'), 'low_carb' => __('Low carb'), 'low_calorie' => __('Low calorie'), 'quick' => __('Quick'), 'budget' => __('Budget'), 'comfort' => __('Hearty'), 'kid_friendly' => __('Kid-friendly')];
    $allergenOptions = ['gluten' => __('Gluten'), 'lactose' => __('Lactose'), 'nuts' => __('Nuts'), 'eggs' => __('Eggs'), 'soy' => __('Soy'), 'fish' => __('Fish'), 'shellfish' => __('Shellfish'), 'sesame' => __('Sesame')];
    $applianceOptions = ['oven' => __('Oven'), 'stove' => __('Stove'), 'microwave' => __('Microwave'), 'air_fryer' => __('Air fryer'), 'thermomix' => __('Thermomix'), 'slow_cooker' => __('Slow cooker'), 'grill' => __('Grill'), 'blender' => __('Blender')];
    $mealLabel = fn ($type) => $mealOptions[$type] ?? $type;
    $euro = fn ($value) => number_format((float) $value, 2, ',', '.').' €';
    // Group by day while keeping the canonical index (the key the "keep" checkboxes use).
    $recipesByDay = collect($planRecipes)->groupBy('day')->sortKeys();
@endphp

<div>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">🤖 {{ __('AI meal planner') }}</h2>
            <a href="{{ route('recipes.plan') }}" wire:navigate class="text-sm text-gray-500 hover:underline dark:text-gray-400">{{ __('Meal plan') }} &rarr;</a>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-5xl space-y-5 px-4 sm:px-6 lg:px-8">
            @unless ($aiReady)
                <div class="rounded-2xl bg-amber-50 p-4 text-sm text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">{{ __('The AI is not set up on this server (ANTHROPIC_API_KEY).') }}</div>
            @endunless

            {{-- Preferences --}}
            <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Your preferences') }}</h3>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('The planner creates a week of recipes and one shopping list – for a single supermarket, within your budget and respecting allergies.') }}</p>

                <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <x-input-label for="people" :value="__('People')" />
                        <x-text-input id="people" type="number" min="1" max="20" wire:model="people" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label for="days" :value="__('Days per week')" />
                        <x-text-input id="days" type="number" min="1" max="7" wire:model="daysPerWeek" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label for="budget" :value="__('Weekly budget (€)')" />
                        <x-text-input id="budget" inputmode="decimal" wire:model="budget" placeholder="60" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label for="plz" :value="__('Postal code')" />
                        <x-text-input id="plz" wire:model="postalCode" placeholder="10115" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label for="diet" :value="__('Diet')" />
                        <select id="diet" wire:model="diet" class="mt-1 block w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                            @foreach ($dietOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <label class="flex items-end gap-2 pb-2 text-sm text-gray-700 dark:text-gray-200">
                        <input type="checkbox" wire:model="mealPrep" class="rounded-sm border-gray-300 text-indigo-600 dark:border-gray-600 dark:bg-gray-900">
                        {{ __('Meal-prep mode (batch-cookable)') }}
                    </label>
                </div>

                <div class="mt-5 grid gap-5 sm:grid-cols-2">
                    <div>
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Meals') }}</span>
                        <div class="mt-2 flex flex-wrap gap-3">
                            @foreach ($mealOptions as $value => $label)
                                <label class="flex items-center gap-1.5 text-sm text-gray-700 dark:text-gray-200"><input type="checkbox" wire:model="meals" value="{{ $value }}" class="rounded-sm border-gray-300 text-indigo-600 dark:border-gray-600 dark:bg-gray-900">{{ $label }}</label>
                            @endforeach
                        </div>
                        <x-input-error :messages="$errors->get('meals')" class="mt-1" />
                    </div>
                    <div>
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Type of dishes') }}</span>
                        <div class="mt-2 flex flex-wrap gap-3">
                            @foreach ($dishTypeOptions as $value => $label)
                                <label class="flex items-center gap-1.5 text-sm text-gray-700 dark:text-gray-200"><input type="checkbox" wire:model="dishTypes" value="{{ $value }}" class="rounded-sm border-gray-300 text-indigo-600 dark:border-gray-600 dark:bg-gray-900">{{ $label }}</label>
                            @endforeach
                        </div>
                    </div>
                    <div>
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Allergies (strictly avoid)') }}</span>
                        <div class="mt-2 flex flex-wrap gap-3">
                            @foreach ($allergenOptions as $value => $label)
                                <label class="flex items-center gap-1.5 text-sm text-gray-700 dark:text-gray-200"><input type="checkbox" wire:model="allergens" value="{{ $value }}" class="rounded-sm border-gray-300 text-indigo-600 dark:border-gray-600 dark:bg-gray-900">{{ $label }}</label>
                            @endforeach
                        </div>
                    </div>
                    <div>
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Kitchen appliances') }}</span>
                        <div class="mt-2 flex flex-wrap gap-3">
                            @foreach ($applianceOptions as $value => $label)
                                <label class="flex items-center gap-1.5 text-sm text-gray-700 dark:text-gray-200"><input type="checkbox" wire:model="appliances" value="{{ $value }}" class="rounded-sm border-gray-300 text-indigo-600 dark:border-gray-600 dark:bg-gray-900">{{ $label }}</label>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="mt-5 grid gap-4 sm:grid-cols-3">
                    <div>
                        <x-input-label for="markets" :value="__('Supermarkets (comma-separated)')" />
                        <x-text-input id="markets" wire:model="supermarketsText" placeholder="Rewe, Aldi, Lidl" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label for="cuisines" :value="__('Cuisines (comma-separated)')" />
                        <x-text-input id="cuisines" wire:model="cuisinesText" placeholder="{{ __('Italian, Asian') }}" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label for="disliked" :value="__('Disliked ingredients (comma-separated)')" />
                        <x-text-input id="disliked" wire:model="dislikedText" placeholder="{{ __('Coriander, olives') }}" class="mt-1 block w-full" />
                    </div>
                </div>

                <div class="mt-5">
                    <x-input-label for="offers" :value="__('Current offers (optional – paste them, the AI prefers them)')" />
                    <textarea id="offers" wire:model="offersText" rows="3" class="mt-1 block w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200" placeholder="{{ __('e.g. Chicken breast 1 kg 5.99 €, Broccoli 0.99 €, …') }}"></textarea>
                </div>

                <div class="mt-5 flex flex-wrap items-center gap-3">
                    <x-primary-button wire:click="generate" wire:loading.attr="disabled" wire:target="generate">
                        <span wire:loading.remove wire:target="generate">✨ {{ __('Generate week plan') }}</span>
                        <span wire:loading wire:target="generate">{{ __('Generating …') }}</span>
                    </x-primary-button>
                    <button type="button" wire:click="savePreferences" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">{{ __('Save preferences') }}</button>
                </div>
                @if ($error)
                    <p class="mt-3 text-sm text-red-600 dark:text-red-400">{{ $error }}</p>
                @endif
            </section>

            {{-- Result --}}
            @if ($result)
                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">🛒 {{ $result['supermarket'] ?? __('Week plan') }}</h3>
                        @if (isset($result['estimated_total']))
                            <span @class(['rounded-full px-3 py-1 text-sm font-semibold', 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-200' => ($result['within_budget'] ?? true), 'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-200' => ! ($result['within_budget'] ?? true)])>
                                ~ {{ $euro($result['estimated_total']) }}
                            </span>
                        @endif
                    </div>
                    @if (! empty($result['notes']))
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ $result['notes'] }}</p>
                    @endif

                    <div class="mt-4 flex flex-wrap gap-3">
                        <x-primary-button wire:click="addToPlan(false)">📅 {{ __('Add to plan') }}</x-primary-button>
                        <button type="button" wire:click="addToPlan(true)" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">✅ {{ __('Save all recipes & add to plan') }}</button>
                        <button type="button" wire:click="createList" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">🧾 {{ __('Create shopping list') }}</button>
                    </div>
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Added recipes stay linked in the week plan and are removed after the week. Tick ⭐ to keep single recipes in the cookbook, or save them all.') }}</p>
                </section>

                <section class="grid gap-4 lg:grid-cols-2">
                    @foreach ($recipesByDay as $day => $dayRecipes)
                        <div class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                            <h4 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Day :n', ['n' => $day]) }}</h4>
                            <div class="mt-3 space-y-4">
                                @foreach ($dayRecipes as $index => $recipe)
                                    <div @class(['rounded-xl border p-3', 'border-amber-300 bg-amber-50/40 dark:border-amber-500/50 dark:bg-amber-900/10' => in_array((string) $index, array_map('strval', $keep), true), 'border-gray-100 dark:border-gray-700' => ! in_array((string) $index, array_map('strval', $keep), true)])>
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="text-xs font-medium uppercase tracking-wide text-indigo-600 dark:text-indigo-300">{{ $mealLabel($recipe['meal_type'] ?? 'dinner') }}</span>
                                            <span class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                                                @if (! empty($recipe['calories'])) {{ $recipe['calories'] }} kcal @endif
                                                @if (! empty($recipe['meal_prep'])) · 🥡 {{ __('Meal prep') }} @endif
                                            </span>
                                        </div>
                                        <p class="mt-1 font-medium text-gray-900 dark:text-gray-100">{{ $recipe['title'] ?? '' }}</p>
                                        @if (! empty($recipe['description']))
                                            <p class="mt-0.5 text-sm text-gray-600 dark:text-gray-400">{{ $recipe['description'] }}</p>
                                        @endif
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                            {{ count($recipe['ingredients'] ?? []) }} {{ __('ingredients') }}
                                            @if (! empty($recipe['prep_time'])) · {{ __('prep') }} {{ $recipe['prep_time'] }} min @endif
                                            @if (! empty($recipe['cook_time'])) · {{ __('cook') }} {{ $recipe['cook_time'] }} min @endif
                                        </p>
                                        <label class="mt-2 flex items-center gap-1.5 text-xs text-gray-600 dark:text-gray-300">
                                            <input type="checkbox" wire:model="keep" value="{{ $index }}" class="rounded-sm border-gray-300 text-amber-500 dark:border-gray-600 dark:bg-gray-900">
                                            ⭐ {{ __('Keep in cookbook') }}
                                        </label>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </section>

                @if (! empty($result['shopping_list']))
                    <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">🧾 {{ __('Shopping list') }}</h3>
                        <ul class="mt-3 grid gap-x-6 gap-y-1 sm:grid-cols-2">
                            @foreach ($result['shopping_list'] as $item)
                                <li class="flex items-center justify-between gap-3 border-b border-gray-50 py-1 text-sm dark:border-gray-700/50">
                                    <span class="text-gray-800 dark:text-gray-200">
                                        {{ $item['name'] ?? '' }}@if (! empty($item['amount'])) <span class="text-gray-500 dark:text-gray-400">· {{ $item['amount'] }}</span>@endif
                                        @if (! empty($item['on_offer'])) <span class="ml-1 rounded bg-rose-100 px-1.5 text-xs text-rose-700 dark:bg-rose-900/50 dark:text-rose-200">{{ __('offer') }}</span>@endif
                                    </span>
                                    @if (! empty($item['estimated_price']))<span class="tabular-nums text-gray-500 dark:text-gray-400">{{ $euro($item['estimated_price']) }}</span>@endif
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif
            @endif
        </div>
    </div>
</div>
