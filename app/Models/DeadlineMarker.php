<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Remembers that a life-area deadline (e.g. an expiring document or a vehicle inspection) has already
 * been turned into a to-do, so the same deadline is not created twice. The key encodes the source and
 * the date, so a renewed date creates a fresh task.
 */
#[Fillable(['user_id', 'key', 'due_on'])]
class DeadlineMarker extends Model
{
    protected function casts(): array
    {
        return ['due_on' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
