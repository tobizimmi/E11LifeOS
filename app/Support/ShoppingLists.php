<?php

namespace App\Support;

use App\Models\ShoppingList;
use App\Models\ShoppingListEntry;
use Illuminate\Support\Facades\DB;

/**
 * Adding to a shopping list without duplicates, and what the household buys often.
 */
class ShoppingLists
{
    /**
     * Adds an item; an open entry with the same name on that list gets the quantity added
     * ("200 g" + "300 g" = "500 g", otherwise "1 Bund + 2"). Returns the entry.
     *
     * @param  array{note?: ?string, price?: ?float, aisle?: ?string}  $extra
     */
    public static function add(ShoppingList $list, string $label, ?string $quantity, ?int $userId, array $extra = []): ShoppingListEntry
    {
        $label = mb_substr(trim($label), 0, 255);
        $quantity = trim((string) $quantity) ?: null;
        // The same item in another spelling ("Zwiebeln" for "Zwiebel") is the same entry.
        $key = IngredientUnits::nameKey($label);
        $existing = ShoppingListEntry::where('shopping_list_id', $list->id)->where('checked', false)->get()
            ->first(fn (ShoppingListEntry $entry) => IngredientUnits::nameKey($entry->label) === $key);

        if ($existing) {
            $existing->update(array_filter([
                'quantity' => self::combine($existing->quantity, $quantity),
                'note' => $extra['note'] ?? null,
                'price' => $extra['price'] ?? null,
            ], fn ($value) => $value !== null));

            return $existing;
        }

        return ShoppingListEntry::create(array_filter([
            'household_id' => $list->household_id,
            'shopping_list_id' => $list->id,
            'label' => $label,
            'quantity' => $quantity,
            'note' => isset($extra['note']) ? mb_substr((string) $extra['note'], 0, 255) : null,
            'price' => $extra['price'] ?? null,
            'aisle' => $extra['aisle'] ?? null,
            'created_by' => $userId,
        ], fn ($value) => $value !== null));
    }

    /**
     * Adds two quantities: numbers with the same unit are summed, anything else is joined.
     */
    public static function combine(?string $first, ?string $second): ?string
    {
        $first = trim((string) $first);
        $second = trim((string) $second);

        if ($first === '' || $second === '') {
            return ($first ?: $second) ?: null;
        }

        // Units of one family are converted: "500 g" + "0,5 kg" = "1 kg".
        $sum = IngredientUnits::add($first, $second);

        if ($sum !== null) {
            return $sum;
        }

        [$firstAmount, $firstUnit] = self::split($first);
        [$secondAmount, $secondUnit] = self::split($second);

        if ($firstAmount !== null && $secondAmount !== null && mb_strtolower($firstUnit) === mb_strtolower($secondUnit)) {
            return trim(IngredientAmount::format($firstAmount + $secondAmount).' '.$firstUnit);
        }

        return mb_substr($first.' + '.$second, 0, 255);
    }

    /**
     * Remembers that an item was bought (ticked off).
     */
    public static function recordPurchase(ShoppingListEntry $entry): void
    {
        $key = mb_substr(mb_strtolower(trim($entry->label)), 0, 255);
        $stat = DB::table('shopping_item_stats')->where('household_id', $entry->household_id)->where('label_key', $key)->first();
        $values = ['label' => trim($entry->label), 'quantity' => $entry->quantity, 'aisle' => $entry->aisle, 'last_bought_at' => now(), 'updated_at' => now()];

        if ($stat) {
            DB::table('shopping_item_stats')->where('id', $stat->id)->update($values + ['count' => $stat->count + 1]);
        } else {
            DB::table('shopping_item_stats')->insert($values + ['household_id' => $entry->household_id, 'label_key' => $key, 'count' => 1, 'created_at' => now()]);
        }
    }

    /**
     * Items the household buys most often that are not open on the list now.
     *
     * @return list<array{label: string, quantity: ?string, count: int}>
     */
    public static function frequent(int $householdId, ?int $listId = null, int $limit = 10): array
    {
        $open = ShoppingListEntry::where('household_id', $householdId)->where('checked', false)
            ->when($listId, fn ($query) => $query->where('shopping_list_id', $listId))
            ->pluck('label')->map(fn (string $label) => mb_strtolower(trim($label)))->all();

        return DB::table('shopping_item_stats')->where('household_id', $householdId)
            ->whereNotIn('label_key', $open === [] ? [''] : $open)
            ->orderByDesc('count')->orderByDesc('last_bought_at')->limit($limit)
            ->get(['label', 'quantity', 'count'])
            ->map(fn (object $row) => ['label' => (string) $row->label, 'quantity' => $row->quantity, 'count' => (int) $row->count])
            ->all();
    }

    /**
     * Adds one or more items from a free-text line, e.g. dictated via Siri:
     * "2 Liter Milch, Butter und 6 Eier". Splits on commas, semicolons, line breaks and the word
     * "und", takes a leading quantity per item, and de-duplicates like add().
     *
     * @return list<ShoppingListEntry>
     */
    public static function addFromText(ShoppingList $list, string $text, ?int $userId): array
    {
        $parts = preg_split('/\s*(?:,|;|\n|\r|&|\bund\b)\s*/iu', trim($text)) ?: [];
        $entries = [];

        foreach ($parts as $part) {
            [$quantity, $label] = self::splitLine(trim($part));

            if ($label === '') {
                continue;
            }

            $entries[] = self::add($list, $label, $quantity, $userId);
        }

        return $entries;
    }

    /**
     * Splits a single spoken item into a leading quantity and the item name
     * ("500 g Mehl" → ["500 g", "Mehl"], "2 Milch" → ["2", "Milch"], "Butter" → [null, "Butter"]).
     *
     * @return array{0: ?string, 1: string}
     */
    private static function splitLine(string $text): array
    {
        if (preg_match('/^([\d.,\/]+(?:\s*[a-zA-Zäöüß]{1,5}\.?)?)\s+(.+)$/u', $text, $match) && preg_match('/\d/', $match[1])) {
            return [trim($match[1]), trim($match[2])];
        }

        return [null, $text];
    }

    /**
     * Labels for the autocomplete of the add field.
     *
     * @return list<string>
     */
    public static function suggestions(int $householdId): array
    {
        return DB::table('shopping_item_stats')->where('household_id', $householdId)->orderByDesc('count')->limit(200)->pluck('label')->all();
    }

    /**
     * @return array{0: ?float, 1: string}
     */
    private static function split(string $quantity): array
    {
        if (preg_match('/^\s*([\d.,\/½¼¾]+)\s*(.*)$/u', $quantity, $match)) {
            return [IngredientAmount::parse($match[1]), trim($match[2])];
        }

        return [null, $quantity];
    }
}
