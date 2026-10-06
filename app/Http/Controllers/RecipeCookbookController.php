<?php

namespace App\Http\Controllers;

use App\Models\Recipe;
use App\Models\RecipeCategory;
use App\Support\PdfTheme;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * A cookbook as one PDF: the recipes of the current filter (category, tag, favorites) with a cover
 * and a table of contents, in the design of the PDF editor.
 */
class RecipeCookbookController extends Controller
{
    /** More recipes would make the PDF too large to render on the server. */
    public const MAX_RECIPES = 60;

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $household = $user->currentHousehold();
        abort_if($household === null, 404);

        $category = ctype_digit((string) $request->query('category')) ? RecipeCategory::where('household_id', $household->id)->find((int) $request->query('category')) : null;
        $tag = trim((string) $request->query('tag', ''));
        $ids = $this->selectedIds($request);

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

        $theme = PdfTheme::for($household->id, 'general');
        $layout = PdfTheme::for($household->id, 'recipe');
        // Small square photos keep a book of many recipes light.
        $layout['photo'] = $layout['photo'] === 'none' ? 'none' : 'small';
        $title = trim((string) $request->query('title', '')) ?: ($ids !== [] ? __('Selected recipes') : ($category->name ?? ($tag !== '' ? '#'.$tag : ($request->boolean('favorites') ? __('Favorites') : __('Our recipes')))));
        $title = mb_substr($title, 0, 80);

        $html = view('recipes.cookbook', [
            'title' => $title,
            'household' => $household,
            'theme' => $theme,
            'layout' => $layout,
            'cover' => RecipePdfController::embeddedImage($recipes->firstWhere('image_path', '!=', null)?->image_path, 'large'),
            'pages' => $recipes->map(fn (Recipe $recipe) => [
                'recipe' => $recipe,
                'servings' => $recipe->servings,
                'ingredients' => $recipe->scaledIngredients($recipe->servings),
                'steps' => $recipe->steps(),
                'nutrition' => $recipe->nutritionValues(),
                'image' => $layout['photo'] === 'none' ? null : RecipePdfController::embeddedImage($recipe->image_path, 'small', 400),
            ]),
        ])->render();

        $pdf = PdfTheme::render($html, $household->id, $layout['paper']);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.(Str::slug(__('Cookbook').' '.$title, '-', 'de') ?: 'kochbuch').'.pdf"',
        ]);
    }

    /**
     * Explicitly selected recipe ids ("ids=1,2,3"); empty when the current filter should be used.
     *
     * @return array<int, int>
     */
    private function selectedIds(Request $request): array
    {
        return collect(explode(',', (string) $request->query('ids', '')))
            ->map(fn ($id) => (int) trim((string) $id))
            ->filter()
            ->unique()
            ->take(self::MAX_RECIPES)
            ->values()
            ->all();
    }
}
