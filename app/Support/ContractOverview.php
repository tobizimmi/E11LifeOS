<?php

namespace App\Support;

use App\Models\Collection;
use App\Models\CollectionProperty;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * What the household pays for its contracts: every collection with a cost field (like the
 * "Contracts & documents" template) is read, the costs are summed per month and year and the
 * next cancellation deadlines are listed.
 */
class ContractOverview
{
    /**
     * @return array{contracts: list<array{item: Item, collection: Collection, provider: ?string, customer_number: ?string, category: ?string, monthly: float, cancel_by: ?string, ends_on: ?string, url: string}>, monthly: float, yearly: float, by_category: array<string, float>, deadlines: list<array<string, mixed>>}
     */
    public static function for(User $user, int $householdId, int $deadlineDays = 90): array
    {
        $contracts = [];

        Collection::query()->visibleTo($user, $householdId)->with('properties')->get()
            ->each(function (Collection $collection) use ($user, &$contracts) {
                $cost = $collection->properties->first(fn (CollectionProperty $property) => $property->type === CollectionProperty::TYPE_NUMBER && self::isCost($property->name));

                if (! $cost) {
                    return;
                }

                $yearly = (bool) preg_match('/jähr|jahr|year|annual/i', $cost->name);
                $provider = self::property($collection, ['Provider']);
                $customerNumber = self::property($collection, ['Customer number']);
                $category = self::property($collection, ['Category']);
                $cancelBy = self::property($collection, ['Cancel by']);
                $endsOn = self::property($collection, ['Ends on']);

                Item::query()->forHousehold($collection->household_id)->visibleTo($user)
                    ->where('collection_id', $collection->id)->where('done', false)->orderBy('title')->get()
                    ->each(function (Item $item) use ($collection, $cost, $yearly, $provider, $customerNumber, $category, $cancelBy, $endsOn, &$contracts) {
                        $amount = (float) $item->propertyValue($cost);

                        $contracts[] = [
                            'item' => $item,
                            'collection' => $collection,
                            'provider' => self::text($item, $provider),
                            'customer_number' => self::text($item, $customerNumber),
                            'category' => self::text($item, $category),
                            'monthly' => round($yearly ? $amount / 12 : $amount, 2),
                            'cancel_by' => self::text($item, $cancelBy),
                            'ends_on' => self::text($item, $endsOn),
                            'url' => route('collections.show', ['collection' => $collection->id, 'entry' => $item->id]),
                        ];
                    });
            });

        usort($contracts, fn (array $a, array $b) => $b['monthly'] <=> $a['monthly']);
        $monthly = round(array_sum(array_column($contracts, 'monthly')), 2);
        $byCategory = [];

        foreach ($contracts as $contract) {
            $key = $contract['category'] ?: __('Other');
            $byCategory[$key] = round(($byCategory[$key] ?? 0) + $contract['monthly'], 2);
        }

        arsort($byCategory);
        $today = today()->toDateString();
        $limit = today()->addDays($deadlineDays)->toDateString();
        $deadlines = array_values(array_filter($contracts, fn (array $contract) => $contract['cancel_by'] && $contract['cancel_by'] >= $today && $contract['cancel_by'] <= $limit));
        usort($deadlines, fn (array $a, array $b) => $a['cancel_by'] <=> $b['cancel_by']);

        return ['contracts' => $contracts, 'monthly' => $monthly, 'yearly' => round($monthly * 12, 2), 'by_category' => $byCategory, 'deadlines' => $deadlines];
    }

    /**
     * Days until a date, negative when it has passed.
     */
    public static function daysUntil(string $date): int
    {
        return (int) today()->diffInDays(Carbon::parse($date), false);
    }

    public static function isCost(string $name): bool
    {
        return (bool) preg_match('/kosten|cost|beitrag|gebühr|fee|preis|price/i', $name) && ! preg_match('/kaufpreis|purchase/i', $name);
    }

    /**
     * Finds a property by its English template name or its translation.
     *
     * @param  list<string>  $names
     */
    public static function property(Collection $collection, array $names): ?CollectionProperty
    {
        $candidates = array_map('mb_strtolower', [...$names, ...array_map(fn (string $name) => __($name), $names)]);

        return $collection->properties->first(fn (CollectionProperty $property) => in_array(mb_strtolower($property->name), $candidates, true));
    }

    private static function text(Item $item, ?CollectionProperty $property): ?string
    {
        if (! $property) {
            return null;
        }

        $value = $item->propertyValue($property);

        if ($property->type === CollectionProperty::TYPE_SELECT) {
            $value = $property->option((string) $value)['name'] ?? null;
        }

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
