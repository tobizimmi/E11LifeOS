<?php

namespace App\Support;

use App\Models\Household;
use App\Models\Item;
use App\Models\Recipe;
use App\Models\RecipeCategory;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;
use ZipArchive;

/**
 * Imports recipes from a file instead of the AI: a schema.org/Recipe JSON (our own export, Mealie,
 * and most recipe sites), a plain JSON array of such recipes, or a Paprika ".paprikarecipes" archive.
 * Returns ready-to-save drafts; persist() turns a draft into a Recipe of the household.
 */
class RecipeFileImporter
{
    /** Never read more than this many recipes from one file. */
    private const MAX_RECIPES = 500;

    public function __construct(private RecipeImporter $importer) {}

    /**
     * Reads the file and returns a list of recipe drafts (same shape as RecipeImporter::normalize,
     * plus optional "nutrition", "tags", "categories" and "imageData" base64).
     *
     * @return array<int, array<string, mixed>>
     */
    public function drafts(UploadedFile $file): array
    {
        $bytes = (string) $file->get();

        if ($bytes === '') {
            throw new RecipeFileImportException(__('The file is empty.'));
        }

        // A Paprika archive (and any zip) starts with the "PK" signature; everything else is JSON.
        $drafts = str_starts_with($bytes, "PK\x03\x04")
            ? $this->fromArchive($bytes)
            : $this->fromJson($bytes);

        if ($drafts === []) {
            throw new RecipeFileImportException(__('No recipe was found in this file.'));
        }

        return array_slice($drafts, 0, self::MAX_RECIPES);
    }

    /**
     * Saves a draft as a recipe of the household.
     */
    public function persist(array $draft, Household $household, User $user, bool $private = false): Recipe
    {
        return DB::transaction(function () use ($draft, $household, $user, $private) {
            $recipe = new Recipe([
                'household_id' => $household->id,
                'created_by' => $user->id,
                'visibility' => $private ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED,
                'title' => $draft['title'],
                'description' => trim((string) ($draft['description'] ?? '')) ?: null,
                'prep_time' => $draft['prepTime'] ?? null,
                'cook_time' => $draft['cookTime'] ?? null,
                'servings' => $draft['servings'] ?? null,
                'source_url' => filled($draft['sourceUrl'] ?? null) ? mb_substr((string) $draft['sourceUrl'], 0, 2000) : null,
                'source_type' => 'FILE',
                'ingredients' => array_map(
                    fn (array $ingredient) => $ingredient['isSection']
                        ? ['name' => $ingredient['name'], 'isSection' => true]
                        : ['amount' => $ingredient['amount'], 'unit' => $ingredient['unit'], 'name' => $ingredient['name']],
                    $draft['ingredients'] ?? [],
                ),
                'instructions' => array_map(fn (string $step) => ['text' => $step], $draft['steps'] ?? []),
                'nutrition' => ($draft['nutrition'] ?? []) ?: null,
                'image_path' => $this->storeImage($draft, $household->id),
            ]);
            $recipe->save();

            if (filled($draft['categories'] ?? null)) {
                $recipe->categories()->sync($this->categoryIds((array) $draft['categories'], $household->id));
            }

            if (filled($draft['tags'] ?? null)) {
                $recipe->syncTagNames((array) $draft['tags']);
            }

            return $recipe;
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fromJson(string $bytes): array
    {
        $data = json_decode($bytes, true);

        if (! is_array($data)) {
            throw new RecipeFileImportException(__('This file is not a readable recipe file.'));
        }

        // A single object, a @graph, or a plain list of recipes.
        $nodes = array_is_list($data) ? $data : ($data['@graph'] ?? [$data]);

        return collect($nodes)
            ->filter(fn ($node) => is_array($node))
            ->map(fn (array $node) => $this->looksLikePaprika($node) ? $this->fromPaprika($node) : $this->fromSchema($node))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fromArchive(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'recipeimport');
        file_put_contents($path, $bytes);

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            @unlink($path);

            throw new RecipeFileImportException(__('This archive could not be opened.'));
        }

        $drafts = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);

            if (str_ends_with($name, '/') || str_starts_with(basename($name), '.')) {
                continue;
            }

            $content = (string) $zip->getFromIndex($index);
            // Paprika stores each recipe gzip-compressed; fall back to plain JSON.
            $json = @gzdecode($content) ?: $content;
            $node = json_decode($json, true);

            if (is_array($node)) {
                $draft = $this->looksLikePaprika($node) ? $this->fromPaprika($node) : $this->fromSchema($node);

                if ($draft) {
                    $drafts[] = $draft;
                }
            }
        }

        $zip->close();
        @unlink($path);

        return $drafts;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>|null
     */
    private function fromSchema(array $node): ?array
    {
        $types = (array) ($node['@type'] ?? []);

        // Only skip nodes that declare a non-Recipe type; a bare object is given the benefit of the doubt.
        if ($types !== [] && ! in_array('Recipe', $types, true)) {
            return null;
        }

        if (trim((string) ($node['name'] ?? '')) === '') {
            return null;
        }

        $draft = $this->importer->draftFromSchema($node);
        $draft['nutrition'] = $this->schemaNutrition($node['nutrition'] ?? null);
        $draft['categories'] = $this->strings($node['recipeCategory'] ?? null);
        $draft['tags'] = $this->strings($node['keywords'] ?? null);

        return $draft;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function fromPaprika(array $node): array
    {
        $draft = $this->importer->normalize([
            'title' => $node['name'] ?? '',
            'description' => $node['description'] ?? ($node['notes'] ?? ''),
            'servings' => $node['servings'] ?? null,
            'prepTime' => $this->minutes($node['prep_time'] ?? null),
            'cookTime' => $this->minutes($node['cook_time'] ?? null),
            'ingredients' => $this->paprikaIngredients((string) ($node['ingredients'] ?? '')),
            'steps' => preg_split('/\R+/u', (string) ($node['directions'] ?? '')) ?: [],
            'imageUrl' => null,
        ]);

        $draft['sourceUrl'] = $node['source_url'] ?? null;
        $draft['categories'] = $this->strings($node['categories'] ?? null);
        $draft['imageData'] = is_string($node['photo_data'] ?? null) ? $node['photo_data'] : null;

        return $draft;
    }

    /**
     * Paprika keeps ingredients and directions as one newline-separated string.
     *
     * @return array<int, array<string, mixed>>
     */
    private function paprikaIngredients(string $text): array
    {
        return collect(preg_split('/\R/u', $text) ?: [])
            ->map(fn (string $line) => trim($line))
            ->filter()
            ->map(function (string $line) {
                // A line without quantities ending in ":" is a section heading (e.g. "For the sauce:").
                if (! preg_match('/\d/', $line) && str_ends_with($line, ':')) {
                    return ['name' => rtrim($line, ':'), 'isSection' => true];
                }

                return $this->importer->parseIngredient($line);
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $names
     * @return array<int, int>
     */
    private function categoryIds(array $names, int $householdId): array
    {
        return collect($names)
            ->map(fn ($name) => mb_substr(trim((string) $name), 0, 191))
            ->filter()
            ->unique()
            ->map(fn (string $name) => RecipeCategory::firstOrCreate(['household_id' => $householdId, 'name' => $name])->id)
            ->values()
            ->all();
    }

    private function storeImage(array $draft, int $householdId): ?string
    {
        $data = $draft['imageData'] ?? null;

        if (! is_string($data) || $data === '') {
            return null;
        }

        try {
            $bytes = base64_decode($data, true);

            return $bytes !== false && $bytes !== '' ? RecipeImages::store($bytes, $householdId) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * schema.org NutritionInformation → our per-serving keys.
     *
     * @return array<string, int|float>
     */
    private function schemaNutrition(mixed $nutrition): array
    {
        if (! is_array($nutrition)) {
            return [];
        }

        $map = ['calories' => 'calories', 'proteinContent' => 'protein', 'carbohydrateContent' => 'carbs', 'fatContent' => 'fat', 'fiberContent' => 'fiber'];
        $values = [];

        foreach ($map as $schemaKey => $ownKey) {
            if (isset($nutrition[$schemaKey]) && preg_match('/[\d.,]+/', (string) $nutrition[$schemaKey], $match)) {
                $values[$ownKey] = (float) str_replace(',', '.', $match[0]);
            }
        }

        return $values;
    }

    /**
     * A comma-separated string or a list → a clean array of names.
     *
     * @return array<int, string>
     */
    private function strings(mixed $value): array
    {
        $items = is_array($value) ? $value : explode(',', (string) $value);

        return collect($items)
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function minutes(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value
            : (is_string($value) && preg_match('/\d+/', $value, $match) ? (int) $match[0] : null);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function looksLikePaprika(array $node): bool
    {
        // Paprika uses free-text "directions"/"ingredients" strings; schema.org uses recipeInstructions.
        return is_string($node['directions'] ?? null) || is_string($node['ingredients'] ?? null);
    }
}
