<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Household;
use App\Models\MealPlan;
use App\Models\MealPlanPreference;
use App\Models\Recipe;
use App\Support\AiException;
use App\Support\MealPlanAi;
use App\Support\MealPlanBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The AI meal planner for the app (the web "AI meal planner" page): saved preferences, generating
 * a week, then adding it to the plan and/or creating the shopping list. A generated week is kept
 * server-side for a few hours under a token so the app only sends small follow-up requests.
 */
class MealPlannerController extends ApiController
{
    private const KEEP_HOURS = 6;

    public function show(): JsonResponse
    {
        $this->household();

        return $this->ok([
            'ai_ready' => MealPlanAi::isConfigured(),
            'preferences' => $this->preferenceData(MealPlanPreference::for($this->user())),
            'options' => [
                'meals' => collect(MealPlan::MEAL_TYPES)->map(fn (string $type) => ['key' => $type, 'label' => MealPlan::mealTypeLabel($type)])->all(),
                'diets' => ['omnivore', 'flexitarian', 'vegetarian', 'vegan', 'pescetarian'],
                'dish_types' => ['healthy', 'high_protein', 'low_carb', 'low_calorie', 'quick', 'budget', 'comfort', 'kid_friendly'],
                'allergens' => ['gluten', 'lactose', 'nuts', 'eggs', 'soy', 'fish', 'shellfish', 'sesame'],
                'appliances' => ['oven', 'stove', 'microwave', 'air_fryer', 'thermomix', 'slow_cooker', 'grill', 'blender'],
            ],
        ]);
    }

    public function savePreferences(Request $request): JsonResponse
    {
        $this->household();
        $preference = MealPlanPreference::for($this->user());
        $preference->fill($this->validatedPreferences($request));
        $preference->save();

        return $this->ok($this->preferenceData($preference));
    }

    public function generate(Request $request, MealPlanAi $ai): JsonResponse
    {
        $this->household();
        $validated = $request->validate([
            ...$this->preferenceRules(),
            'offers' => ['nullable', 'string', 'max:5000'],
        ]);

        $preference = MealPlanPreference::for($this->user());
        $preference->fill($this->validatedPreferences($request));
        $preference->save();

        try {
            $result = $ai->generate($this->preferenceData($preference), ($validated['offers'] ?? '') !== '' ? (string) $validated['offers'] : null);
        } catch (AiException $exception) {
            throw ValidationException::withMessages(['ai' => $exception->getMessage()]);
        }

        $token = Str::random(32);
        Cache::put($this->cacheKey($token), $result, now()->addHours(self::KEEP_HOURS));

        return $this->ok($this->resultData($token, $result));
    }

    /**
     * Adds the generated recipes to the week plan. A title already planned that week is reported
     * as a duplicate first; the app then repeats the call with `confirm_duplicates` (the ones to
     * add anyway). `keep` lists recipes to save to the cookbook permanently (or all, `keep_all`).
     */
    public function addToPlan(Request $request): JsonResponse
    {
        $household = $this->household();
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'week' => ['nullable', 'date'],
            'keep_all' => ['sometimes', 'boolean'],
            'keep' => ['sometimes', 'array'],
            'keep.*' => ['integer', 'min:0'],
            'confirm_duplicates' => ['sometimes', 'array'],
            'confirm_duplicates.*' => ['integer', 'min:0'],
        ]);
        $result = $this->storedResult((string) $validated['token']);
        $week = ($validated['week'] ?? null) !== null ? Carbon::parse((string) $validated['week'])->startOfWeek() : now()->startOfWeek();
        $keepAll = (bool) ($validated['keep_all'] ?? false);

        $titles = MealPlan::query()
            ->visibleTo($this->user(), $household->id)
            ->whereDate('date', '>=', $week->toDateString())
            ->whereDate('date', '<=', $week->copy()->addDays(6)->toDateString())
            ->pluck('title')
            ->all();
        $duplicates = MealPlanBuilder::duplicatesAgainst($result, $titles);
        $skip = [];
        if ($duplicates !== []) {
            if (! $request->has('confirm_duplicates')) {
                return $this->ok(['added' => 0, 'duplicates' => $duplicates]);
            }
            $skip = array_values(array_diff(
                array_map(fn (array $row): int => $row['index'], $duplicates),
                array_map('intval', (array) $validated['confirm_duplicates']),
            ));
        }

        $expiresAt = $keepAll ? null : $week->copy()->addDays(8)->startOfDay();
        $keep = $keepAll ? [] : array_map('intval', (array) ($validated['keep'] ?? []));
        $saved = MealPlanBuilder::createRecipes($this->user(), $household->id, $result, $expiresAt, $keep, $skip);
        $count = MealPlanBuilder::addToPlan($this->user(), $household->id, $week, $result, $saved, $skip);
        $kept = count($saved) - count(array_filter($saved, fn (Recipe $recipe): bool => $recipe->isTemporary()));

        return $this->ok(['added' => $count, 'kept' => $kept, 'duplicates' => []]);
    }

    public function createList(Request $request): JsonResponse
    {
        $household = $this->household();
        $validated = $request->validate(['token' => ['required', 'string'], 'week' => ['nullable', 'date']]);
        $result = $this->storedResult((string) $validated['token']);
        $week = ($validated['week'] ?? null) !== null ? Carbon::parse((string) $validated['week'])->startOfWeek() : now()->startOfWeek();

        $list = MealPlanBuilder::createShoppingList($this->user(), $household->id, $result, __('Meal plan').' '.$week->format('d.m.'));

        return $this->ok(['list_id' => $list->id, 'name' => $list->name], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function storedResult(string $token): array
    {
        $result = Cache::get($this->cacheKey($token));
        abort_unless(is_array($result), 404, __('This plan has expired. Please generate it again.'));

        /** @var array<string, mixed> $result */
        return $result;
    }

    private function cacheKey(string $token): string
    {
        return 'meal-planner:'.$this->user()->id.':'.preg_replace('/[^A-Za-z0-9]/', '', $token);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function resultData(string $token, array $result): array
    {
        return [
            'token' => $token,
            'supermarket' => $result['supermarket'] ?? null,
            'estimated_total' => $result['estimated_total'] ?? null,
            'within_budget' => (bool) ($result['within_budget'] ?? true),
            'notes' => $result['notes'] ?? null,
            'recipes' => collect(MealPlanBuilder::plannedRecipes($result))->map(fn (array $recipe, int $index): array => [
                'index' => $index,
                'day' => $recipe['day'] ?? null,
                'meal_type' => $recipe['meal_type'] ?? 'dinner',
                'title' => (string) ($recipe['title'] ?? ''),
                'description' => $recipe['description'] ?? null,
                'calories' => $recipe['calories'] ?? null,
                'prep_time' => $recipe['prep_time'] ?? null,
                'cook_time' => $recipe['cook_time'] ?? null,
                'meal_prep' => (bool) ($recipe['meal_prep'] ?? false),
                'ingredients' => count(is_array($recipe['ingredients'] ?? null) ? $recipe['ingredients'] : []),
            ])->all(),
            'shopping_list' => $result['shopping_list'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function preferenceData(MealPlanPreference $preference): array
    {
        return [
            'people' => $preference->people ?: 2,
            'days_per_week' => $preference->days_per_week ?: 5,
            'weekly_budget' => $preference->weekly_budget !== null ? (float) $preference->weekly_budget : null,
            'postal_code' => $preference->postal_code,
            'diet' => $preference->diet,
            'meal_prep' => (bool) $preference->meal_prep,
            'meals' => $preference->meals ?: ['dinner'],
            'dish_types' => $preference->dish_types ?: [],
            'allergens' => $preference->allergens ?: [],
            'appliances' => $preference->appliances ?: [],
            'supermarkets' => $preference->supermarkets ?: [],
            'cuisines' => $preference->cuisines ?: [],
            'disliked_ingredients' => $preference->disliked_ingredients ?: [],
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function preferenceRules(): array
    {
        return [
            'people' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'days_per_week' => ['sometimes', 'integer', 'min:1', 'max:7'],
            'weekly_budget' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:12'],
            'diet' => ['sometimes', 'nullable', 'string', 'max:40'],
            'meal_prep' => ['sometimes', 'boolean'],
            'meals' => ['sometimes', 'array', 'min:1'],
            'meals.*' => ['in:'.implode(',', MealPlan::MEAL_TYPES)],
            'dish_types' => ['sometimes', 'array'],
            'dish_types.*' => ['string', 'max:40'],
            'allergens' => ['sometimes', 'array'],
            'allergens.*' => ['string', 'max:40'],
            'appliances' => ['sometimes', 'array'],
            'appliances.*' => ['string', 'max:40'],
            'supermarkets' => ['sometimes', 'array'],
            'supermarkets.*' => ['string', 'max:80'],
            'cuisines' => ['sometimes', 'array'],
            'cuisines.*' => ['string', 'max:80'],
            'disliked_ingredients' => ['sometimes', 'array'],
            'disliked_ingredients.*' => ['string', 'max:80'],
        ];
    }

    /**
     * Only the fields the app sent (the rest keeps its saved value).
     *
     * @return array<string, mixed>
     */
    private function validatedPreferences(Request $request): array
    {
        $validated = $request->validate($this->preferenceRules());
        foreach (['diet', 'postal_code'] as $key) {
            if (array_key_exists($key, $validated) && ($validated[$key] ?? '') === '') {
                $validated[$key] = null;
            }
        }

        return $validated;
    }

    private function household(): Household
    {
        return $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
    }
}
