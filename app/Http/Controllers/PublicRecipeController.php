<?php

namespace App\Http\Controllers;

use App\Models\Recipe;
use App\Support\Addons;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Read-only recipe page for people outside the household (link with a random token, no login).
 */
class PublicRecipeController extends Controller
{
    public function show(Request $request, string $token): View
    {
        $recipe = $this->findRecipe($token);
        $servings = $request->integer('servings') ?: $recipe->servings;
        $servings = $servings ? max(1, min(99, $servings)) : null;

        return view('recipes.public', [
            'recipe' => $recipe,
            'servings' => $servings,
            'ingredients' => $recipe->scaledIngredients($servings),
            'steps' => $recipe->steps(),
            'nutrition' => $recipe->nutritionValues(),
        ]);
    }

    public function image(string $token): StreamedResponse
    {
        $recipe = $this->findRecipe($token);
        abort_unless($recipe->image_path && Storage::disk(Recipe::IMAGE_DISK)->exists($recipe->image_path), 404);

        return Storage::disk(Recipe::IMAGE_DISK)->response($recipe->image_path, null, [
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /**
     * Links stop working when they are turned off or the household switches the addon off.
     */
    private function findRecipe(string $token): Recipe
    {
        $recipe = Recipe::with('household')->whereShareToken('public_token', $token, 'public_expires_at')->first();

        abort_unless($recipe && strlen($token) >= 32 && $recipe->household?->hasAddon(Addons::RECIPES), 404);

        return $recipe;
    }
}
