<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Household;
use App\Models\Item;
use App\Models\MealPlan;
use App\Models\Recipe;
use App\Models\ShoppingList;
use App\Support\ShoppingIngredients;
use App\Support\ShoppingLists;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * The meal plan for the app: what is cooked on which day, and the ingredients of a period for
 * the shopping list.
 */
class MealPlanController extends ApiController
{
    private const MAX_RANGE_DAYS = 62;

    /**
     * The entries of a period (default: this week, Monday to Sunday).
     */
    public function index(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return $this->ok([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'meal_types' => collect(MealPlan::MEAL_TYPES)->map(fn (string $type) => ['key' => $type, 'label' => MealPlan::mealTypeLabel($type)])->all(),
            'entries' => $this->entries($from, $to)->map(fn (MealPlan $entry) => $this->entryData($entry))->values()->all(),
        ]);
    }

    /**
     * Plans a recipe (recipe_id) or a free-text meal (title) for a day.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'meal_type' => ['required', Rule::in(MealPlan::MEAL_TYPES)],
            'recipe_id' => ['nullable', 'integer', 'required_without:title'],
            'title' => ['nullable', 'string', 'max:500', 'required_without:recipe_id'],
            'servings' => ['nullable', 'integer', 'min:1', 'max:99'],
            'private' => ['sometimes', 'boolean'],
        ]);

        $household = $this->household();
        $private = (bool) ($data['private'] ?? false);
        $recipe = ($data['recipe_id'] ?? null) ? Recipe::query()->visibleTo($this->user(), $household->id)->findOrFail($data['recipe_id']) : null;

        $entry = MealPlan::create([
            'household_id' => $household->id,
            'created_by' => $this->user()->id,
            'visibility' => $private || $recipe?->isPrivate() ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED,
            'date' => $data['date'],
            'meal_type' => $data['meal_type'],
            'recipe_id' => $recipe?->id,
            'title' => $recipe ? $recipe->title : trim((string) $data['title']),
            'servings' => ($data['servings'] ?? null) ?: $recipe?->servings,
        ]);

        return $this->ok($this->entryData($entry->load('recipe')), 201);
    }

    /**
     * Moves an entry to another day or meal, or changes its servings (and the title of a free-text meal).
     */
    public function update(Request $request, int $entry): JsonResponse
    {
        $model = $this->find($entry);
        $data = $request->validate([
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'meal_type' => ['sometimes', Rule::in(MealPlan::MEAL_TYPES)],
            'servings' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:99'],
            'title' => ['sometimes', 'string', 'max:500'],
        ]);

        $attributes = array_intersect_key($data, array_flip(['date', 'meal_type', 'servings']));

        if (array_key_exists('title', $data) && ! $model->recipe_id) {
            $attributes['title'] = trim($data['title']);
        }

        $model->update($attributes);

        return $this->ok($this->entryData($model->load('recipe')));
    }

    public function destroy(int $entry): JsonResponse
    {
        $this->find($entry)->delete();

        return $this->ok();
    }

    /**
     * Ingredients of all planned recipes of the period, scaled and combined.
     */
    public function ingredients(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return $this->ok($this->combinedIngredients($from, $to));
    }

    /**
     * Puts the ingredients of the period on the shopping list – all of them, or only the chosen
     * positions of the list `ingredients` returns for the same period.
     */
    public function toShopping(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);
        $data = $request->validate(['indexes' => ['sometimes', 'array'], 'indexes.*' => ['integer', 'min:0']]);

        $ingredients = $this->combinedIngredients($from, $to);
        $chosen = array_key_exists('indexes', $data) ? array_intersect_key($ingredients, array_flip($data['indexes'])) : $ingredients;
        $list = ShoppingList::defaultFor($this->household()->id);

        foreach ($chosen as $ingredient) {
            ShoppingLists::add($list, $ingredient['name'], $ingredient['quantity'] !== '' ? mb_substr($ingredient['quantity'], 0, 255) : null, $this->user()->id);
        }

        return $this->ok(['added' => count($chosen)]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(Request $request): array
    {
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $from = Carbon::parse($data['from'] ?? today()->startOfWeek(Carbon::MONDAY)->toDateString());
        $to = Carbon::parse($data['to'] ?? $from->copy()->addDays(6)->toDateString());
        abort_if($from->diffInDays($to) > self::MAX_RANGE_DAYS, 422, __('Please choose at most two months.'));

        return [$from, $to];
    }

    /**
     * Visible entries of the period; recipes the member cannot see are hidden from the entry.
     *
     * @return Collection<int, MealPlan>
     */
    private function entries(Carbon $from, Carbon $to): Collection
    {
        $entries = MealPlan::query()
            ->visibleTo($this->user(), $this->household()->id)
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->with('recipe')
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        return MealPlan::withVisibleRecipes($entries, $this->user(), $this->household()->id);
    }

    /**
     * @return array<int, array{name: string, quantity: string, recipes: array<int, string>}>
     */
    private function combinedIngredients(Carbon $from, Carbon $to): array
    {
        $lines = [];

        foreach ($this->entries($from, $to) as $entry) {
            if (! $entry->recipe instanceof Recipe) {
                continue;
            }

            foreach ($entry->recipe->scaledIngredients($entry->servings) as $ingredient) {
                if (! $ingredient['isSection']) {
                    $lines[] = $ingredient + ['recipe' => $entry->recipe->title];
                }
            }
        }

        return array_values(ShoppingIngredients::combine($lines));
    }

    private function find(int $id): MealPlan
    {
        return MealPlan::query()->visibleTo($this->user(), $this->household()->id)->findOrFail($id);
    }

    private function household(): Household
    {
        return $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function entryData(MealPlan $entry): array
    {
        return [
            'id' => $entry->id,
            'date' => $entry->date->toDateString(),
            'meal_type' => $entry->meal_type,
            'title' => $entry->title,
            'recipe_id' => $entry->recipe instanceof Recipe ? $entry->recipe->id : null,
            'servings' => $entry->servings,
            'note' => $entry->note,
            'private' => $entry->isPrivate(),
            'mine' => $entry->created_by === $this->user()->id,
        ];
    }
}
