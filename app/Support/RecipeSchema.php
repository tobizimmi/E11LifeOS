<?php

namespace App\Support;

use App\Models\Recipe;

/**
 * Turns a recipe into the standard schema.org/Recipe shape (for JSON-LD on the public page and the
 * JSON export) and into Markdown. The JSON is what other recipe apps and Google can read; the same
 * structure is parsed back by App\Support\RecipeImporter when a recipe file is imported.
 */
class RecipeSchema
{
    /**
     * schema.org/Recipe as an array, ready for json_encode (JSON-LD or the JSON export).
     *
     * @param  string|null  $imageUrl  Absolute image URL to embed; the recipe's photo is not read here.
     * @return array<string, mixed>
     */
    public static function toArray(Recipe $recipe, ?string $imageUrl = null): array
    {
        $ingredients = collect($recipe->scaledIngredients())
            ->map(fn (array $ingredient) => $ingredient['isSection']
                ? '# '.$ingredient['name']
                : trim($ingredient['amount'].' '.$ingredient['unit'].' '.$ingredient['name']))
            ->values()
            ->all();

        $data = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Recipe',
            'name' => $recipe->title,
            'description' => $recipe->description ?: null,
            'recipeYield' => $recipe->servings ? (string) $recipe->servings : null,
            'prepTime' => self::duration($recipe->prep_time),
            'cookTime' => self::duration($recipe->cook_time),
            'totalTime' => self::duration($recipe->totalTime()),
            'recipeCategory' => $recipe->relationLoaded('categories') ? $recipe->categories->pluck('name')->implode(', ') : null,
            'keywords' => $recipe->relationLoaded('tags') ? $recipe->tags->pluck('name')->implode(', ') : null,
            'image' => $imageUrl,
            'recipeIngredient' => $ingredients,
            'recipeInstructions' => array_map(
                fn (string $step) => ['@type' => 'HowToStep', 'text' => $step],
                $recipe->steps(),
            ),
            'nutrition' => self::nutrition($recipe),
        ], fn ($value) => $value !== null && $value !== [] && $value !== '');

        return $data;
    }

    /**
     * A plain-text recipe in Markdown – easy to read, print or paste into a message.
     */
    public static function toMarkdown(Recipe $recipe, ?int $servings = null): string
    {
        $lines = ['# '.$recipe->title, ''];

        if ($recipe->description) {
            $lines[] = $recipe->description;
            $lines[] = '';
        }

        $facts = array_filter([
            $servings ? __('Servings').': '.$servings : null,
            $recipe->prep_time ? __('Prep').': '.$recipe->prep_time.' '.__('min') : null,
            $recipe->cook_time ? __('Cooking').': '.$recipe->cook_time.' '.__('min') : null,
            $recipe->difficultyLabel() ? __('Difficulty').': '.$recipe->difficultyLabel() : null,
        ]);

        if ($facts !== []) {
            $lines[] = '_'.implode(' · ', $facts).'_';
            $lines[] = '';
        }

        $lines[] = '## '.__('Ingredients');

        foreach ($recipe->scaledIngredients($servings) as $ingredient) {
            $lines[] = $ingredient['isSection']
                ? "\n**".$ingredient['name'].'**'
                : '- '.trim($ingredient['amount'].' '.$ingredient['unit'].' '.$ingredient['name']);
        }

        $lines[] = '';
        $lines[] = '## '.__('Preparation');

        foreach ($recipe->steps() as $number => $step) {
            $lines[] = ($number + 1).'. '.$step;
        }

        if ($recipe->source_url) {
            $lines[] = '';
            $lines[] = __('Source').': '.$recipe->source_url;
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * schema.org NutritionInformation per serving, or null when nothing is known.
     *
     * @return array<string, string>|null
     */
    private static function nutrition(Recipe $recipe): ?array
    {
        $map = [
            'calories' => ['calories', 'kcal'],
            'protein' => ['proteinContent', 'g'],
            'carbs' => ['carbohydrateContent', 'g'],
            'fat' => ['fatContent', 'g'],
            'fiber' => ['fiberContent', 'g'],
        ];

        $nutrition = collect($recipe->nutritionValues())
            ->mapWithKeys(fn ($value, string $key) => isset($map[$key])
                ? [$map[$key][0] => str_replace('.', ',', (string) $value).' '.$map[$key][1]]
                : [])
            ->all();

        return $nutrition !== [] ? ['@type' => 'NutritionInformation'] + $nutrition : null;
    }

    /**
     * Minutes as an ISO 8601 duration ("PT1H30M"), or null.
     */
    private static function duration(?int $minutes): ?string
    {
        if (! $minutes || $minutes <= 0) {
            return null;
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return 'PT'.($hours > 0 ? $hours.'H' : '').($rest > 0 ? $rest.'M' : '');
    }
}
