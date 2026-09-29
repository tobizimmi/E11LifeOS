<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An external calendar (ICS link) shown in the member's calendar. The link usually contains a
 * secret, so it is stored encrypted and never shown in full again.
 */
#[Fillable(['user_id', 'name', 'url', 'fetched_at', 'error'])]
#[Hidden(['url'])]
class CalendarSubscription extends Model
{
    protected function casts(): array
    {
        return [
            'url' => 'encrypted',
            'fetched_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
