<?php

namespace App\Support;

use App\Models\MealPlan;
use App\Models\Recipe;
use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Applies a generated meal plan (see {@see MealPlanAi}): it can add the invented recipes to the
 * cookbook, enter them into the weekly meal plan and build a shopping list from the consolidated
 * ingredients. Kept free of the AI call so it is fully testable with a fixed result array.
 */
class MealPlanBuilder
{
    /**
     * Creates a recipe per entry of the result.
     *
     * With an `$expiresAt` the recipes are temporary (only added to the week plan, not the
     * cookbook); they are pruned once they expire. Entries whose index is in `$keepIndexes` are
     * still created permanently, so the user can keep a few of a temporary plan's recipes.
     *
     * @param  array<string, mixed>  $result
     * @param  array<int, int>  $keepIndexes  canonical indexes (see {@see self::recipes()}) to keep permanently
     * @return array<int, Recipe> keyed by the canonical recipe index
     */
    public static function createRecipes(User $user, int $householdId, array $result, ?Carbon $expiresAt = null, array $keepIndexes = []): array
    {
        $recipes = [];

        foreach (self::recipes($result) as $index => $data) {
            $recipeExpiry = $expiresAt !== null && ! in_array($index, $keepIndexes, true) ? $expiresAt : null;
            $recipes[$index] = Recipe::create(self::recipeAttributes($data, $householdId, $user->id, $recipeExpiry));
        }

        return $recipes;
    }

    /**
     * The result's recipe entries in their canonical order (empty-title entries removed), keyed by
     * the same index {@see self::createRecipes()} and {@see self::addToPlan()} use – so the UI can
     * offer a per-recipe "keep permanently" choice that lines up with the created recipes.
     *
     * @param  array<string, mixed>  $result
     * @return array<int, array<string, mixed>>
     */
    public static function plannedRecipes(array $result): array
    {
        return self::recipes($result);
    }

    /**
     * Adds the plan entries to the weekly meal plan, linking to a saved recipe where one was created.
     *
     * @param  array<string, mixed>  $result
     * @param  array<int, Recipe>  $savedRecipes  from {@see self::createRecipes()} (empty = plan by title only)
     * @return int the number of entries created
     */
    public static function addToPlan(User $user, int $householdId, Carbon $weekStart, array $result, array $savedRecipes = []): int
    {
        $count = 0;

        foreach (self::recipes($result) as $index => $data) {
            $day = max(1, min(7, (int) ($data['day'] ?? 1)));
            $mealType = in_array($data['meal_type'] ?? '', MealPlan::MEAL_TYPES, true) ? $data['meal_type'] : 'dinner';
            $recipe = $savedRecipes[$index] ?? null;

            MealPlan::create([
                'household_id' => $householdId,
                'created_by' => $user->id,
                'date' => $weekStart->copy()->addDays($day - 1)->toDateString(),
                'meal_type' => $mealType,
                'recipe_id' => $recipe?->id,
                'title' => mb_substr((string) ($data['title'] ?? __('Meal')), 0, 500),
                'servings' => isset($data['servings']) ? (int) $data['servings'] : null,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Creates a shopping list from the consolidated ingredients of the result.
     *
     * @param  array<string, mixed>  $result
     */
    public static function createShoppingList(User $user, int $householdId, array $result, ?string $name = null): ShoppingList
    {
        $list = ShoppingList::create([
            'household_id' => $householdId,
            'created_by' => $user->id,
            'name' => mb_substr($name ?: __('Meal plan'), 0, 255),
            'icon' => '🍽️',
        ]);

        foreach (is_array($result['shopping_list'] ?? null) ? $result['shopping_list'] : [] as $item) {
            $itemName = trim((string) ($item['name'] ?? ''));

            if ($itemName === '') {
                continue;
            }

            $amount = trim((string) ($item['amount'] ?? ''));
            ShoppingLists::add($list, mb_substr($itemName, 0, 255), $amount !== '' ? mb_substr($amount, 0, 255) : null, $user->id);
        }

        return $list;
    }

    /**
     * The recipe attributes in the cookbook's format (ingredients as amount/unit/name rows).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function recipeAttributes(array $data, int $householdId, int $userId, ?Carbon $expiresAt = null): array
    {
        $ingredients = array_values(array_filter(array_map(function (mixed $ingredient): ?array {
            if (! is_array($ingredient) || trim((string) ($ingredient['name'] ?? '')) === '') {
                return null;
            }

            return ['amount' => trim((string) ($ingredient['amount'] ?? '')), 'unit' => '', 'name' => trim((string) $ingredient['name']), 'isSection' => false];
        }, is_array($data['ingredients'] ?? null) ? $data['ingredients'] : [])));

        $instructions = array_values(array_filter(array_map(
            fn ($step) => is_string($step) ? trim($step) : '',
            is_array($data['instructions'] ?? null) ? $data['instructions'] : [],
        ), fn (string $step) => $step !== ''));

        $difficulty = in_array($data['difficulty'] ?? '', Recipe::DIFFICULTIES, true) ? $data['difficulty'] : null;

        return [
            'household_id' => $householdId,
            'created_by' => $userId,
            'title' => mb_substr((string) ($data['title'] ?? __('Meal')), 0, 255),
            'description' => isset($data['description']) ? mb_substr((string) $data['description'], 0, 2000) : null,
            'ingredients' => $ingredients,
            'instructions' => $instructions,
            'servings' => isset($data['servings']) ? (int) $data['servings'] : null,
            'prep_time' => isset($data['prep_time']) ? (int) $data['prep_time'] : null,
            'cook_time' => isset($data['cook_time']) ? (int) $data['cook_time'] : null,
            'difficulty' => $difficulty,
            'nutrition' => isset($data['calories']) && $data['calories'] ? ['calories' => (int) $data['calories']] : null,
            'source_type' => 'ai',
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * The recipe entries of a result as an index-keyed list.
     *
     * @param  array<string, mixed>  $result
     * @return array<int, array<string, mixed>>
     */
    private static function recipes(array $result): array
    {
        return array_values(array_filter(
            is_array($result['recipes'] ?? null) ? $result['recipes'] : [],
            fn ($recipe) => is_array($recipe) && trim((string) ($recipe['title'] ?? '')) !== '',
        ));
    }
}
