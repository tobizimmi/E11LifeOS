<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An own checklist of the household or a member: tasks with sub-items and a day offset each, and
 * optionally packing items with their category. The same template creates tasks, a checklist
 * page or a trip packing list.
 *
 * @property int $id
 * @property int $household_id
 * @property ?int $created_by
 * @property string $name
 * @property ?string $icon
 * @property string $visibility
 * @property array<int, array<string, mixed>> $tasks title, offset and subtasks each
 * @property ?array<int, mixed> $packing [label, category] each
 */
#[Fillable(['household_id', 'created_by', 'name', 'icon', 'visibility', 'tasks', 'packing'])]
class ChecklistTemplate extends Model
{
    protected function casts(): array
    {
        return [
            'tasks' => 'array',
            'packing' => 'array',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /**
     * Shared templates of the household and the member's private ones.
     *
     * @param  Builder<ChecklistTemplate>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user, int $householdId): void
    {
        $query->where('household_id', $householdId)
            ->where(fn (Builder $query) => $query->where('visibility', Item::VISIBILITY_SHARED)->orWhere('created_by', $user->id));
    }

    public function isPrivate(): bool
    {
        return $this->visibility === Item::VISIBILITY_PRIVATE;
    }

    /**
     * Every item of the template with its category: the packing items or else all sub-items.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function items(): array
    {
        if ($this->packing) {
            return array_values(array_map(fn (array $item) => [(string) $item[0], (string) $item[1]], array_filter($this->packing, fn ($item) => is_array($item) && isset($item[0], $item[1]))));
        }

        $items = [];

        foreach ($this->tasks as $task) {
            foreach ((array) ($task['subtasks'] ?? []) as $subtask) {
                $items[] = [(string) $subtask, 'other'];
            }
        }

        return $items;
    }

    /**
     * The template as a Markdown checklist for a page (one heading per task).
     */
    public function toMarkdown(): string
    {
        $blocks = [];

        foreach ($this->tasks as $task) {
            $lines = count($this->tasks) > 1 ? ['## '.$task['title'], ''] : [];

            foreach ((array) ($task['subtasks'] ?? []) as $subtask) {
                $lines[] = '- [ ] '.$subtask;
            }

            $blocks[] = implode("\n", $lines);
        }

        return implode("\n\n", $blocks);
    }
}
