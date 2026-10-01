<?php

namespace App\Support;

use App\Models\Collection;
use App\Models\CollectionProperty;
use App\Models\Item;
use App\Models\MealPlan;
use App\Models\Recipe;
use App\Models\User;

/**
 * Suggests recipes for a week: favorites and well-rated recipes first, preferring what can
 * be cooked from the pantry (especially food that expires soon) and avoiding recipes that
 * were planned recently.
 */
class MealSuggestions
{
    /**
     * One recipe per date, never the same twice; $exclude keeps recipes out (e.g. "another one").
     *
     * @param  array<int, string>  $dates
     * @param  array<int, int>  $exclude
     * @return array<string, array{recipe: Recipe, reasons: list<string>}>
     */
    public static function forDates(User $user, int $householdId, array $dates, array $exclude = []): array
    {
        $pantry = self::pantry($user, $householdId);
        $recentlyPlanned = MealPlan::query()->visibleTo($user, $householdId)->whereNotNull('recipe_id')
            ->whereDate('date', '>=', today()->subDays(14)->toDateString())->pluck('recipe_id')->all();

        $candidates = Recipe::query()->visibleTo($user, $householdId)->whereNull('archived_at')
            ->whereNotIn('id', $exclude)
            ->with(['favoritedBy' => fn ($query) => $query->whereKey($user->id)])
            ->withAvg('ratings', 'rating')
            ->get()
            ->map(fn (Recipe $recipe) => ['recipe' => $recipe] + self::score($recipe, $pantry, $recentlyPlanned))
            ->sortByDesc('score')
            ->values();

        $suggestions = [];

        foreach ($dates as $date) {
            // A little randomness among the best ten keeps the plan varied.
            $pick = $candidates->take(10)->shuffle()->sortByDesc(fn (array $row) => $row['score'] + mt_rand(0, 150) / 100)->first();

            if (! $pick) {
                break;
            }

            $suggestions[$date] = ['recipe' => $pick['recipe'], 'reasons' => $pick['reasons']];
            $candidates = $candidates->reject(fn (array $row) => $row['recipe']->is($pick['recipe']))->values();
        }

        return $suggestions;
    }

    /**
     * Names of the pantry items in stock (lower case) and those expiring within 5 days.
     *
     * @return array{stock: array<int, string>, expiring: array<int, string>}
     */
    public static function pantry(User $user, int $householdId): array
    {
        $stock = [];
        $expiring = [];
        $soon = today()->addDays(5)->toDateString();

        Collection::query()->visibleTo($user, $householdId)->where(fn ($query) => $query->whereNull('system_key')->orWhere('system_key', Pantry::SYSTEM_KEY))->with('properties')->get()
            ->each(function (Collection $collection) use ($user, $soon, &$stock, &$expiring) {
                $bestBefore = self::property($collection, 'Best before');
                $quantity = self::property($collection, 'Quantity');

                if (! $bestBefore && ! self::property($collection, 'Minimum stock')) {
                    return;
                }

                Item::query()->forHousehold($collection->household_id)->visibleTo($user)->where('collection_id', $collection->id)->where('done', false)->get()
                    ->each(function (Item $entry) use ($bestBefore, $quantity, $soon, &$stock, &$expiring) {
                        $amount = $quantity ? $entry->propertyValue($quantity) : null;

                        if (is_numeric($amount) && (float) $amount <= 0) {
                            return;
                        }

                        $name = mb_strtolower(trim($entry->title));
                        $stock[] = $name;
                        $date = $bestBefore ? (string) $entry->propertyValue($bestBefore) : '';

                        if ($date !== '' && $date >= today()->toDateString() && $date <= $soon) {
                            $expiring[] = $name;
                        }
                    });
            });

        return ['stock' => array_values(array_unique(array_filter($stock))), 'expiring' => array_values(array_unique($expiring))];
    }

    /**
     * Whether an ingredient is (probably) in the pantry.
     *
     * @param  array<int, string>  $stock
     */
    public static function inStock(string $ingredient, array $stock): bool
    {
        $ingredient = mb_strtolower($ingredient);

        return collect($stock)->contains(fn (string $item) => str_contains($ingredient, $item) || str_contains($item, $ingredient));
    }

    /**
     * @param  array{stock: array<int, string>, expiring: array<int, string>}  $pantry
     * @param  array<int, int>  $recentlyPlanned
     * @return array{score: float, reasons: list<string>}
     */
    private static function score(Recipe $recipe, array $pantry, array $recentlyPlanned): array
    {
        $score = 0.0;
        $reasons = [];
        $ingredients = collect($recipe->scaledIngredients())->reject(fn (array $ingredient) => $ingredient['isSection'])->pluck('name');

        if ($recipe->favoritedBy->isNotEmpty()) {
            $score += 3;
            $reasons[] = '♥ '.__('Favorite');
        }

        if ($recipe->ratings_avg_rating !== null) {
            $score += ((float) $recipe->ratings_avg_rating - 3) * 0.8;

            if ((float) $recipe->ratings_avg_rating >= 4) {
                $reasons[] = '★ '.number_format((float) $recipe->ratings_avg_rating, 1, ',', '');
            }
        }

        $inStock = $ingredients->filter(fn (string $name) => self::inStock($name, $pantry['stock']));

        if ($ingredients->isNotEmpty() && $inStock->isNotEmpty()) {
            $score += 3 * $inStock->count() / $ingredients->count();
            $reasons[] = '🧺 '.trans_choice(':count ingredient in stock|:count ingredients in stock', $inStock->count());
        }

        $expiring = $ingredients->filter(fn (string $name) => self::inStock($name, $pantry['expiring']));

        if ($expiring->isNotEmpty()) {
            $score += 2.5;
            $reasons[] = '⏳ '.__('uses :items', ['items' => $expiring->take(2)->implode(', ')]);
        }

        if (in_array($recipe->id, $recentlyPlanned, true)) {
            $score -= 5;
        }

        $score += min(1.5, log(1 + (int) $recipe->cooked_count) / 2);

        return ['score' => $score, 'reasons' => $reasons];
    }

    private static function property(Collection $collection, string $name): ?CollectionProperty
    {
        $names = [mb_strtolower($name), mb_strtolower(__($name))];

        return $collection->properties->first(fn (CollectionProperty $property) => in_array(mb_strtolower($property->name), $names, true));
    }
}
