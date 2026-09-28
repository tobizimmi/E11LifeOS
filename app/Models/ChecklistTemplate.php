<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A checklist the household saved from a task: tasks with sub-items and a day offset each.
 */
#[Fillable(['household_id', 'created_by', 'name', 'icon', 'tasks'])]
class ChecklistTemplate extends Model
{
    protected function casts(): array
    {
        return [
            'tasks' => 'array',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }
}
