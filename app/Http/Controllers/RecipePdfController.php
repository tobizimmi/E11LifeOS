<?php

namespace App\Http\Controllers;

use App\Models\Recipe;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Printable PDF of a recipe (optionally scaled to other servings) for members who may see it.
 */
class RecipePdfController extends Controller
{
    public function __invoke(Request $request, Recipe $recipe): Response
    {
        $household = $request->user()->currentHousehold();

        abort_unless(
            $household && Recipe::query()->visibleTo($request->user(), $household->id)->whereKey($recipe->id)->exists(),
            404,
        );

        $servings = $request->integer('servings') ?: $recipe->servings;
        $servings = $servings ? max(1, min(99, $servings)) : null;

        $html = view('recipes.pdf', [
            'recipe' => $recipe->load('categories', 'tags'),
            'servings' => $servings,
            'ingredients' => $recipe->scaledIngredients($servings),
            'steps' => $recipe->steps(),
            'nutrition' => $recipe->nutritionValues(),
            'image' => $this->embeddedImage($recipe->image_path),
        ])->render();

        // No remote resources: everything the PDF shows is embedded above.
        $dompdf = new Dompdf((new Options)->setIsRemoteEnabled(false)->setDefaultFont('DejaVu Sans'));
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.(Str::slug($recipe->title, '-', 'de') ?: 'rezept').'.pdf"',
        ]);
    }

    private function embeddedImage(?string $path): ?string
    {
        if (! $path || ! Storage::disk(Recipe::IMAGE_DISK)->exists($path)) {
            return null;
        }

        return 'data:image/jpeg;base64,'.base64_encode(Storage::disk(Recipe::IMAGE_DISK)->get($path));
    }
}
