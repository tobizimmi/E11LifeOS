<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Household;
use App\Models\RecipeCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Mobile API for the household's recipe categories (the same list the web "Recipe categories"
 * page manages). Deleting a category keeps its recipes; they only lose the category.
 */
class RecipeCategoryController extends ApiController
{
    public function index(): JsonResponse
    {
        $categories = RecipeCategory::query()
            ->where('household_id', $this->household()->id)
            ->withCount(['recipes' => fn ($query) => $query->cookbook()])
            ->orderBy('position')
            ->orderBy('name')
            ->get()
            ->map(fn (RecipeCategory $category): array => $this->data($category, (int) $category->getAttribute('recipes_count')))
            ->all();

        return $this->ok($categories);
    }

    public function store(Request $request): JsonResponse
    {
        $householdId = $this->household()->id;
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('recipe_categories', 'name')->where('household_id', $householdId)],
            'icon' => ['nullable', 'string', 'max:16'],
        ]);

        $category = RecipeCategory::create([
            'household_id' => $householdId,
            'name' => trim((string) $validated['name']),
            'icon' => trim((string) ($validated['icon'] ?? '')) ?: null,
            'position' => (int) RecipeCategory::where('household_id', $householdId)->max('position') + 1,
        ]);

        return $this->ok($this->data($category, 0), 201);
    }

    public function update(Request $request, int $category): JsonResponse
    {
        $model = $this->find($category);
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('recipe_categories', 'name')->where('household_id', $this->household()->id)->ignore($model->id)],
            'icon' => ['sometimes', 'nullable', 'string', 'max:16'],
        ]);

        if (array_key_exists('name', $validated)) {
            $model->name = trim((string) $validated['name']);
        }
        if (array_key_exists('icon', $validated)) {
            $model->icon = trim((string) ($validated['icon'] ?? '')) ?: null;
        }
        $model->save();

        return $this->ok($this->data($model, $model->recipes()->whereNull('recipes.expires_at')->count()));
    }

    /**
     * Swap the category with its neighbour above (-1) or below (+1), like the web arrows.
     */
    public function move(Request $request, int $category): JsonResponse
    {
        $direction = (int) $request->validate(['direction' => ['required', 'in:-1,1']])['direction'];
        $this->find($category);

        $ordered = RecipeCategory::where('household_id', $this->household()->id)->orderBy('position')->orderBy('name')->get()->values();
        $index = $ordered->search(fn (RecipeCategory $item): bool => $item->id === $category);
        $target = $index === false ? false : $index + $direction;

        if ($index !== false && $target !== false && isset($ordered[$target])) {
            $list = $ordered->all();
            [$list[$index], $list[$target]] = [$list[$target], $list[$index]];
            foreach ($list as $position => $item) {
                $item->update(['position' => $position]);
            }
        }

        return $this->ok(['moved' => true]);
    }

    public function destroy(int $category): JsonResponse
    {
        $this->find($category)->delete();

        return $this->ok(['deleted' => true]);
    }

    private function household(): Household
    {
        return $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
    }

    private function find(int $id): RecipeCategory
    {
        return RecipeCategory::where('household_id', $this->household()->id)->findOrFail($id);
    }

    /**
     * @return array{id: int, name: string, icon: string|null, color: string|null, position: int, recipes: int}
     */
    private function data(RecipeCategory $category, int $recipes): array
    {
        return [
            'id' => $category->id,
            'name' => (string) $category->getAttribute('name'),
            'icon' => $category->getAttribute('icon') === null ? null : (string) $category->getAttribute('icon'),
            'color' => $category->getAttribute('color') === null ? null : (string) $category->getAttribute('color'),
            'position' => (int) $category->getAttribute('position'),
            'recipes' => $recipes,
        ];
    }
}
