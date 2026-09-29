<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing to pack for a trip.
 */
#[Fillable(['trip_id', 'label', 'category', 'person', 'packed', 'position'])]
class TripPackingItem extends Model
{
    protected function casts(): array
    {
        return ['packed' => 'boolean', 'position' => 'integer'];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }
}
