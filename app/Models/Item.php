<?php

namespace App\Models;

use App\Observers\ItemObserver;
use App\Support\Recurrence;
use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection as SupportCollection;

#[Fillable(['household_id', 'parent_id', 'collection_id', 'type', 'visibility', 'title', 'icon', 'content', 'properties', 'created_by', 'done', 'position'])]
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
     * Entry of a user-defined collection (tasks keep TYPE_TODO).
     */
    public const TYPE_ENTRY = 'entry';

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
            'properties' => 'array',
            'done' => 'boolean',
        ];
    }

    /**
     * Tasks always live in the household's tasks collection, whose "status"
     * property mirrors the done flag (whichever of the two changed wins).
     */
    protected static function booted(): void
    {
        static::saving(function (Item $item) {
            if ($item->type !== self::TYPE_TODO) {
                return;
            }

            if (! $item->collection_id) {
                $item->collection_id = Collection::tasksFor($item->household_id)->id;
                $item->unsetRelation('collection');
            }

            $item->syncTaskStatusWithDone();
        });

        // Embedded files go with the page (one by one, so the stored files are removed too).
        // (No return value: a non-null result would stop the other "deleting" listeners.)
        static::deleting(function (Item $item): void {
            $item->attachments()->get()->each->delete();
        });
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    private function syncTaskStatusWithDone(): void
    {
        $statusProperty = $this->collection?->systemProperty('status');

        if (! $statusProperty) {
            return;
        }

        $values = $this->properties ?? [];
        $status = $values[$statusProperty->id] ?? null;
        $originalStatus = ($this->exists ? ($this->getOriginal('properties') ?? []) : [])[$statusProperty->id] ?? null;

        if ($status !== null && $status !== $originalStatus) {
            $this->done = $status === Collection::TASK_STATUS_DONE;
        } else {
            if ($this->done) {
                $values[$statusProperty->id] = Collection::TASK_STATUS_DONE;
            } elseif ($status === null || $status === Collection::TASK_STATUS_DONE) {
                $values[$statusProperty->id] = Collection::TASK_STATUS_TODO;
            }

            $this->properties = $values;
        }

        $this->rescheduleIfRepeating($statusProperty);
    }

    /**
     * Set when completing a repeating task moved it to its next due date
     * (the UI tells the user); holds the new date.
     */
    public ?string $rescheduledTo = null;

    /**
     * A repeating task that gets completed is not closed: it moves to its
     * next due date and opens again (one task, not a growing list of copies).
     */
    private function rescheduleIfRepeating(CollectionProperty $statusProperty): void
    {
        $wasDone = $this->exists && (bool) $this->getOriginal('done');

        if (! $this->done || $wasDone) {
            return;
        }

        $repeatProperty = $this->collection->systemProperty('repeat');
        $dueProperty = $this->collection->systemProperty('due');
        $rule = $repeatProperty ? $this->propertyValue($repeatProperty) : null;
        $due = $dueProperty ? $this->propertyValue($dueProperty) : null;

        if (! $rule || ! $due || ! ($next = Recurrence::next($due, $rule))) {
            return;
        }

        $values = $this->properties ?? [];
        $values[$dueProperty->id] = $next;
        $values[$statusProperty->id] = Collection::TASK_STATUS_TODO;

        $this->properties = $values;
        $this->done = false;
        $this->rescheduledTo = $next;
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

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
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

    public function propertyValue(CollectionProperty|int $property): mixed
    {
        $id = $property instanceof CollectionProperty ? $property->id : $property;

        return ($this->properties ?? [])[$id] ?? null;
    }

    /**
     * Sets (or with null removes) one property value; call save() afterwards.
     */
    public function setPropertyValue(CollectionProperty $property, mixed $value): void
    {
        $values = $this->properties ?? [];

        if ($value === null) {
            unset($values[$property->id]);
        } else {
            $values[$property->id] = $value;
        }

        $this->properties = $values;
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
     * @return SupportCollection<int, Item>
     */
    public function backlinksVisibleTo(User $viewer): SupportCollection
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
