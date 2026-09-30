<?php

namespace App\Support\Import;

use App\Models\Collection;
use App\Models\CollectionProperty;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Turns table rows into collection entries: columns map to the title, an existing property,
 * a new property (type guessed from the values) or nothing. Values are converted from common
 * spreadsheet formats (German dates and numbers, "ja"/"x" checkboxes, option names).
 */
class CollectionImporter
{
    public const TITLE = 'title';

    public const NEW = 'new';

    public const SKIP = 'skip';

    private const TITLE_HEADERS = ['titel', 'title', 'name', 'bezeichnung', 'aufgabe', 'task'];

    /**
     * @param  array<int, string>  $headers
     * @return array<int, string> Target per column: "title", "new", "skip" or "property:ID".
     */
    public static function suggestMapping(Collection $collection, array $headers): array
    {
        $mapping = [];
        $titleColumn = null;

        foreach ($headers as $index => $header) {
            if (in_array(mb_strtolower($header), self::TITLE_HEADERS, true) && $titleColumn === null) {
                $titleColumn = $index;
            }
        }

        $titleColumn ??= 0;

        foreach ($headers as $index => $header) {
            $property = $collection->properties->first(fn (CollectionProperty $property) => mb_strtolower($property->name) === mb_strtolower($header));

            $mapping[$index] = match (true) {
                $index === $titleColumn => self::TITLE,
                $property !== null && $property->type !== CollectionProperty::TYPE_RELATION => 'property:'.$property->id,
                default => self::NEW,
            };
        }

        return $mapping;
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string>>  $rows
     * @param  array<int, string>  $mapping
     * @return int Number of entries created.
     */
    public static function import(Collection $collection, array $headers, array $rows, array $mapping, User $user, int $householdId): int
    {
        $members = $collection->household->members()->with('user:id,name')->get()
            ->mapWithKeys(fn ($member) => [mb_strtolower((string) $member->user?->name) => (int) $member->user_id])->all();
        $position = (int) Item::where('collection_id', $collection->id)->max('position');
        $targets = [];

        foreach ($mapping as $index => $target) {
            if ($target === self::NEW) {
                $values = array_column($rows, $index);
                $type = self::guessType($values);
                $property = $collection->properties()->create([
                    'name' => $headers[$index] ?? __('Column :number', ['number' => $index + 1]),
                    'type' => $type,
                    'options' => $type === CollectionProperty::TYPE_SELECT ? [] : null,
                    'position' => (int) $collection->properties()->max('position') + 1,
                ]);
                $targets[$index] = $property;
            } elseif (str_starts_with($target, 'property:')) {
                $property = $collection->properties->firstWhere('id', (int) substr($target, 9));

                if ($property && $property->type !== CollectionProperty::TYPE_RELATION) {
                    $targets[$index] = $property;
                }
            }
        }

        $titleColumn = array_search(self::TITLE, $mapping, true);
        $count = 0;

        foreach ($rows as $row) {
            $title = $titleColumn !== false ? trim($row[$titleColumn] ?? '') : '';
            $title = $title !== '' ? $title : collect($row)->first(fn ($value) => trim($value) !== '');

            if ($title === null) {
                continue;
            }

            $entry = new Item([
                'household_id' => $householdId,
                'collection_id' => $collection->id,
                'type' => $collection->isTasks() ? Item::TYPE_TODO : Item::TYPE_ENTRY,
                'visibility' => $collection->entryVisibility(),
                'title' => mb_substr($title, 0, 255),
                'created_by' => $user->id,
                'position' => ++$position,
            ]);

            foreach ($targets as $index => $property) {
                $entry->setPropertyValue($property, self::value($property, (string) ($row[$index] ?? ''), $members));
            }

            $entry->saveQuietly();
            $count++;
        }

        return $count;
    }

    /**
     * @param  array<int, string>  $values
     */
    public static function guessType(array $values): string
    {
        $filled = array_values(array_filter(array_map('trim', $values), fn ($value) => $value !== ''));

        if ($filled === []) {
            return CollectionProperty::TYPE_TEXT;
        }

        $all = fn (callable $test) => collect($filled)->every($test);

        return match (true) {
            $all(fn ($value) => self::date($value) !== null) => CollectionProperty::TYPE_DATE,
            $all(fn ($value) => self::number($value) !== null) => CollectionProperty::TYPE_NUMBER,
            $all(fn ($value) => preg_match('#^https?://#i', $value) === 1) => CollectionProperty::TYPE_URL,
            $all(fn ($value) => in_array(mb_strtolower($value), ['ja', 'nein', 'yes', 'no', 'x', 'true', 'false', 'wahr', 'falsch'], true)) => CollectionProperty::TYPE_CHECKBOX,
            count($filled) >= 4 && count(array_unique($filled)) <= min(10, intdiv(count($filled), 2)) && $all(fn ($value) => mb_strlen($value) <= 40) => CollectionProperty::TYPE_SELECT,
            default => CollectionProperty::TYPE_TEXT,
        };
    }

    /**
     * @param  array<string, int>  $members  User id by lower-case name.
     */
    public static function value(CollectionProperty $property, string $raw, array $members = []): mixed
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        return match ($property->type) {
            CollectionProperty::TYPE_DATE => self::date($raw),
            CollectionProperty::TYPE_NUMBER => self::number($raw),
            CollectionProperty::TYPE_CHECKBOX => in_array(mb_strtolower($raw), ['ja', 'yes', 'x', 'true', 'wahr', '1', '✓'], true) ? true : null,
            CollectionProperty::TYPE_SELECT => self::optionId($property, $raw),
            CollectionProperty::TYPE_MULTI_SELECT => array_values(array_unique(array_filter(array_map(fn ($name) => self::optionId($property, trim($name)), preg_split('/[;,]/', $raw) ?: [])))) ?: null,
            CollectionProperty::TYPE_PERSON => $members[mb_strtolower($raw)] ?? null,
            default => $property->normalizeValue($raw),
        };
    }

    /**
     * "24.12.2026", "24.12.26", "2026-12-24", "12/24/2026" or an Excel serial number.
     */
    public static function date(string $value): ?string
    {
        $value = trim($value);
        $formats = ['!d.m.Y' => '/^\d{1,2}\.\d{1,2}\.\d{4}$/', '!d.m.y' => '/^\d{1,2}\.\d{1,2}\.\d{2}$/', '!Y-m-d' => '/^\d{4}-\d{2}-\d{2}$/', '!m/d/Y' => '/^\d{1,2}\/\d{1,2}\/\d{4}$/'];

        foreach ($formats as $format => $pattern) {
            if (preg_match($pattern, $value)) {
                $date = Carbon::createFromFormat($format, $value);

                return $date && $date->format(ltrim($format, '!')) === self::padded($value, $format) ? $date->toDateString() : null;
            }
        }

        // Excel stores dates as days since 1899-12-30.
        if (preg_match('/^\d{5}(\.\d+)?$/', $value) && (float) $value >= 20000 && (float) $value <= 80000) {
            return Carbon::create(1899, 12, 30)->addDays((int) $value)->toDateString();
        }

        return null;
    }

    /**
     * "1.234,56", "1234.56", "12,5 €" → float.
     */
    public static function number(string $value): ?float
    {
        $value = trim(str_replace(['€', '%', ' ', "\u{00A0}"], '', $value));

        if ($value === '' || ! preg_match('/^-?[\d.,]+$/', $value)) {
            return null;
        }

        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = strrpos($value, ',') > strrpos($value, '.') ? str_replace(['.', ','], ['', '.'], $value) : str_replace(',', '', $value);
        } elseif (str_contains($value, ',')) {
            $value = str_replace(',', '.', $value);
        } elseif (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $value)) {
            $value = str_replace('.', '', $value);
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private static function optionId(CollectionProperty $property, string $name): ?string
    {
        if ($name === '') {
            return null;
        }

        $option = collect($property->selectOptions())->first(fn (array $option) => mb_strtolower($option['name']) === mb_strtolower($name));

        return ($option ?? $property->addOption(mb_substr($name, 0, 100)))['id'];
    }

    /**
     * Pads "1.2.2026" to "01.02.2026" so it can be compared with the parsed date.
     */
    private static function padded(string $value, string $format): string
    {
        if (str_starts_with($format, '!d.m') || str_starts_with($format, '!m/d')) {
            $separator = str_contains($value, '/') ? '/' : '.';
            $parts = explode($separator, $value);
            $parts[0] = str_pad($parts[0], 2, '0', STR_PAD_LEFT);
            $parts[1] = str_pad($parts[1], 2, '0', STR_PAD_LEFT);

            return implode($separator, $parts);
        }

        return $value;
    }
}
