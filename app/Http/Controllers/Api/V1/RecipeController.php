<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Household;
use App\Models\Recipe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile API for recipes: browse the household's recipes, open one with its ingredients and
 * steps, and create/edit/delete recipes. Gated by the "recipes" addon.
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

        return $this->ok($this->detailData($recipe));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedRecipe($request, creating: true);

        $recipe = new Recipe($data);
        $recipe->household_id = $this->household()->id;
        $recipe->created_by = $this->user()->id;
        $recipe->save();

        $recipe->load('categories:id,name');

        return $this->ok($this->detailData($recipe), 201);
    }

    public function update(Request $request, Recipe $recipe): JsonResponse
    {
        $this->authorizeRecipe($recipe);

        $recipe->fill($this->validatedRecipe($request, creating: false));
        $recipe->save();

        $recipe->load('categories:id,name');

        return $this->ok($this->detailData($recipe));
    }

    public function destroy(Recipe $recipe): JsonResponse
    {
        $this->authorizeRecipe($recipe);
        $recipe->delete();

        return $this->ok();
    }

    /**
     * Only recipes the member can see in their household may be edited or removed.
     */
    private function authorizeRecipe(Recipe $recipe): void
    {
        abort_unless(
            Recipe::query()->visibleTo($this->user(), $this->household()->id)->whereKey($recipe->id)->exists(),
            404,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedRecipe(Request $request, bool $creating): array
    {
        return $request->validate([
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'servings' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:999'],
            'prep_time' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000'],
            'cook_time' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000'],
            'difficulty' => ['sometimes', 'nullable', 'in:easy,medium,hard'],
            'ingredients' => ['sometimes', 'nullable', 'array'],
            'ingredients.*' => ['string', 'max:500'],
            'instructions' => ['sometimes', 'nullable', 'array'],
            'instructions.*' => ['string', 'max:5000'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function detailData(Recipe $recipe): array
    {
        return $this->listData($recipe) + [
            'ingredients' => $recipe->ingredients ?? [],
            'instructions' => $recipe->instructions ?? [],
            'nutrition' => $recipe->nutrition,
        ];
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
