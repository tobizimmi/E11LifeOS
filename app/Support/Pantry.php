<?php

namespace App\Support;

use App\Models\Collection;
use App\Models\CollectionProperty;
use App\Models\Item;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;

/**
 * The household's pantry (the "Pantry" collection) together with recipes and the shopping list:
 * which ingredients are in stock, what cooking a recipe uses up, what runs out or expires soon.
 */
class Pantry
{
    public const SYSTEM_KEY = 'pantry';

    /** Names of the pantry fields in German and English (collections made from the template before). */
    private const FIELD_NAMES = [
        'quantity' => ['menge', 'quantity', 'bestand'],
        'unit' => ['einheit', 'unit'],
        'minimum' => ['mindestbestand', 'minimum stock', 'minimum'],
        'best_before' => ['mindestens haltbar bis', 'mhd', 'best before', 'haltbar bis', 'mindesthaltbarkeit'],
        'kind' => ['art', 'kind', 'typ', 'type'],
        'place' => ['ort', 'place', 'lagerort'],
    ];

    /** Option ids of the "Kind" field. */
    public const KIND_FOOD = 'food';

    public const KIND_SUPPLIES = 'supplies';

    /**
     * The pantry collection of the household (a collection made from the pantry template is adopted).
     */
    public static function collectionFor(int $householdId): ?Collection
    {
        $collection = Collection::where('household_id', $householdId)->where('system_key', self::SYSTEM_KEY)->with('properties')->first();

        if ($collection) {
            return $collection;
        }

        $candidate = Collection::where('household_id', $householdId)->whereNull('system_key')->with('properties')->get()
            ->first(fn (Collection $collection) => self::fieldFor($collection, 'quantity') && self::fieldFor($collection, 'best_before'));

        if ($candidate) {
            self::adopt($candidate);
        }

        return $candidate;
    }

    /**
     * Marks a collection as the pantry and its fields as quantity, unit, minimum and best-before date.
     */
    public static function adopt(Collection $collection): void
    {
        $collection->update(['system_key' => self::SYSTEM_KEY]);

        foreach (array_keys(self::FIELD_NAMES) as $key) {
            self::fieldFor($collection, $key)?->update(['system_key' => $key]);
        }

        $collection->load('properties');
    }

    private static function fieldFor(Collection $collection, string $key): ?CollectionProperty
    {
        $type = match ($key) {
            'quantity', 'minimum' => CollectionProperty::TYPE_NUMBER,
            'best_before' => CollectionProperty::TYPE_DATE,
            'kind', 'place' => CollectionProperty::TYPE_SELECT,
            default => CollectionProperty::TYPE_TEXT,
        };

        $properties = CollectionProperty::where('collection_id', $collection->id)->get();

        return $properties->firstWhere('system_key', $key)
            ?? $properties->first(fn (CollectionProperty $property) => $property->type === $type && $property->system_key === null
                && in_array(mb_strtolower(trim($property->name)), self::FIELD_NAMES[$key], true));
    }

    /**
     * Everything in the pantry the user sees.
     *
     * @return SupportCollection<int, array{item: Item, name: string, key: string, quantity: ?float, unit: string, minimum: ?float, best_before: ?Carbon}>
     */
    public static function stock(User $user, int $householdId): SupportCollection
    {
        $collection = self::collectionFor($householdId);

        if (! $collection) {
            return collect();
        }

        $fields = collect(array_keys(self::FIELD_NAMES))->mapWithKeys(fn (string $key) => [$key => self::fieldFor($collection, $key)]);

        return Item::query()->where('collection_id', $collection->id)->visibleTo($user)->where('title', '!=', '')->orderBy('title')->get()
            ->map(function (Item $item) use ($fields) {
                $value = fn (string $key) => $fields[$key] ? $item->propertyValue($fields[$key]) : null;
                $date = $value('best_before');

                return [
                    'item' => $item,
                    'name' => (string) $item->title,
                    'key' => IngredientUnits::nameKey((string) $item->title),
                    'quantity' => is_numeric($value('quantity')) ? (float) $value('quantity') : null,
                    'unit' => IngredientUnits::normalize((string) $value('unit')),
                    'minimum' => is_numeric($value('minimum')) ? (float) $value('minimum') : null,
                    'best_before' => is_string($date) && $date !== '' ? Carbon::parse($date) : null,
                ];
            })
            ->values();
    }

    /**
     * The pantry entry for an ingredient ("Weizenmehl Type 405" finds "Mehl"), or null.
     *
     * @param  SupportCollection<int, array{item: Item, name: string, key: string, quantity: ?float, unit: string, minimum: ?float, best_before: ?Carbon}>  $stock
     * @return ?array{item: Item, name: string, key: string, quantity: ?float, unit: string, minimum: ?float, best_before: ?Carbon}
     */
    public static function match(SupportCollection $stock, string $ingredient): ?array
    {
        $key = IngredientUnits::nameKey($ingredient);

        if ($key === '') {
            return null;
        }

        // Otherwise a word of the ingredient that is or ends with the pantry name (German compounds:
        // "Weizenmehl Type 405" → "Mehl"); names of several words have to appear as they are.
        $words = preg_split('/[\s\-\/]+/u', $key) ?: [];

        return $stock->first(fn (array $entry) => $entry['key'] === $key)
            ?? $stock->filter(fn (array $entry) => mb_strlen($entry['key']) >= 3 && (str_contains($entry['key'], ' ')
                ? str_contains($key, $entry['key'])
                : collect($words)->contains(fn (string $word) => $word === $entry['key'] || (mb_strlen($entry['key']) >= 4 && str_ends_with($word, $entry['key'])))))
                ->sortByDesc(fn (array $entry) => mb_strlen($entry['key']))->first();
    }

    /**
     * How much of an ingredient amount is in the unit of the pantry entry (null if not comparable).
     */
    private static function inPantryUnit(?string $amount, string $unit, array $entry): ?float
    {
        $value = IngredientAmount::parse((string) $amount);

        if ($value === null || IngredientUnits::family($unit) !== IngredientUnits::family($entry['unit'])) {
            return null;
        }

        return (float) IngredientUnits::toBase($value, $unit) / (float) IngredientUnits::toBase(1, $entry['unit']);
    }

    /**
     * What cooking the recipe (for these servings) takes from the pantry.
     *
     * @return list<array{item_id: int, name: string, ingredient: string, before: float, used: float, after: float, unit: string, low: bool}>
     */
    public static function usage(Recipe $recipe, int $servings, User $user, int $householdId): array
    {
        $stock = self::stock($user, $householdId);
        $usage = [];

        foreach ($recipe->scaledIngredients($servings) as $ingredient) {
            if ($ingredient['isSection'] || ! ($entry = self::match($stock, $ingredient['name'])) || $entry['quantity'] === null) {
                continue;
            }

            $used = self::inPantryUnit($ingredient['amount'], IngredientUnits::normalize($ingredient['unit']), $entry);

            if ($used === null || $used <= 0) {
                continue;
            }

            $id = $entry['item']->id;
            $before = isset($usage[$id]) ? $usage[$id]['after'] : $entry['quantity'];
            $after = max(0.0, round($before - $used, 3));
            $usage[$id] = [
                'item_id' => $id, 'name' => $entry['name'], 'ingredient' => $ingredient['name'],
                'before' => $usage[$id]['before'] ?? $entry['quantity'], 'used' => round(($usage[$id]['used'] ?? 0) + $used, 3), 'after' => $after,
                'unit' => $entry['unit'], 'low' => $entry['minimum'] !== null ? $after < $entry['minimum'] : $after <= 0,
            ];
        }

        return array_values($usage);
    }

    /**
     * Takes the used amounts out of the pantry; returns the entries that are now low on stock.
     *
     * @param  list<array{item_id: int, name: string, ingredient: string, before: float, used: float, after: float, unit: string, low: bool}>  $usage
     * @return list<string> names of entries below their minimum (or empty)
     */
    public static function take(array $usage, int $householdId): array
    {
        $collection = self::collectionFor($householdId);
        $field = $collection ? self::fieldFor($collection, 'quantity') : null;

        if (! $collection || ! $field) {
            return [];
        }

        $low = [];

        foreach ($usage as $use) {
            $item = Item::where('collection_id', $collection->id)->find($use['item_id']);

            if (! $item) {
                continue;
            }

            $item->setPropertyValue($field, $use['after'] + 0);
            $item->save();

            if ($use['low']) {
                $low[] = $use['name'];
            }
        }

        return $low;
    }

    /**
     * Ingredients of the recipe that the pantry covers completely (indexes of scaledIngredients()).
     *
     * @return list<int>
     */
    public static function covered(Recipe $recipe, int $servings, User $user, int $householdId): array
    {
        $stock = self::stock($user, $householdId);
        $covered = [];

        foreach ($recipe->scaledIngredients($servings) as $index => $ingredient) {
            $entry = $ingredient['isSection'] ? null : self::match($stock, $ingredient['name']);

            if (! $entry || $entry['quantity'] === null || $entry['quantity'] <= 0) {
                continue;
            }

            $needed = self::inPantryUnit($ingredient['amount'], IngredientUnits::normalize($ingredient['unit']), $entry);

            // Without a comparable amount (e.g. "etwas Salz") anything in stock is enough.
            if ($needed === null ? trim((string) $ingredient['amount']) === '' || IngredientAmount::parse((string) $ingredient['amount']) === null : $needed <= $entry['quantity']) {
                $covered[] = $index;
            }
        }

        return $covered;
    }

    /**
     * Pantry entries whose best-before date is within the next days (or has passed).
     *
     * @return SupportCollection<int, array{item: Item, name: string, key: string, quantity: ?float, unit: string, minimum: ?float, best_before: Carbon}>
     */
    public static function expiring(User $user, int $householdId, int $days = 3): SupportCollection
    {
        return self::stock($user, $householdId)
            ->filter(fn (array $entry) => $entry['best_before'] && $entry['best_before']->lte(today()->addDays($days)) && ($entry['quantity'] === null || $entry['quantity'] > 0))
            ->sortBy(fn (array $entry) => $entry['best_before']->timestamp)
            ->values();
    }

    /**
     * The pantry collection, created from the template when the household has none yet; it always
     * gets the "Kind" field (groceries or household supplies like toilet paper and detergent).
     */
    public static function ensure(User $user, int $householdId): Collection
    {
        $collection = self::collectionFor($householdId);

        if (! $collection) {
            $collection = Collection::create([
                'household_id' => $householdId,
                'name' => __('Pantry'),
                'icon' => '🥫',
                'visibility' => Item::VISIBILITY_SHARED,
                'created_by' => $user->id,
            ]);
            CollectionTemplates::apply($collection, 'pantry');
            $collection = self::collectionFor($householdId) ?? $collection;
        }

        if (! self::fieldFor($collection, 'kind')) {
            $collection->properties()->create([
                'name' => __('Kind'),
                'type' => CollectionProperty::TYPE_SELECT,
                'system_key' => 'kind',
                'position' => (int) $collection->properties()->max('position') + 1,
                'options' => self::kindOptions(),
            ]);
        }

        return $collection->load('properties');
    }

    /**
     * @return list<array{id: string, name: string, color: string}>
     */
    public static function kindOptions(): array
    {
        return [
            ['id' => self::KIND_FOOD, 'name' => __('Groceries'), 'color' => 'green'],
            ['id' => self::KIND_SUPPLIES, 'name' => __('Household supplies'), 'color' => 'blue'],
        ];
    }

    /**
     * Everything in stock with kind and storage place, for the pantry page.
     *
     * @return SupportCollection<int, array{item: Item, name: string, key: string, quantity: ?float, unit: string, minimum: ?float, best_before: ?Carbon, kind: string, place: string, low: bool}>
     */
    public static function inventory(User $user, int $householdId): SupportCollection
    {
        $collection = self::collectionFor($householdId);

        if (! $collection) {
            return collect();
        }

        $kind = self::fieldFor($collection, 'kind');
        $place = self::fieldFor($collection, 'place');

        return self::stock($user, $householdId)->map(function (array $entry) use ($kind, $place) {
            $kindOption = $kind?->option((string) $entry['item']->propertyValue($kind));
            $placeOption = $place?->option((string) $entry['item']->propertyValue($place));
            $isSupplies = $kindOption && ($kindOption['id'] === self::KIND_SUPPLIES || preg_match('/verbrauch|haushalt|supplies|drogerie/i', (string) $kindOption['name']));

            return [
                'item' => $entry['item'],
                'name' => $entry['name'],
                'key' => $entry['key'],
                'quantity' => $entry['quantity'],
                'unit' => $entry['unit'],
                'minimum' => $entry['minimum'],
                'best_before' => $entry['best_before'],
                'kind' => $isSupplies ? self::KIND_SUPPLIES : self::KIND_FOOD,
                'place' => (string) ($placeOption['name'] ?? ''),
                'low' => $entry['minimum'] !== null && ($entry['quantity'] ?? 0) <= $entry['minimum'],
            ];
        })->values();
    }

    /**
     * Adds an entry, or adds the quantity to an existing one with the same name.
     *
     * @param  array{name: string, kind: string, quantity: ?float, unit: string, minimum: ?float, place: string, best_before: ?string}  $data
     */
    public static function add(User $user, int $householdId, array $data): Item
    {
        $collection = self::ensure($user, $householdId);
        $key = IngredientUnits::nameKey($data['name']);
        $item = self::stock($user, $householdId)->first(fn (array $entry) => $entry['key'] === $key)['item'] ?? null;
        $quantityField = self::fieldFor($collection, 'quantity');

        if ($item) {
            if ($quantityField && $data['quantity'] !== null) {
                $item->setPropertyValue($quantityField, (float) $item->propertyValue($quantityField) + $data['quantity']);
            }
        } else {
            $item = new Item([
                'household_id' => $householdId,
                'collection_id' => $collection->id,
                'type' => Item::TYPE_ENTRY,
                'visibility' => $collection->entryVisibility(),
                'title' => mb_substr(trim($data['name']), 0, 255),
                'created_by' => $user->id,
                'position' => (int) Item::where('collection_id', $collection->id)->max('position') + 1,
            ]);

            if ($quantityField && $data['quantity'] !== null) {
                $item->setPropertyValue($quantityField, $data['quantity']);
            }

            if (($field = self::fieldFor($collection, 'unit')) && $data['unit'] !== '') {
                $item->setPropertyValue($field, $data['unit']);
            }

            if ($field = self::fieldFor($collection, 'kind')) {
                $item->setPropertyValue($field, self::kindOptionId($field, $data['kind']));
            }
        }

        if (($field = self::fieldFor($collection, 'minimum')) && $data['minimum'] !== null) {
            $item->setPropertyValue($field, $data['minimum']);
        }

        if (($field = self::fieldFor($collection, 'best_before')) && $data['best_before']) {
            $item->setPropertyValue($field, $data['best_before']);
        }

        if (($field = self::fieldFor($collection, 'place')) && $data['place'] !== '' && $field->option($data['place'])) {
            $item->setPropertyValue($field, $data['place']);
        }

        $item->save();

        return $item;
    }

    /**
     * One more or one less of an entry (never below zero); returns the new quantity.
     */
    public static function adjust(Item $item, float $delta): float
    {
        $collection = self::collectionFor($item->household_id);
        $field = $collection && $item->collection_id === $collection->id ? self::fieldFor($collection, 'quantity') : null;

        if (! $field) {
            return 0;
        }

        $quantity = max(0, (float) $item->propertyValue($field) + $delta);
        $item->setPropertyValue($field, $quantity + 0);
        $item->save();

        return $quantity;
    }

    /**
     * The storage places of the pantry ("Fridge", "Cupboard", …) by option id.
     *
     * @return array<string, string>
     */
    public static function places(Collection $collection): array
    {
        return collect(self::fieldFor($collection, 'place')?->selectOptions() ?? [])->pluck('name', 'id')->all();
    }

    /**
     * The option of the "Kind" field for groceries or supplies (adopted fields have own ids).
     */
    private static function kindOptionId(CollectionProperty $field, string $kind): ?string
    {
        foreach ($field->selectOptions() as $option) {
            $isSupplies = $option['id'] === self::KIND_SUPPLIES || preg_match('/verbrauch|haushalt|supplies|drogerie/i', (string) $option['name']);

            if ($isSupplies === ($kind === self::KIND_SUPPLIES)) {
                return $option['id'];
            }
        }

        return null;
    }
}
