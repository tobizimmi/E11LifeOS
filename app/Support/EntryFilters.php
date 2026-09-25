<?php

namespace App\Support;

use App\Models\CollectionProperty;
use App\Models\Item;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Property filters of a collection view, e.g. "Due: overdue", "Assignee: me",
 * "Topics: contains Garden", "Amount > 50". A filter is array{property: string, operator: string, value: string}
 * where property is a property id or "title".
 */
class EntryFilters
{
    /**
     * Operators (with label) offered for a property type.
     *
     * @return array<string, string>
     */
    public static function operators(?CollectionProperty $property): array
    {
        return match ($property?->type) {
            null, CollectionProperty::TYPE_TEXT, CollectionProperty::TYPE_URL => ['contains' => __('contains'), 'not_contains' => __('does not contain'), 'empty' => __('is empty'), 'not_empty' => __('is not empty')],
            CollectionProperty::TYPE_NUMBER => ['eq' => '=', 'gt' => '>', 'lt' => '<', 'empty' => __('is empty'), 'not_empty' => __('is not empty')],
            CollectionProperty::TYPE_DATE => ['today' => __('is today'), 'overdue' => __('is overdue'), 'next_7_days' => __('in the next 7 days'), 'this_month' => __('this month'), 'before' => __('before'), 'after' => __('after'), 'empty' => __('is empty'), 'not_empty' => __('is not empty')],
            CollectionProperty::TYPE_SELECT => ['is' => __('is'), 'is_not' => __('is not'), 'empty' => __('is empty'), 'not_empty' => __('is not empty')],
            CollectionProperty::TYPE_MULTI_SELECT => ['has' => __('contains'), 'has_not' => __('does not contain'), 'empty' => __('is empty'), 'not_empty' => __('is not empty')],
            CollectionProperty::TYPE_CHECKBOX => ['checked' => __('is checked'), 'unchecked' => __('is not checked')],
            CollectionProperty::TYPE_PERSON => ['me' => __('is me'), 'is' => __('is'), 'empty' => __('is empty'), 'not_empty' => __('is not empty')],
            CollectionProperty::TYPE_RELATION => ['not_empty' => __('is not empty'), 'empty' => __('is empty')],
            default => [],
        };
    }

    /**
     * Operators that need a value typed or picked by the user.
     */
    public static function needsValue(string $operator): bool
    {
        return in_array($operator, ['contains', 'not_contains', 'eq', 'gt', 'lt', 'before', 'after', 'is', 'is_not', 'has', 'has_not'], true);
    }

    /**
     * Keeps only well-formed filters for existing properties.
     *
     * @param  array<int, mixed>  $filters
     * @param  SupportCollection<int, CollectionProperty>  $properties
     * @return array<int, array{property: string, operator: string, value: string}>
     */
    public static function sanitize(array $filters, SupportCollection $properties): array
    {
        return collect($filters)
            ->filter(fn ($filter) => is_array($filter))
            ->map(fn (array $filter) => [
                'property' => (string) ($filter['property'] ?? ''),
                'operator' => (string) ($filter['operator'] ?? ''),
                'value' => mb_substr((string) ($filter['value'] ?? ''), 0, 200),
            ])
            ->filter(function (array $filter) use ($properties) {
                $property = $filter['property'] === 'title' ? null : $properties->firstWhere('id', (int) $filter['property']);

                return ($filter['property'] === 'title' || $property) && array_key_exists($filter['operator'], self::operators($property));
            })
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * @param  SupportCollection<int, Item>  $entries
     * @param  array<int, array{property: string, operator: string, value: string}>  $filters
     * @param  SupportCollection<int, CollectionProperty>  $properties
     * @return SupportCollection<int, Item>
     */
    public static function apply(SupportCollection $entries, array $filters, SupportCollection $properties, int $userId): SupportCollection
    {
        foreach ($filters as $filter) {
            if (self::needsValue($filter['operator']) && trim($filter['value']) === '') {
                continue;
            }

            $property = $filter['property'] === 'title' ? null : $properties->firstWhere('id', (int) $filter['property']);
            $entries = $entries->filter(fn (Item $entry) => self::matches($entry, $property, $filter['operator'], $filter['value'], $userId));
        }

        return $entries->values();
    }

    private static function matches(Item $entry, ?CollectionProperty $property, string $operator, string $value, int $userId): bool
    {
        $raw = $property ? $entry->propertyValue($property) : $entry->title;
        $isEmpty = $raw === null || $raw === '' || $raw === [] || $raw === false;
        $today = now()->toDateString();

        return match ($operator) {
            'empty' => $isEmpty,
            'not_empty' => ! $isEmpty,
            'contains' => str_contains(mb_strtolower((string) $raw), mb_strtolower(trim($value))),
            'not_contains' => ! str_contains(mb_strtolower((string) $raw), mb_strtolower(trim($value))),
            'eq' => is_numeric($raw) && is_numeric(str_replace(',', '.', $value)) && (float) $raw == (float) str_replace(',', '.', $value),
            'gt' => is_numeric($raw) && is_numeric(str_replace(',', '.', $value)) && (float) $raw > (float) str_replace(',', '.', $value),
            'lt' => is_numeric($raw) && is_numeric(str_replace(',', '.', $value)) && (float) $raw < (float) str_replace(',', '.', $value),
            'today' => $raw === $today,
            'overdue' => ! $isEmpty && $raw < $today && ! $entry->done,
            'next_7_days' => ! $isEmpty && $raw >= $today && $raw <= now()->addDays(7)->toDateString(),
            'this_month' => ! $isEmpty && str_starts_with((string) $raw, now()->format('Y-m')),
            'before' => ! $isEmpty && $raw < $value,
            'after' => ! $isEmpty && $raw > $value,
            'is' => (string) $raw === $value,
            'is_not' => (string) $raw !== $value,
            'has' => in_array($value, (array) $raw, true),
            'has_not' => ! in_array($value, (array) $raw, true),
            'checked' => (bool) $raw,
            'unchecked' => ! $raw,
            'me' => (string) $raw === (string) $userId,
            default => true,
        };
    }
}
