<?php

namespace App\Models;

use Database\Factories\CollectionTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A household's own collection template: the properties and form settings of a collection,
 * saved to create new collections from.
 */
#[Fillable(['household_id', 'created_by', 'name', 'icon', 'description', 'definition'])]
class CollectionTemplate extends Model
{
    /** @use HasFactory<CollectionTemplateFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'definition' => 'array',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /**
     * Snapshot of a collection's structure (no entries).
     *
     * @return array{default_view: ?string, properties: array<int, array<string, mixed>>}
     */
    public static function definitionOf(Collection $collection): array
    {
        return [
            'default_view' => $collection->default_view,
            'properties' => $collection->properties->reject(fn (CollectionProperty $property) => $property->isSystem() || $property->type === CollectionProperty::TYPE_RELATION)
                ->values()
                ->map(fn (CollectionProperty $property) => [
                    'name' => $property->name,
                    'type' => $property->type,
                    'options' => $property->options,
                    'required' => $property->required,
                    // Person defaults point at members of this household only.
                    'default_value' => $property->type === CollectionProperty::TYPE_PERSON && $property->default_value !== 'me' ? null : $property->default_value,
                    'help' => $property->help,
                ])->all(),
        ];
    }

    /**
     * Creates a new collection with this template's properties.
     */
    public function createCollection(int $householdId, User $creator, string $name, string $visibility): Collection
    {
        $collection = Collection::create([
            'household_id' => $householdId,
            'name' => $name,
            'icon' => $this->icon,
            'default_view' => $this->definition['default_view'] ?? null,
            'visibility' => $visibility,
            'created_by' => $creator->id,
        ]);

        foreach (array_values((array) ($this->definition['properties'] ?? [])) as $position => $property) {
            if (! in_array($property['type'] ?? null, CollectionProperty::TYPES, true)) {
                continue;
            }

            $collection->properties()->create([
                'name' => mb_substr((string) $property['name'], 0, 100),
                'type' => $property['type'],
                'options' => $property['options'] ?? null,
                'required' => (bool) ($property['required'] ?? false),
                'default_value' => $property['default_value'] ?? null,
                'help' => $property['help'] ?? null,
                'position' => $position + 1,
            ]);
        }

        return $collection;
    }
}
