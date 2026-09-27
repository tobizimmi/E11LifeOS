<?php

namespace App\Support;

use App\Models\CollectionProperty;
use App\Models\Item;
use App\Models\Recipe;
use App\Models\User;
use App\Support\Portfolio\PortfolioLink;
use Illuminate\Support\Str;

/**
 * Finds the pages, tasks, entries and recipes a question is most likely about, restricted
 * to what the asking member may see (plus their portfolio when asked about it), and turns them
 * into short text documents for the AI.
 */
class BrainSearch
{
    public const MAX_DOCUMENTS = 15;

    private const MAX_TEXT_LENGTH = 1500;

    private const CANDIDATES_PER_KEYWORD = 30;

    /**
     * Common German and English words that say nothing about the topic of a question.
     */
    private const STOP_WORDS = [
        'aber', 'alle', 'allem', 'alles', 'also', 'andere', 'auch', 'bei', 'bin', 'bis', 'bitte', 'dann', 'das', 'dass', 'dem', 'den', 'der', 'des', 'die',
        'dies', 'diese', 'diesem', 'diesen', 'dieser', 'doch', 'dort', 'durch', 'ein', 'eine', 'einem', 'einen', 'einer', 'eines', 'etwas', 'für', 'gab', 'gibt',
        'habe', 'haben', 'hat', 'hatte', 'hier', 'ich', 'ihr', 'ist', 'jetzt', 'kann', 'kannst', 'kein', 'keine', 'mal', 'man', 'mein', 'meine', 'meinem', 'meinen',
        'meiner', 'mich', 'mir', 'mit', 'muss', 'müssen', 'nach', 'nicht', 'noch', 'nur', 'oder', 'ohne', 'sind', 'soll', 'sollte', 'steht', 'über', 'und', 'uns',
        'unser', 'unsere', 'unter', 'viel', 'vom', 'von', 'vor', 'war', 'waren', 'was', 'welche', 'welcher', 'welches', 'wenn', 'wer', 'wie', 'wieder', 'wir',
        'wird', 'wo', 'woher', 'wohin', 'wann', 'warum', 'weshalb', 'wieso', 'zum', 'zur', 'zwischen', 'the', 'and', 'what', 'which', 'where', 'when', 'who', 'how', 'have', 'has', 'for', 'with', 'about',
    ];

    /**
     * @return array<int, array{key: string, kind: string, title: string, text: string}>
     */
    public static function documents(string $question, User $viewer, int $householdId): array
    {
        $scores = [];
        $items = [];
        $recipes = [];
        $withRecipes = Links::recipesEnabled($householdId);

        foreach (self::keywords($question) as $keyword) {
            Item::query()->forHousehold($householdId)->visibleTo($viewer)->matching($keyword)
                ->latest('updated_at')->limit(self::CANDIDATES_PER_KEYWORD)->get()
                ->each(function (Item $item) use (&$scores, &$items, $keyword) {
                    $key = Links::key(Links::ITEM, $item->id);
                    $items[$key] = $item;
                    $scores[$key] = ($scores[$key] ?? 0) + (str_contains(mb_strtolower($item->title), $keyword) ? 3 : 1);
                });

            if ($withRecipes) {
                Recipe::query()->visibleTo($viewer, $householdId)->matching($keyword)
                    ->latest('updated_at')->limit(self::CANDIDATES_PER_KEYWORD)->get()
                    ->each(function (Recipe $recipe) use (&$scores, &$recipes, $keyword) {
                        $key = Links::key(Links::RECIPE, $recipe->id);
                        $recipes[$key] = $recipe;
                        $scores[$key] = ($scores[$key] ?? 0) + (str_contains(mb_strtolower($recipe->title), $keyword) ? 3 : 1);
                    });
            }
        }

        arsort($scores);
        $keys = array_slice(array_keys($scores), 0, self::MAX_DOCUMENTS);

        // Questions like "What is due this week?" match no words: fall back to what changed recently.
        if (count($keys) < 5) {
            Item::query()->forHousehold($householdId)->visibleTo($viewer)->whereNotIn('type', [Item::TYPE_INBOX])
                ->latest('updated_at')->limit(self::MAX_DOCUMENTS - count($keys))->get()
                ->each(function (Item $item) use (&$keys, &$items) {
                    $key = Links::key(Links::ITEM, $item->id);

                    if (! in_array($key, $keys, true)) {
                        $keys[] = $key;
                        $items[$key] = $item;
                    }
                });
        }

        $loaded = collect($items)->filter(fn (Item $item, string $key) => in_array($key, $keys, true));
        $loaded->each->loadMissing(['tags', 'collection.properties']);

        $documents = collect($keys)
            ->map(fn (string $key) => isset($items[$key]) ? self::itemDocument($items[$key]) : self::recipeDocument($recipes[$key]))
            ->values()
            ->all();

        // Portfolios are personal: only the asking member's own depot is added.
        $portfolio = $viewer->canUseAddon(Addons::PORTFOLIO) ? PortfolioLink::brainDocument($viewer, $question) : null;

        return $portfolio ? array_slice([$portfolio, ...$documents], 0, self::MAX_DOCUMENTS) : $documents;
    }

    /**
     * Distinct lower-case words of at least three letters that are not stop words.
     *
     * @return array<int, string>
     */
    public static function keywords(string $question): array
    {
        return collect(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($question), -1, PREG_SPLIT_NO_EMPTY))
            ->filter(fn (string $word) => mb_strlen($word) >= 3 && ! in_array($word, self::STOP_WORDS, true))
            ->map(fn (string $word) => self::stem($word))
            ->unique()
            ->take(8)
            ->values()
            ->all();
    }

    /**
     * Rough German stem so that "Versicherungen" also finds "Versicherung" and "Autos" "Auto"
     * (the stem is used as a substring, so it must not get too short).
     */
    private static function stem(string $word): string
    {
        foreach (['ungen', 'innen', 'en', 'er', 'es', 'e', 'n', 's'] as $ending) {
            if (str_ends_with($word, $ending) && mb_strlen($word) - mb_strlen($ending) >= 4) {
                return mb_substr($word, 0, mb_strlen($word) - mb_strlen($ending)).($ending === 'ungen' ? 'ung' : '');
            }
        }

        return $word;
    }

    /**
     * @return array{key: string, kind: string, title: string, text: string}
     */
    private static function itemDocument(Item $item): array
    {
        $lines = [];

        if ($item->tags->isNotEmpty()) {
            $lines[] = 'Tags: '.$item->tags->pluck('name')->map(fn (string $name) => '#'.$name)->implode(' ');
        }

        foreach ($item->collection?->properties ?? [] as $property) {
            $value = self::propertyText($property, $item->propertyValue($property));

            if ($value !== null) {
                $lines[] = $property->name.': '.$value;
            }
        }

        $lines[] = 'Zuletzt geändert: '.$item->updated_at?->format('Y-m-d');
        $body = trim($item->body());

        if ($body !== '') {
            $lines[] = Str::limit($body, self::MAX_TEXT_LENGTH);
        }

        return [
            'key' => Links::key(Links::ITEM, $item->id),
            'kind' => Links::itemNode($item)['kind'],
            'title' => $item->title,
            'text' => implode("\n", $lines),
        ];
    }

    /**
     * @return array{key: string, kind: string, title: string, text: string}
     */
    private static function recipeDocument(Recipe $recipe): array
    {
        $ingredients = collect($recipe->ingredients ?? [])
            ->filter(fn ($ingredient) => is_array($ingredient) && ! ($ingredient['isSection'] ?? false))
            ->map(fn (array $ingredient) => trim(($ingredient['amount'] ?? '').' '.($ingredient['unit'] ?? '').' '.($ingredient['name'] ?? '')))
            ->implode(', ');

        return [
            'key' => Links::key(Links::RECIPE, $recipe->id),
            'kind' => __('Recipe'),
            'title' => $recipe->title,
            'text' => Str::limit(trim($recipe->description."\nZutaten: ".$ingredients."\n".implode("\n", $recipe->steps())), self::MAX_TEXT_LENGTH),
        ];
    }

    private static function propertyText(CollectionProperty $property, mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        return match ($property->type) {
            CollectionProperty::TYPE_SELECT => $property->option((string) $value)['name'] ?? null,
            CollectionProperty::TYPE_MULTI_SELECT => collect((array) $value)->map(fn ($id) => $property->option((string) $id)['name'] ?? null)->filter()->implode(', ') ?: null,
            CollectionProperty::TYPE_CHECKBOX => $value ? 'ja' : 'nein',
            CollectionProperty::TYPE_PERSON, CollectionProperty::TYPE_RELATION => null,
            default => is_scalar($value) ? (string) $value : null,
        };
    }
}
