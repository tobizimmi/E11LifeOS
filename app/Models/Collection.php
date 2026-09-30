<?php

namespace App\Models;

use App\Models\Concerns\HasShareTokens;
use App\Models\Concerns\SharedWithGroups;
use App\Support\Recurrence;
use Database\Factories\CollectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Notion-style database: entries (items) with typed properties, shown as
 * table or board. Tasks are the built-in collection with system_key "tasks".
 */
#[Fillable(['public_expires_at', 'household_id', 'name', 'icon', 'default_view', 'visibility', 'created_by', 'system_key'])]
#[Hidden(['public_token', 'public_token_encrypted'])]
class Collection extends Model
{
    /** @use HasFactory<CollectionFactory> */
    use HasFactory, HasShareTokens, SharedWithGroups;

    public const SYSTEM_TASKS = 'tasks';

    public const DEFAULT_ICON = '🗂️';

    public const VIEWS = ['table', 'board', 'calendar', 'cards', 'list'];

    /**
     * Status option ids of the tasks collection; "done" mirrors items.done.
     */
    public const TASK_STATUS_TODO = 'todo';

    public const TASK_STATUS_DOING = 'doing';

    public const TASK_STATUS_DONE = 'done';

    /**
     * Priority option ids of the tasks collection.
     */
    public const TASK_PRIORITY_HIGH = 'high';

    public const TASK_PRIORITY_MEDIUM = 'medium';

    public const TASK_PRIORITY_LOW = 'low';

    protected $attributes = [
        'visibility' => Item::VISIBILITY_SHARED,
    ];

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function properties(): HasMany
    {
        return $this->hasMany(CollectionProperty::class)->orderBy('position')->orderBy('id');
    }

    public function views(): HasMany
    {
        return $this->hasMany(CollectionView::class)->orderBy('position')->orderBy('id');
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
    /**
     * Starting categories of the task list; members can rename, add and remove them.
     *
     * @return list<array{id: string, name: string, color: string}>
     */
    public static function defaultTaskCategories(): array
    {
        return [
            ['id' => 'home', 'name' => __('Household'), 'color' => 'green'],
            ['id' => 'work', 'name' => __('Work'), 'color' => 'blue'],
            ['id' => 'private', 'name' => __('Private'), 'color' => 'purple'],
            ['id' => 'finance', 'name' => __('Finances'), 'color' => 'orange'],
            ['id' => 'errands', 'name' => __('Errands'), 'color' => 'gray'],
        ];
    }

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
            [
                'name' => __('Repeat'),
                'type' => CollectionProperty::TYPE_SELECT,
                'options' => [
                    ['id' => Recurrence::DAILY, 'name' => __('Daily'), 'color' => 'gray'],
                    ['id' => Recurrence::WEEKLY, 'name' => __('Weekly'), 'color' => 'blue'],
                    ['id' => Recurrence::MONTHLY, 'name' => __('Monthly'), 'color' => 'purple'],
                    ['id' => Recurrence::YEARLY, 'name' => __('Yearly'), 'color' => 'orange'],
                ],
                'system_key' => 'repeat',
                'position' => 3,
            ],
            [
                'name' => __('Priority'),
                'type' => CollectionProperty::TYPE_SELECT,
                'options' => [
                    ['id' => self::TASK_PRIORITY_HIGH, 'name' => __('High'), 'color' => 'red'],
                    ['id' => self::TASK_PRIORITY_MEDIUM, 'name' => __('Medium'), 'color' => 'orange'],
                    ['id' => self::TASK_PRIORITY_LOW, 'name' => __('Low'), 'color' => 'blue'],
                ],
                'system_key' => 'priority',
                'position' => 4,
            ],
            ['name' => __('Category'), 'type' => CollectionProperty::TYPE_SELECT, 'options' => self::defaultTaskCategories(), 'system_key' => 'category', 'position' => 5],
        ]);

        return $collection;
    }

    protected function casts(): array
    {
        return ['public_expires_at' => 'datetime'];
    }

    /**
     * @return list<string>
     */
    protected function shareTokenAttributes(): array
    {
        return ['public_token'];
    }
}
