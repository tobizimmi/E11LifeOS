<?php

namespace App\Support;

use App\Models\CollectionProperty;
use App\Models\Item;
use App\Support\Collections\FormulaEvaluator;
use Illuminate\Support\Carbon;

/**
 * Read-only presentation of property values for cards, lists and the detail view:
 * dates with a countdown ("in 29 days", highlighted when a deadline is near), amounts in
 * euro, links as their host name, select options as colored badges.
 */
class PropertyDisplay
{
    /**
     * @param  array<int, string>  $members  Names by user id.
     * @param  array<string, array<string, mixed>>  $nodes  Link targets by "type:id".
     * @return array{text: string, hint: ?string, tone: string, options: array<int, array<string, mixed>>, url: ?string, nodes: array<int, array<string, mixed>>, files: array<int, int>}|null Null when empty.
     */
    public static function format(CollectionProperty $property, Item $item, array $members = [], array $nodes = []): ?array
    {
        $result = ['text' => '', 'hint' => null, 'tone' => 'normal', 'options' => [], 'url' => null, 'nodes' => [], 'files' => []];

        // Formula columns store no value; they are computed from the entry's other columns on display,
        // so they are handled before the "empty value" guard below.
        if ($property->type === CollectionProperty::TYPE_FORMULA) {
            $properties = $item->relationLoaded('collection') ? $item->collection?->properties : null;

            if ($properties === null) {
                return null;
            }

            $computed = FormulaEvaluator::forItem($property, $item, $properties);

            if ($computed === null) {
                return null;
            }

            $result['text'] = self::number($computed, self::isCurrency($property));

            return $result;
        }

        $value = $item->propertyValue($property);

        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        switch ($property->type) {
            case CollectionProperty::TYPE_DATE:
                $date = Carbon::createFromFormat('!Y-m-d', (string) $value);
                $days = (int) now()->startOfDay()->diffInDays($date, false);
                $result['text'] = $date->translatedFormat('j. M Y');
                $result['hint'] = self::countdown($days);
                $result['tone'] = self::dateTone($property, $item, $days);
                break;

            case CollectionProperty::TYPE_NUMBER:
                $result['text'] = self::number((float) $value, self::isCurrency($property));
                break;

            case CollectionProperty::TYPE_SELECT:
                $option = $property->option((string) $value);

                if (! $option) {
                    return null;
                }

                $result['options'] = [$option];
                $result['text'] = $option['name'];
                break;

            case CollectionProperty::TYPE_MULTI_SELECT:
                $result['options'] = collect((array) $value)->map(fn ($id) => $property->option((string) $id))->filter()->values()->all();

                if ($result['options'] === []) {
                    return null;
                }

                $result['text'] = implode(', ', array_column($result['options'], 'name'));
                break;

            case CollectionProperty::TYPE_CHECKBOX:
                $result['text'] = $value ? '✓ '.__('Yes') : __('No');
                $result['tone'] = $value ? 'success' : 'muted';
                break;

            case CollectionProperty::TYPE_PERSON:
                $result['text'] = $members[(int) $value] ?? '—';
                break;

            case CollectionProperty::TYPE_URL:
                $result['url'] = (string) $value;
                $result['text'] = preg_replace('/^www\./', '', (string) (parse_url((string) $value, PHP_URL_HOST) ?: $value));
                break;

            case CollectionProperty::TYPE_RELATION:
                $result['nodes'] = collect((array) $value)->map(fn ($key) => $nodes[$key] ?? null)->filter()->values()->all();

                if ($result['nodes'] === []) {
                    return null;
                }

                $result['text'] = implode(', ', array_column($result['nodes'], 'title'));
                break;

            case CollectionProperty::TYPE_RATING:
                $stars = max(1, min(5, (int) $value));
                $result['text'] = str_repeat('★', $stars).str_repeat('☆', 5 - $stars);
                break;

            case CollectionProperty::TYPE_PROGRESS:
                $result['text'] = max(0, min(100, (int) $value)).' %';
                break;

            case CollectionProperty::TYPE_FILE:
            case CollectionProperty::TYPE_IMAGE:
                $result['files'] = collect((array) $value)->filter(fn ($id) => is_numeric($id))->map(fn ($id) => (int) $id)->values()->all();

                if ($result['files'] === []) {
                    return null;
                }

                $result['text'] = $property->type === CollectionProperty::TYPE_IMAGE
                    ? trans_choice(':count image|:count images', count($result['files']))
                    : trans_choice(':count file|:count files', count($result['files']));
                break;

            default:
                $result['text'] = (string) $value;
        }

        return $result;
    }

    /**
     * Amounts are recognised by the property name ("Kosten (€)", "Betrag", "Preis" …).
     */
    public static function isCurrency(CollectionProperty $property): bool
    {
        return (bool) preg_match('/€|eur\b|euro|kosten|betrag|preis|cost|amount|price/i', $property->name);
    }

    public static function number(float $value, bool $currency = false): string
    {
        $decimals = $currency || floor($value) !== $value ? 2 : 0;
        $text = number_format($value, $decimals, ',', '.');

        return $currency ? $text.' €' : $text;
    }

    private static function countdown(int $days): string
    {
        return match (true) {
            $days === 0 => __('today'),
            $days === 1 => __('tomorrow'),
            $days === -1 => __('yesterday'),
            $days > 1 => trans_choice('in :count day|in :count days', $days),
            default => trans_choice(':count day ago|:count days ago', -$days),
        };
    }

    /**
     * Deadlines (dates with a reminder, task due dates) turn orange when the reminder
     * window has started and red once passed; other dates stay neutral.
     */
    private static function dateTone(CollectionProperty $property, Item $item, int $days): string
    {
        $isDeadline = $property->remindDays() !== null || $property->system_key === 'due';

        if (! $isDeadline || $item->done) {
            return 'normal';
        }

        return match (true) {
            $days < 0 => 'danger',
            $days <= max(7, (int) $property->remindDays()) => 'warning',
            default => 'normal',
        };
    }
}
