<?php

namespace App\Models;

use App\Observers\ItemObserver;
use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable(['household_id', 'parent_id', 'type', 'visibility', 'title', 'icon', 'content', 'created_by', 'done', 'position'])]
#[ObservedBy(ItemObserver::class)]
class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use HasFactory;

    public const TYPE_NOTE = 'note';

    public const TYPE_TODO = 'todo';

    /**
     * Quick-capture entries that have not been sorted into a module yet.
     */
    public const TYPE_INBOX = 'inbox';

    /**
     * Shown in front of pages without their own icon.
     */
    public const DEFAULT_PAGE_ICON = '📄';

    public const VISIBILITY_SHARED = 'shared';

    public const VISIBILITY_PRIVATE = 'private';

    /**
     * Matches [[Title]] and [[Title|Label]].
     */
    public const WIKI_LINK_PATTERN = '/\[\[([^\[\]|]+)(?:\|([^\[\]]+))?\]\]/u';

    protected $attributes = [
        'visibility' => self::VISIBILITY_SHARED,
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'done' => 'boolean',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /**
     * @param  Builder<Item>  $query
     */
    public function scopeOfType(Builder $query, string $type): void
    {
        $query->where('type', $type);
    }

    /**
     * @param  Builder<Item>  $query
     */
    public function scopeForHousehold(Builder $query, int $householdId): void
    {
        $query->where('items.household_id', $householdId);
    }

    /**
     * Shared items plus the user's own private ones.
     *
     * @param  Builder<Item>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(function (Builder $query) use ($user) {
            $query->where('items.visibility', self::VISIBILITY_SHARED)
                ->orWhere('items.created_by', $user->id);
        });
    }

    /**
     * Case-insensitive match on title, note body or tag name.
     *
     * @param  Builder<Item>  $query
     */
    public function scopeMatching(Builder $query, string $term): void
    {
        $like = '%'.mb_strtolower($term).'%';

        $query->where(function (Builder $query) use ($like) {
            $query->whereRaw('LOWER(items.title) LIKE ?', [$like])
                ->orWhereRaw("LOWER(JSON_EXTRACT(items.content, '$.body')) LIKE ?", [$like])
                ->orWhereHas('tags', fn (Builder $tags) => $tags->where('name', 'like', $like));
        });
    }

    public function displayIcon(): string
    {
        return $this->icon ?: self::DEFAULT_PAGE_ICON;
    }

    public function isPrivate(): bool
    {
        return $this->visibility === self::VISIBILITY_PRIVATE;
    }

    /**
     * Only the creator may switch an item between private and shared: anyone else
     * making it "private" would hide it from themselves and hand it to the creator.
     */
    public function visibilityCanBeChangedBy(User $user): bool
    {
        return $this->created_by !== null && $this->created_by === $user->id;
    }

    public function body(): string
    {
        return (string) ($this->content['body'] ?? '');
    }

    /**
     * Lower-cased titles of all notes this item links to via [[Title]].
     *
     * @return array<int, string>
     */
    public function wikiLinkTitles(): array
    {
        preg_match_all(self::WIKI_LINK_PATTERN, $this->body(), $matches);

        return collect($matches[1])
            ->map(fn (string $title) => mb_strtolower(trim($title)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Notes the viewer can see that link to this note via [[Title]].
     *
     * @return Collection<int, Item>
     */
    public function backlinksVisibleTo(User $viewer): Collection
    {
        $title = mb_strtolower($this->title);

        return self::query()
            ->forHousehold($this->household_id)
            ->visibleTo($viewer)
            ->ofType(self::TYPE_NOTE)
            ->whereKeyNot($this->id)
            ->whereRaw("JSON_EXTRACT(items.content, '$.body') LIKE ?", ['%[[%'])
            ->orderBy('title')
            ->get()
            ->filter(fn (Item $note) => in_array($title, $note->wikiLinkTitles(), true))
            ->values();
    }

    /**
     * Replaces the item's tags with the given names, creating missing household tags.
     *
     * @param  array<int, string>  $names
     */
    public function syncTagNames(array $names): void
    {
        $tagIds = collect($names)
            ->map(fn (string $name) => Tag::normalizeName($name))
            ->filter()
            ->unique()
            ->map(fn (string $name) => Tag::firstOrCreate(['household_id' => $this->household_id, 'name' => $name])->id)
            ->all();

        $this->tags()->sync($tagIds);
    }
}
