<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Household;
use App\Models\Recipe;
use Illuminate\Http\JsonResponse;

/**
 * Mobile API for recipes (read-only): browse the household's recipes and open one with its
 * ingredients and steps. Gated by the "recipes" addon.
 */
class RecipeController extends ApiController
{
    public function index(): JsonResponse
    {
        $recipes = Recipe::query()
            ->visibleTo($this->user(), $this->household()->id)
            ->whereNull('archived_at')
            ->with('categories:id,name')
            ->orderBy('title')
            ->get()
            ->map(fn (Recipe $recipe): array => $this->listData($recipe))
            ->all();

        return $this->ok($recipes);
    }

    public function show(Recipe $recipe): JsonResponse
    {
        abort_unless(
            Recipe::query()->visibleTo($this->user(), $this->household()->id)->whereKey($recipe->id)->exists(),
            404,
        );

        $recipe->load('categories:id,name');

        return $this->ok($this->listData($recipe) + [
            'ingredients' => $recipe->ingredients ?? [],
            'instructions' => $recipe->instructions ?? [],
            'nutrition' => $recipe->nutrition,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function listData(Recipe $recipe): array
    {
        return [
            'id' => $recipe->id,
            'title' => $recipe->title,
            'description' => $recipe->description,
            'image_url' => $recipe->imageUrl(),
            'prep_time' => $recipe->prep_time,
            'cook_time' => $recipe->cook_time,
            'total_time' => $recipe->totalTime(),
            'servings' => $recipe->servings,
            'difficulty' => $recipe->difficulty,
            'cooked_count' => (int) $recipe->cooked_count,
            'categories' => $recipe->categories->pluck('name')->all(),
        ];
    }

    private function household(): Household
    {
        return $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
    }
}
