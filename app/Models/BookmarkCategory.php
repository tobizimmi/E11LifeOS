<?php

namespace App\Models;

use Database\Factories\BookmarkCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An area/category of the bookmarks module (e.g. "Shopping", "Magazines", "Articles"), shared in
 * the household.
 *
 * @property int $id
 * @property int $household_id
 * @property string $name
 * @property ?string $icon
 * @property ?string $color
 * @property int $position
 */
#[Fillable(['household_id', 'created_by', 'name', 'icon', 'color', 'position'])]
class BookmarkCategory extends Model
{
    /** @use HasFactory<BookmarkCategoryFactory> */
    use HasFactory;

    public function bookmarks(): HasMany
    {
        return $this->hasMany(Bookmark::class, 'category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
