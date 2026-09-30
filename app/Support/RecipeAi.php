<?php

namespace App\Support;

use App\Models\Recipe;
use Throwable;

/**
 * Claude API calls of the recipes addon: reading recipes from text, photos/screenshots
 * or PDFs, and estimating nutrition. Answers are forced into a JSON schema.
 */
class RecipeAi extends StructuredAi
{
    protected const FEATURE = AiFeatures::RECIPES;

    public const MAX_IMAGES = 5;

    /**
     * @var array<string, mixed>
     */
    private const RECIPE_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'found' => ['type' => 'boolean', 'description' => 'false if the input contains no recipe'],
            'title' => ['type' => 'string'],
            'description' => ['type' => 'string', 'description' => 'one or two sentences, empty if none'],
            'servings' => ['type' => 'integer', 'description' => '0 if unknown'],
            'prepTime' => ['type' => 'integer', 'description' => 'minutes, 0 if unknown'],
            'cookTime' => ['type' => 'integer', 'description' => 'minutes, 0 if unknown'],
            'ingredients' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'amount' => ['type' => 'string', 'description' => 'e.g. "200", "1/2", "2-3", empty if none'],
                        'unit' => ['type' => 'string', 'description' => 'e.g. "g", "EL", empty if none'],
                        'name' => ['type' => 'string'],
                        'isSection' => ['type' => 'boolean', 'description' => 'true for a heading like "Für den Teig"'],
                    ],
                    'required' => ['amount', 'unit', 'name', 'isSection'],
                    'additionalProperties' => false,
                ],
            ],
            'steps' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => ['found', 'title', 'description', 'servings', 'prepTime', 'cookTime', 'ingredients', 'steps'],
        'additionalProperties' => false,
    ];

    /**
     * @var array<string, mixed>
     */
    private const NUTRITION_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'calories' => ['type' => 'integer', 'description' => 'kcal per serving'],
            'protein' => ['type' => 'integer', 'description' => 'g per serving'],
            'carbs' => ['type' => 'integer', 'description' => 'g per serving'],
            'fat' => ['type' => 'integer', 'description' => 'g per serving'],
            'fiber' => ['type' => 'integer', 'description' => 'g per serving'],
        ],
        'required' => ['calories', 'protein', 'carbs', 'fat', 'fiber'],
        'additionalProperties' => false,
    ];

    private const RECIPE_INSTRUCTIONS = 'Extract the recipe. Keep the original language (usually German) and wording; '
        .'split amount, unit and ingredient name; keep ingredient group headings as sections; '
        .'one preparation step per entry without numbering. Do not invent missing information.';

    /**
     * @return array<string, mixed>
     */
    public function extractFromText(string $text): array
    {
        return $this->recipe([
            ['type' => 'text', 'text' => self::RECIPE_INSTRUCTIONS."\n\n<source>\n".$text."\n</source>"],
        ]);
    }

    /**
     * Photos or screenshots of one recipe (e.g. several pages of a cookbook).
     *
     * @param  array<int, array{data: string, mediaType: string}>  $images  Raw bytes and MIME type.
     * @return array<string, mixed>
     */
    public function extractFromImages(array $images): array
    {
        $content = collect($images)->take(self::MAX_IMAGES)
            ->map(fn (array $image) => ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $image['mediaType'], 'data' => base64_encode($image['data'])]])
            ->values()
            ->all();
        $content[] = ['type' => 'text', 'text' => self::RECIPE_INSTRUCTIONS.' The images show one recipe, possibly across several pages.'];

        return $this->recipe($content);
    }

    /**
     * @return array<string, mixed>
     */
    public function extractFromPdf(string $pdf): array
    {
        return $this->recipe([
            ['type' => 'document', 'source' => ['type' => 'base64', 'mediaType' => 'application/pdf', 'data' => base64_encode($pdf)]],
            ['type' => 'text', 'text' => self::RECIPE_INSTRUCTIONS.' If the PDF contains several recipes, take the first one.'],
        ]);
    }

    /**
     * Rough nutrition values per serving, estimated from the ingredient list.
     *
     * @return array{calories: int, protein: int, carbs: int, fat: int, fiber: int}
     */
    public function estimateNutrition(Recipe $recipe): array
    {
        $ingredients = collect($recipe->scaledIngredients())
            ->reject(fn (array $ingredient) => $ingredient['isSection'])
            ->map(fn (array $ingredient) => trim($ingredient['amount'].' '.$ingredient['unit'].' '.$ingredient['name']))
            ->implode("\n");

        $values = $this->ask([
            ['type' => 'text', 'text' => "Estimate the nutrition values per serving for this recipe ({$recipe->title}, "
                .($recipe->servings ?: 1)." servings). Use typical values for the ingredients; round to whole numbers.\n\nIngredients:\n".$ingredients],
        ], self::NUTRITION_SCHEMA, 2000);

        return array_map(fn ($value) => max(0, (int) $value), $values);
    }

    /**
     * @param  array<int, array<string, mixed>>  $content
     * @return array<string, mixed>
     */
    private function recipe(array $content): array
    {
        $recipe = $this->ask($content, self::RECIPE_SCHEMA, 16000);

        if (! ($recipe['found'] ?? false)) {
            $this->fail(__('No recipe was found in the source.'));
        }

        return $recipe;
    }

    protected function fail(string $message, ?Throwable $previous = null): never
    {
        throw new RecipeAiException($message, previous: $previous);
    }
}
