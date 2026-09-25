<?php

namespace App\Models;

use Database\Factories\CollectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Notion-style database: entries (items) with typed properties, shown as
 * table or board. Tasks are the built-in collection with system_key "tasks".
 */
#[Fillable(['household_id', 'name', 'icon', 'visibility', 'created_by', 'system_key'])]
class Collection extends Model
{
    /** @use HasFactory<CollectionFactory> */
    use HasFactory;

    public const SYSTEM_TASKS = 'tasks';

    public const DEFAULT_ICON = '🗂️';

    /**
     * Status option ids of the tasks collection; "done" mirrors items.done.
     */
    public const TASK_STATUS_TODO = 'todo';

    public const TASK_STATUS_DOING = 'doing';

    public const TASK_STATUS_DONE = 'done';

    protected $attributes = [
        'visibility' => Item::VISIBILITY_SHARED,
    ];

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function properties(): HasMany
    {
        return $this->hasMany(CollectionProperty::class)->orderBy('position')->orderBy('id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    /**
     * @param  Builder<Collection>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user, int $householdId): void
    {
        $query->where('household_id', $householdId)
            ->where(fn (Builder $query) => $query
                ->where('visibility', Item::VISIBILITY_SHARED)
                ->orWhere('created_by', $user->id));
    }

    public function isTasks(): bool
    {
        return $this->system_key === self::SYSTEM_TASKS;
    }

    public function isPrivate(): bool
    {
        return $this->visibility === Item::VISIBILITY_PRIVATE;
    }

    public function displayIcon(): string
    {
        return $this->icon ?: self::DEFAULT_ICON;
    }

    /**
     * Entries of a private collection are private to its owner; otherwise shared.
     */
    public function entryVisibility(): string
    {
        return $this->isPrivate() ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED;
    }

    public function systemProperty(string $key): ?CollectionProperty
    {
        return $this->properties->firstWhere('system_key', $key);
    }

    /**
     * The household's tasks collection, created with its built-in properties on first use.
     */
    public static function tasksFor(int $householdId): self
    {
        $existing = self::where('household_id', $householdId)->where('system_key', self::SYSTEM_TASKS)->first();

        if ($existing) {
            return $existing;
        }

        $collection = self::create([
            'household_id' => $householdId,
            'name' => __('Tasks'),
            'icon' => '✅',
            'system_key' => self::SYSTEM_TASKS,
        ]);

        $collection->properties()->createMany([
            [
                'name' => __('Status'),
                'type' => CollectionProperty::TYPE_SELECT,
                'options' => [
                    ['id' => self::TASK_STATUS_TODO, 'name' => __('Open'), 'color' => 'gray'],
                    ['id' => self::TASK_STATUS_DOING, 'name' => __('In progress'), 'color' => 'blue'],
                    ['id' => self::TASK_STATUS_DONE, 'name' => __('Done'), 'color' => 'green'],
                ],
                'system_key' => 'status',
                'position' => 0,
            ],
            ['name' => __('Due'), 'type' => CollectionProperty::TYPE_DATE, 'system_key' => 'due', 'position' => 1],
            ['name' => __('Assignee'), 'type' => CollectionProperty::TYPE_PERSON, 'system_key' => 'assignee', 'position' => 2],
        ]);

        return $collection;
    }
}
