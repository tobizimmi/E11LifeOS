<?php

namespace App\Http\Controllers;

use App\Models\Recipe;
use App\Support\PdfTheme;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Throwable;

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

        // The PDF editor previews unsaved settings ("draft") inline.
        abort_if(mb_strlen((string) $request->query('draft', '')) > 2000, 422);
        $draft = json_decode((string) $request->query('draft', ''), true);
        $theme = is_array($draft) ? PdfTheme::clean('general', (array) ($draft['general'] ?? [])) : PdfTheme::for($household->id, 'general');
        $layout = is_array($draft) ? PdfTheme::clean('recipe', (array) ($draft['recipe'] ?? [])) : PdfTheme::for($household->id, 'recipe');

        $html = view('recipes.pdf', [
            'recipe' => $recipe->load('categories', 'tags'),
            'servings' => $servings,
            'ingredients' => $recipe->scaledIngredients($servings),
            'steps' => $recipe->steps(),
            'nutrition' => $recipe->nutritionValues(),
            'image' => $layout['photo'] === 'none' ? null : $this->embeddedImage($recipe->image_path, $layout['photo']),
            'theme' => $theme,
            'layout' => $layout,
        ])->render();

        // Live preview of the editor: the same page as HTML, laid out like the paper.
        if ($request->query('format') === 'html') {
            $width = $layout['paper'] === 'A5' ? '148mm' : '210mm';
            $padding = $layout['paper'] === 'A5' ? '12mm' : '16mm';
            $page = '<style>html{background:#e5e5ea;}body{box-sizing:border-box;width:'.$width.';min-height:100vh;margin:0 auto;padding:'.$padding.';background:#fff;}.pdf-footer{position:static;margin-top:24pt;}img{max-width:100%;}</style>';

            return response(str_replace('</head>', $page.'</head>', PdfTheme::document($html, $household->id, theme: $theme)), 200, ['Content-Type' => 'text/html; charset=utf-8']);
        }

        $pdf = PdfTheme::render($html, $household->id, $layout['paper'], theme: is_array($draft) ? $theme : null);
        $filename = (Str::slug($recipe->title, '-', 'de') ?: 'rezept').'.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($request->boolean('inline') ? 'inline' : 'attachment').'; filename="'.$filename.'"',
        ]);
    }

    /**
     * The photo cut to the layout (wide 16:9 or square) – the PDF renderer cannot crop images itself.
     */
    private function embeddedImage(?string $path, string $size): ?string
    {
        if (! $path || ! Storage::disk(Recipe::IMAGE_DISK)->exists($path)) {
            return null;
        }

        try {
            $image = (new ImageManager(new Driver))->read((string) Storage::disk(Recipe::IMAGE_DISK)->get($path));
            $image = $size === 'small' ? $image->cover(700, 700) : $image->cover(1600, 900);

            return 'data:image/jpeg;base64,'.base64_encode((string) $image->toJpeg(82));
        } catch (Throwable) {
            return null;
        }
    }
}
