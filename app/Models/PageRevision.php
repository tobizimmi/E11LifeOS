<?php

namespace App\Models;

use Database\Factories\PageRevisionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An earlier state of a page (title and text), kept so changes can be looked at and undone.
 *
 * @property int $item_id
 * @property ?int $user_id
 * @property string $title
 * @property ?string $body
 */
#[Fillable(['item_id', 'user_id', 'title', 'body'])]
class PageRevision extends Model
{
    /** @use HasFactory<PageRevisionFactory> */
    use HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
