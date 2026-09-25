<?php

namespace App\Http\Controllers;

use App\Models\Recipe;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a recipe photo (the cover or one of the additional photos) only to members who may see the recipe.
 */
class RecipeImageController extends Controller
{
    public function __invoke(Request $request, Recipe $recipe, ?int $index = null): StreamedResponse
    {
        $household = $request->user()->currentHousehold();

        abort_unless(
            $household && Recipe::query()->visibleTo($request->user(), $household->id)->whereKey($recipe->id)->exists(),
            404,
        );
        $path = $index === null ? $recipe->image_path : ($recipe->additional_images[$index] ?? null);
        abort_unless(is_string($path) && $path !== '' && Storage::disk(Recipe::IMAGE_DISK)->exists($path), 404);

        return Storage::disk(Recipe::IMAGE_DISK)->response($path, null, [
            // URLs carry a version hash of the path, so the browser may cache them for long.
            'Cache-Control' => 'private, max-age=2592000',
        ]);
    }
}
