<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Text of a push notification. Pushes are sent without payload; the service
 * worker fetches the undelivered messages when one arrives (see WebPush).
 *
 * @property list<array{label: string, url: string}>|null $actions
 */
#[Fillable(['user_id', 'title', 'body', 'url', 'actions', 'delivered_at'])]
class PushMessage extends Model
{
    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
            'actions' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
