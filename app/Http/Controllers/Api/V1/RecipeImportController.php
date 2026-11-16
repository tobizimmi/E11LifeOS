<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Recipe;
use App\Support\RecipeAi;
use App\Support\RecipeAiException;
use App\Support\RecipeFileImporter;
use App\Support\RecipeFileImportException;
use App\Support\RecipeImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Mobile API: import recipes from a link (also Instagram), pasted text, photos, a PDF or a recipe
 * file (schema.org, Paprika, Mealie). The result is saved as a recipe the app then opens.
 */
class RecipeImportController extends ApiController
{
    public function sources(): JsonResponse
    {
        return $this->ok(['ai' => RecipeAi::isConfigured()]);
    }

    public function import(Request $request, RecipeImporter $importer, RecipeFileImporter $fileImporter): JsonResponse
    {
        $source = (string) $request->input('source', 'link');
        abort_unless(in_array($source, ['link', 'text', 'photo', 'pdf', 'file'], true), 422);

        $request->validate(match ($source) {
            'photo' => ['photos' => ['required', 'array', 'min:1', 'max:'.RecipeAi::MAX_IMAGES], 'photos.*' => ['image', 'max:15360']],
            'pdf' => ['pdf' => ['required', 'file', 'mimes:pdf', 'max:20480']],
            'file' => ['recipe_file' => ['required', 'file', 'max:20480']],
            'text' => ['text' => ['required', 'string', 'min:20', 'max:50000']],
            default => ['url' => ['required', 'url:http,https', 'max:2000'], 'caption' => ['nullable', 'string', 'max:20000']],
        });

        $household = $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));

        try {
            $drafts = match ($source) {
                'file' => $fileImporter->drafts($this->upload($request, 'recipe_file')),
                'photo' => [$importer->fromImages(array_values($request->file('photos', [])))],
                'pdf' => [$importer->fromPdf($this->upload($request, 'pdf'))],
                'text' => [$importer->fromText((string) $request->input('text'))],
                default => [$importer->fromUrl(trim((string) $request->input('url')), trim((string) $request->input('caption')) ?: null)],
            };
        } catch (RecipeAiException|RecipeFileImportException $exception) {
            $this->failure($exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            $this->failure($source === 'file' ? __('This file could not be read.') : __('The page could not be loaded. Check the link or paste the recipe text instead.'));
        }

        $recipes = [];

        foreach ($drafts as $draft) {
            if ($source !== 'file' && ! empty($draft['imageUrl'])) {
                $draft['imagePath'] = $importer->downloadImage((string) $draft['imageUrl'], $household->id);
            }

            $recipe = $fileImporter->persist($draft, $household, $this->user());

            if ($source !== 'file') {
                $recipe->forceFill(['source_type' => $draft['sourceType'] ?? null, 'image_path' => $draft['imagePath'] ?? $recipe->image_path])->save();
            }

            $recipes[] = $recipe;
        }

        return $this->ok([
            'count' => count($recipes),
            'ids' => array_map(fn (Recipe $recipe): int => $recipe->id, $recipes),
            'notice' => $drafts[0]['notice'] ?? null,
        ], 201);
    }

    private function upload(Request $request, string $key): UploadedFile
    {
        $file = $request->file($key);
        abort_unless($file instanceof UploadedFile, 422);

        return $file;
    }

    private function failure(string $message): never
    {
        throw ValidationException::withMessages(['source' => $message]);
    }
}
