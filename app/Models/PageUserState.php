<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A user's own state for a page: whether it is a favourite (pinned in the sidebar) and when they
 * last opened it (for "recently visited"). One row per user and page.
 *
 * @property int $user_id
 * @property int $item_id
 * @property bool $favorite
 * @property ?Carbon $visited_at
 */
#[Fillable(['user_id', 'item_id', 'favorite', 'visited_at'])]
class PageUserState extends Model
{
    protected function casts(): array
    {
        return ['favorite' => 'boolean', 'visited_at' => 'datetime'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
