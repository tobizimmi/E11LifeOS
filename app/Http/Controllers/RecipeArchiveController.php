<?php

namespace App\Http\Controllers;

use App\Models\Recipe;
use App\Models\RecipeCategory;
use App\Support\RecipeSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * Exports the recipes of the current filter as one ZIP: a schema.org/Recipe JSON per recipe (plus its
 * photo), a portable backup that this app and others can import again.
 */
class RecipeArchiveController extends Controller
{
    /** Keep the archive small enough to build in memory on the server. */
    public const MAX_RECIPES = 300;

    public function __invoke(Request $request): BinaryFileResponse
    {
        $user = $request->user();
        $household = $user->currentHousehold();
        abort_if($household === null, 404);

        $category = ctype_digit((string) $request->query('category')) ? RecipeCategory::where('household_id', $household->id)->find((int) $request->query('category')) : null;
        $tag = trim((string) $request->query('tag', ''));
        $ids = collect(explode(',', (string) $request->query('ids', '')))
            ->map(fn ($id) => (int) trim((string) $id))->filter()->unique()->take(self::MAX_RECIPES)->values()->all();

        $recipes = Recipe::query()->visibleTo($user, $household->id)->whereNull('archived_at')
            ->with('categories', 'tags')
            ->when($ids !== [], fn ($query) => $query->whereIn('recipes.id', $ids))
            ->when($ids === [] && $category, fn ($query) => $query->whereHas('categories', fn ($categories) => $categories->whereKey($category->id)))
            ->when($ids === [] && $tag !== '', fn ($query) => $query->whereHas('tags', fn ($tags) => $tags->where('name', $tag)))
            ->when($ids === [] && $request->boolean('favorites'), fn ($query) => $query->whereHas('favoritedBy', fn ($users) => $users->whereKey($user->id)))
            ->orderBy('title')
            ->limit(self::MAX_RECIPES)
            ->get();

        abort_if($recipes->isEmpty(), 404);

        $path = tempnam(sys_get_temp_dir(), 'recipes');
        $zip = new ZipArchive;
        abort_unless($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 500);

        $usedNames = [];

        foreach ($recipes as $recipe) {
            $base = $this->uniqueName(Str::slug($recipe->title, '-', 'de') ?: 'rezept', $recipe->id, $usedNames);
            $imageName = $this->addImage($zip, $recipe, $base);

            $zip->addFromString(
                'recipes/'.$base.'.json',
                (string) json_encode(RecipeSchema::toArray($recipe, $imageName), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            );
        }

        $zip->close();

        $label = $ids !== [] ? __('Selected recipes') : ($category->name ?? ($tag !== '' ? $tag : ($request->boolean('favorites') ? __('Favorites') : __('Our recipes'))));
        $filename = (Str::slug(__('Recipes').' '.$label, '-', 'de') ?: 'rezepte').'-'.now()->format('Y-m-d').'.zip';

        return response()->download($path, $filename, ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    /**
     * Adds the recipe's main photo to the archive and returns its relative name, or null.
     */
    private function addImage(ZipArchive $zip, Recipe $recipe, string $base): ?string
    {
        if (! $recipe->image_path || ! Storage::disk(Recipe::IMAGE_DISK)->exists($recipe->image_path)) {
            return null;
        }

        $name = 'images/'.$base.'.jpg';
        $zip->addFromString($name, (string) Storage::disk(Recipe::IMAGE_DISK)->get($recipe->image_path));

        return $name;
    }

    /**
     * A file name unique within the archive (several recipes may share a title).
     *
     * @param  array<string, bool>  $used
     */
    private function uniqueName(string $slug, int $id, array &$used): string
    {
        $name = $slug;

        if (isset($used[$name])) {
            $name = $slug.'-'.$id;
        }

        $used[$name] = true;

        return $name;
    }
}
