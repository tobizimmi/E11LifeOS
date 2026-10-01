<?php

namespace App\Support\Life;

use App\Models\Collection;
use App\Models\CollectionProperty;
use App\Models\Item;
use App\Models\User;
use App\Support\CollectionTemplates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Throwable;

/**
 * Warranties of appliances and purchases: every entry of a collection with a "Warranty until"
 * date (the household inventory and anything built like it), sorted by when the warranty ends.
 */
class Warranties
{
    /** Warranties ending within this many days count as "ending soon". */
    public const SOON_DAYS = 60;

    public static function isWarrantyField(CollectionProperty $property): bool
    {
        return $property->type === CollectionProperty::TYPE_DATE && (bool) preg_match('/garantie|gewährleistung|warranty/i', $property->name);
    }

    /**
     * @return SupportCollection<int, array{item: Item, collection: Collection, until: Carbon, purchased: ?Carbon, price: ?float, status: 'active'|'expired'|'soon', days: int}>
     */
    public static function list(User $user, int $householdId): SupportCollection
    {
        $collections = Collection::where('household_id', $householdId)->get()->keyBy('id');
        $fields = self::fields($collections->keys()->all());
        $collections = $collections->filter(fn (Collection $collection) => isset($fields[$collection->id]['until']));

        if ($collections->isEmpty()) {
            return collect();
        }

        return Item::query()->forHousehold($householdId)->visibleTo($user)->whereIn('collection_id', $collections->keys())->get()
            ->map(function (Item $item) use ($collections, $fields) {
                $field = $fields[$item->collection_id];
                $until = self::date($item->propertyValue($field['until']));
                $collection = $collections->get($item->collection_id);

                if (! $until || ! $collection instanceof Collection) {
                    return null;
                }

                $price = isset($field['price']) ? $item->propertyValue($field['price']) : null;
                $days = (int) today()->diffInDays($until, false);

                return [
                    'item' => $item,
                    'collection' => $collection,
                    'until' => $until,
                    'purchased' => isset($field['purchased']) ? self::date($item->propertyValue($field['purchased'])) : null,
                    'price' => is_numeric($price) ? (float) $price : null,
                    'status' => $days < 0 ? 'expired' : ($days <= self::SOON_DAYS ? 'soon' : 'active'),
                    'days' => $days,
                ];
            })
            ->filter()
            ->sortBy(fn (array $row) => [$row['status'] === 'expired' ? 1 : 0, $row['status'] === 'expired' ? -$row['until']->timestamp : $row['until']->timestamp])
            ->values();
    }

    /**
     * Warranty, purchase date and price fields per collection (only collections with a warranty field).
     *
     * @param  list<int>  $collectionIds
     * @return array<int, array{until?: CollectionProperty, purchased?: CollectionProperty, price?: CollectionProperty}>
     */
    private static function fields(array $collectionIds): array
    {
        $fields = [];

        foreach (CollectionProperty::whereIn('collection_id', $collectionIds)->orderBy('position')->orderBy('id')->get() as $property) {
            $role = match (true) {
                self::isWarrantyField($property) => 'until',
                $property->type === CollectionProperty::TYPE_DATE && (bool) preg_match('/gekauft|kauf|purchase/i', $property->name) => 'purchased',
                $property->type === CollectionProperty::TYPE_NUMBER && (bool) preg_match('/preis|price/i', $property->name) => 'price',
                default => null,
            };

            if ($role !== null && ! isset($fields[$property->collection_id][$role])) {
                $fields[$property->collection_id][$role] = $property;
            }
        }

        return $fields;
    }

    /**
     * The collection new appliances go into: an existing one with a warranty date, otherwise a new
     * household inventory made from the template.
     */
    public static function inventoryFor(User $user, int $householdId): Collection
    {
        $collections = Collection::where('household_id', $householdId)->orderBy('id')->get();
        $fields = self::fields($collections->pluck('id')->all());
        $existing = $collections->first(fn (Collection $collection) => isset($fields[$collection->id]['until']));

        if ($existing instanceof Collection) {
            return $existing;
        }

        $collection = Collection::create([
            'household_id' => $householdId,
            'name' => __('Household inventory'),
            'icon' => CollectionTemplates::all()['inventory']['icon'],
            'visibility' => Item::VISIBILITY_SHARED,
            'created_by' => $user->id,
        ]);
        CollectionTemplates::apply($collection, 'inventory');

        return $collection;
    }

    /**
     * Adds an appliance with purchase date, price and warranty end.
     */
    public static function add(User $user, int $householdId, string $title, Carbon $purchased, Carbon $until, ?float $price): Item
    {
        $collection = self::inventoryFor($user, $householdId);
        $item = new Item([
            'household_id' => $householdId,
            'collection_id' => $collection->id,
            'type' => Item::TYPE_ENTRY,
            'visibility' => $collection->entryVisibility(),
            'title' => mb_substr($title, 0, 255),
            'created_by' => $user->id,
            'position' => (int) Item::where('collection_id', $collection->id)->max('position') + 1,
        ]);

        $field = self::fields([$collection->id])[$collection->id] ?? [];

        if (isset($field['until'])) {
            $item->setPropertyValue($field['until'], $until->toDateString());
        }

        if (isset($field['purchased'])) {
            $item->setPropertyValue($field['purchased'], $purchased->toDateString());
        }

        if ($price !== null && isset($field['price'])) {
            $item->setPropertyValue($field['price'], $price);
        }

        $item->save();

        return $item;
    }

    private static function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
