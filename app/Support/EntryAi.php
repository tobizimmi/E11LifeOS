<?php

namespace App\Support;

use App\Models\Collection;
use App\Models\CollectionProperty;

/**
 * Reads a document (contract, policy, invoice, photo of a letter …) and fills in the
 * fields of a collection entry. Values are matched to the collection's properties.
 */
class EntryAi extends StructuredAi
{
    public const MEDIA_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];

    /**
     * @var array<string, mixed>
     */
    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'title' => ['type' => 'string', 'description' => 'short title for the entry, e.g. "Handyvertrag Telekom"'],
            'fields' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'exactly one of the given field names'],
                        'value' => ['type' => 'string'],
                    ],
                    'required' => ['name', 'value'],
                    'additionalProperties' => false,
                ],
            ],
            'notes' => ['type' => 'string', 'description' => 'other important facts from the document in one to three short lines, empty if none'],
        ],
        'required' => ['title', 'fields', 'notes'],
        'additionalProperties' => false,
    ];

    /**
     * @return array{title: string, values: array<int, mixed>, notes: string} Values by property id, already normalized.
     */
    public function extract(Collection $collection, string $data, string $mediaType): array
    {
        $properties = $collection->properties->reject(fn (CollectionProperty $property) => in_array($property->type, [CollectionProperty::TYPE_PERSON, CollectionProperty::TYPE_RELATION], true));

        $fieldList = $properties->map(fn (CollectionProperty $property) => '- '.$property->name.' ('.match ($property->type) {
            CollectionProperty::TYPE_DATE => 'date as YYYY-MM-DD',
            CollectionProperty::TYPE_NUMBER => 'number with a dot as decimal separator, no currency sign',
            CollectionProperty::TYPE_CHECKBOX => '"yes" or "no"',
            CollectionProperty::TYPE_URL => 'URL starting with https://',
            CollectionProperty::TYPE_SELECT => 'one of: '.implode(', ', array_column($property->selectOptions(), 'name')),
            CollectionProperty::TYPE_MULTI_SELECT => 'comma-separated, from: '.implode(', ', array_column($property->selectOptions(), 'name')),
            default => 'text',
        }.')')->implode("\n");

        $source = $mediaType === 'application/pdf'
            ? ['type' => 'document', 'source' => ['type' => 'base64', 'mediaType' => 'application/pdf', 'data' => base64_encode($data)]]
            : ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $mediaType, 'data' => base64_encode($data)]];

        $result = $this->ask([
            $source,
            ['type' => 'text', 'text' => 'This document belongs in the collection "'.$collection->name.'". Fill in the fields you can find in the document; '
                .'leave out fields that are not in it – never guess. Deadlines like "Kündigung 3 Monate zum Vertragsende" belong into the date field for the '
                .'last day to cancel if the end date is known. Today is '.now()->toDateString().". Keep the language of the document.\n\nFields:\n".$fieldList],
        ], self::SCHEMA, 2000);

        $values = [];

        foreach ((array) ($result['fields'] ?? []) as $field) {
            $property = $properties->first(fn (CollectionProperty $property) => mb_strtolower($property->name) === mb_strtolower(trim((string) ($field['name'] ?? ''))));
            $raw = trim((string) ($field['value'] ?? ''));

            if (! $property || $raw === '') {
                continue;
            }

            $value = match ($property->type) {
                CollectionProperty::TYPE_SELECT => self::optionId($property, $raw),
                CollectionProperty::TYPE_MULTI_SELECT => array_values(array_filter(array_map(fn (string $name) => self::optionId($property, trim($name)), explode(',', $raw)))),
                CollectionProperty::TYPE_CHECKBOX => in_array(mb_strtolower($raw), ['yes', 'ja', 'true', '1'], true),
                CollectionProperty::TYPE_NUMBER => str_replace(',', '.', preg_replace('/[^\d,.\-]/', '', $raw)),
                default => $raw,
            };

            $normalized = $property->normalizeValue($value);

            if ($normalized !== null) {
                $values[$property->id] = $normalized;
            }
        }

        return ['title' => mb_substr(trim((string) ($result['title'] ?? '')), 0, 255), 'values' => $values, 'notes' => trim((string) ($result['notes'] ?? ''))];
    }

    private static function optionId(CollectionProperty $property, string $name): ?string
    {
        return collect($property->selectOptions())->first(fn (array $option) => mb_strtolower($option['name']) === mb_strtolower($name))['id'] ?? null;
    }
}
