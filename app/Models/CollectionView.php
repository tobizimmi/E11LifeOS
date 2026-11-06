<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved view of a collection, shared with everyone who can see the collection.
 *
 * @property array<string, mixed> $settings
 */
#[Fillable(['collection_id', 'created_by', 'name', 'settings', 'position'])]
class CollectionView extends Model
{
    /**
     * Component properties a view stores and restores.
     */
    public const SETTINGS = ['viewMode', 'search', 'tag', 'sort', 'direction', 'groupBy', 'hideDone', 'calendarRange', 'dateBy', 'filters', 'filterMatch', 'hiddenProperties'];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }
}
