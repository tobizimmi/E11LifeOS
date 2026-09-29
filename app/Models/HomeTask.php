<?php

namespace App\Models;

use Database\Factories\HomeTaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A recurring maintenance job at home (heating service, smoke detectors, gutter …) with its interval
 * and the next due date.
 */
#[Fillable(['household_id', 'created_by', 'key', 'title', 'icon', 'interval_months', 'last_done_on', 'next_due_on', 'remind_days', 'notes'])]
class HomeTask extends Model
{
    /** @use HasFactory<HomeTaskFactory> */
    use HasFactory;

    public const CUSTOM = 'custom';

    protected function casts(): array
    {
        return [
            'interval_months' => 'integer',
            'last_done_on' => 'date',
            'next_due_on' => 'date',
            'remind_days' => 'integer',
        ];
    }

    /**
     * Done today (or on the given day): the next date moves on by the interval.
     */
    public function markDone(?Carbon $on = null): void
    {
        $on ??= today();
        $this->update(['last_done_on' => $on, 'next_due_on' => $on->copy()->addMonthsNoOverflow(max(1, $this->interval_months))]);
    }

    public function isOverdue(): bool
    {
        return $this->next_due_on->lt(today());
    }

    public function isDueSoon(): bool
    {
        return ! $this->isOverdue() && $this->next_due_on->lte(today()->addDays(30));
    }
}
