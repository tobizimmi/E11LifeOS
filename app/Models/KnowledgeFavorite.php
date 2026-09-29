<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tip or news article the member marked to read again later (knowledge area).
 */
#[Fillable(['user_id', 'kind', 'key', 'title', 'url', 'summary', 'source', 'category', 'published_at'])]
class KnowledgeFavorite extends Model
{
    public const TIP = 'tip';

    public const NEWS = 'news';

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
