<?php

namespace App\Models;

use App\Support\BookmarkImages;
use Database\Factories\BookmarkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A saved link/article/magazine for later, with a web-page preview (title, description, image), a
 * comment and an area (category). Shared in the household unless marked private.
 *
 * @property int $id
 * @property int $household_id
 * @property ?int $created_by
 * @property ?int $category_id
 * @property string $visibility
 * @property string $url
 * @property ?string $title
 * @property ?string $description
 * @property ?string $site_name
 * @property ?string $favicon_url
 * @property ?string $image_url
 * @property ?string $image_path
 * @property ?string $comment
 * @property bool $is_read
 * @property ?Carbon $read_at
 * @property int $position
 */
#[Fillable(['household_id', 'created_by', 'category_id', 'visibility', 'url', 'title', 'description', 'site_name', 'favicon_url', 'image_url', 'image_path', 'comment', 'summary', 'is_read', 'read_at', 'position'])]
class Bookmark extends Model
{
    /** @use HasFactory<BookmarkFactory> */
    use HasFactory;

    /** Disk for downloaded preview images (served through BookmarkImageController). */
    public const IMAGE_DISK = 'local';

    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
            'read_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleted(fn (Bookmark $bookmark) => BookmarkImages::delete($bookmark->image_path));
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(BookmarkCategory::class, 'category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPrivate(): bool
    {
        return $this->visibility === Item::VISIBILITY_PRIVATE;
    }

    /**
     * The preview image to show: the downloaded one, else the remote fallback, else none.
     */
    public function previewImageUrl(): ?string
    {
        if ($this->image_path) {
            return route('bookmarks.image', ['bookmark' => $this->id, 'v' => substr(md5($this->image_path), 0, 8)]);
        }

        return $this->image_url;
    }

    public function host(): ?string
    {
        return $this->site_name ?: (parse_url($this->url, PHP_URL_HOST) ?: null);
    }

    /**
     * Shared bookmarks of the household plus the member's own private ones.
     *
     * @param  Builder<Bookmark>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user, int $householdId): void
    {
        $query->where('household_id', $householdId)
            ->where(fn (Builder $query) => $query->where('visibility', Item::VISIBILITY_SHARED)->orWhere('created_by', $user->id));
    }
}
