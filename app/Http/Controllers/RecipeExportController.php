<?php

namespace App\Http\Controllers;

use App\Models\Recipe;
use App\Support\RecipeSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Downloads a single recipe as a portable file: schema.org/Recipe JSON (importable by other apps)
 * or Markdown (easy to read, print or paste into a message).
 */
class RecipeExportController extends Controller
{
    public function __invoke(Request $request, Recipe $recipe): Response
    {
        $household = $request->user()->currentHousehold();

        abort_unless(
            $household && Recipe::query()->visibleTo($request->user(), $household->id)->whereKey($recipe->id)->exists(),
            404,
        );

        $recipe->load('categories', 'tags');
        $slug = Str::slug($recipe->title, '-', 'de') ?: 'rezept';

        if ($request->query('format') === 'md') {
            return response(RecipeSchema::toMarkdown($recipe), 200, [
                'Content-Type' => 'text/markdown; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="'.$slug.'.md"',
            ]);
        }

        $json = json_encode(RecipeSchema::toArray($recipe, $recipe->imageUrl() ? url($recipe->imageUrl()) : null), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return response((string) $json, 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$slug.'.json"',
        ]);
    }
}
