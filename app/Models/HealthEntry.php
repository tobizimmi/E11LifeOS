<?php

namespace App\Models;

use Database\Factories\HealthEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A check-up, vaccination, doctor's appointment or medication of a health profile.
 */
#[Fillable(['health_profile_id', 'kind', 'key', 'title', 'done_on', 'next_due_on', 'interval_months', 'time_of_day', 'times', 'dosage', 'active', 'notes'])]
class HealthEntry extends Model
{
    /** @use HasFactory<HealthEntryFactory> */
    use HasFactory;

    public const CHECKUP = 'checkup';

    public const VACCINATION = 'vaccination';

    public const APPOINTMENT = 'appointment';

    public const MEDICATION = 'medication';

    public const CUSTOM = 'custom';

    protected function casts(): array
    {
        return [
            'done_on' => 'date',
            'next_due_on' => 'date',
            'interval_months' => 'integer',
            'times' => 'array',
            'active' => 'boolean',
            'notes' => 'encrypted',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(HealthProfile::class, 'health_profile_id');
    }

    /**
     * Done on the given day: with an interval the next date moves on, otherwise it is cleared.
     */
    public function markDone(?Carbon $on = null): void
    {
        $on ??= today();
        $this->update(['done_on' => $on, 'next_due_on' => $this->interval_months ? $on->copy()->addMonthsNoOverflow($this->interval_months) : null]);
    }

    public function isOverdue(): bool
    {
        return $this->next_due_on !== null && $this->next_due_on->lt(today());
    }
}
