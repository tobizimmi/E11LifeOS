<?php

namespace App\Support\Collections;

use App\Models\Collection;
use App\Models\CollectionProperty;
use App\Models\Item;
use App\Support\Links;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Computes a rollup column for one entry: it follows a relation column to the linked entries and
 * aggregates a numeric target property of those entries (sum, average, minimum, maximum) or counts
 * them. Everything is calculated on display and never stored.
 *
 * The configuration lives in the rollup column's options: the id of the relation column to follow
 * ("relation"), the name of the target property on the linked entries ("target"), and the aggregate
 * function ("aggregate"). Only links to entries/pages (Links::ITEM) are considered.
 */
class RollupResolver
{
    /**
     * At most this many linked entries are aggregated, as a safety bound.
     */
    private const MAX_RELATED = 500;

    /**
     * Computes the rollup for one entry, or null when it cannot be determined (count returns 0).
     *
     * @param  iterable<int, CollectionProperty>  $properties  The entry's collection's columns.
     */
    public static function forItem(CollectionProperty $rollup, Item $item, iterable $properties): ?float
    {
        $config = $rollup->rollupConfig();

        if ($config['relation'] === null) {
            return null;
        }

        $relation = collect($properties)->first(
            fn (CollectionProperty $property) => $property->id === $config['relation'] && $property->type === CollectionProperty::TYPE_RELATION
        );

        if (! $relation instanceof CollectionProperty) {
            return null;
        }

        $ids = collect((array) $item->propertyValue($relation))
            ->map(fn ($key) => Links::parseKey($key))
            ->filter(fn ($parsed) => is_array($parsed) && $parsed[0] === Links::ITEM)
            ->map(fn ($parsed) => (int) $parsed[1])
            ->unique()
            ->take(self::MAX_RELATED)
            ->values();

        if ($config['aggregate'] === 'count') {
            return (float) $ids->count();
        }

        if ($ids->isEmpty()) {
            return null;
        }

        $values = self::relatedItems($ids->all(), (int) $item->household_id)
            ->map(fn (Item $related) => self::targetValue($related, $config['target']))
            ->filter(fn ($value) => $value !== null)
            ->values();

        if ($values->isEmpty()) {
            return null;
        }

        return match ($config['aggregate']) {
            'avg' => (float) $values->avg(),
            'min' => (float) $values->min(),
            'max' => (float) $values->max(),
            default => (float) $values->sum(),
        };
    }

    /**
     * The linked entries of the same household, with their collection's columns loaded.
     *
     * @param  list<int>  $ids
     * @return EloquentCollection<int, Item>
     */
    private static function relatedItems(array $ids, int $householdId): EloquentCollection
    {
        return Item::query()
            ->whereKey($ids)
            ->where('household_id', $householdId)
            ->with('collection.properties')
            ->get();
    }

    /**
     * The numeric value of the target property (matched by name) on a linked entry, or null.
     */
    private static function targetValue(Item $related, string $target): ?float
    {
        $collection = $related->collection;

        if (! $collection instanceof Collection) {
            return null;
        }

        /** @var EloquentCollection<int, CollectionProperty> $columns */
        $columns = $collection->properties;
        $wanted = mb_strtolower(trim($target));

        $property = $columns->first(fn (CollectionProperty $column) => mb_strtolower(trim($column->name)) === $wanted);

        if (! $property instanceof CollectionProperty) {
            return null;
        }

        $value = $related->propertyValue($property);

        return is_numeric($value) ? (float) $value : null;
    }
}
