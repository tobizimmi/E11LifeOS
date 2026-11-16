<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A news headline from a finance RSS feed, for one security or the market in general.
 *
 * @property Carbon|null $published_at
 */
#[Fillable(['symbol', 'title', 'url', 'url_hash', 'source', 'feed', 'summary', 'thumbnail', 'published_at', 'notified_at'])]
class NewsArticle extends Model
{
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }
}
