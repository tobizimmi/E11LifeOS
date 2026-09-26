<?php

namespace App\Models;

use Database\Factories\WatchlistItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A security a user follows without holding it.
 */
#[Fillable(['user_id', 'symbol', 'name', 'notes'])]
class WatchlistItem extends Model
{
    /** @use HasFactory<WatchlistItemFactory> */
    use HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function security(): BelongsTo
    {
        return $this->belongsTo(Security::class, 'symbol', 'symbol');
    }
}
