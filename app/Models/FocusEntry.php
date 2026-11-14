<?php

namespace App\Models;

use Database\Factories\FocusEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single entry of the personal daily "Focus" plan: a brain-dumped task, sorted into an Eisenhower
 * quadrant, optionally one of the day's most important tasks (MIT) and placed into a time box. May
 * mirror a real to-do (item_id).
 *
 * @property int $id
 * @property int $household_id
 * @property int $user_id
 * @property ?int $item_id
 * @property Carbon $date
 * @property string $title
 * @property ?string $quadrant
 * @property bool $is_mit
 * @property ?int $start_minute
 * @property int $duration_minutes
 * @property bool $done
 * @property int $position
 */
#[Fillable(['household_id', 'user_id', 'item_id', 'date', 'title', 'quadrant', 'is_mit', 'start_minute', 'duration_minutes', 'done', 'position'])]
class FocusEntry extends Model
{
    /** @use HasFactory<FocusEntryFactory> */
    use HasFactory;

    /** Eisenhower quadrants: q1 do now, q2 schedule, q3 delegate, q4 eliminate. */
    public const QUADRANTS = ['q1', 'q2', 'q3', 'q4'];

    /** Most important tasks per day (classic time-boxing cap). */
    public const MAX_MITS = 3;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'is_mit' => 'boolean',
            'done' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isScheduled(): bool
    {
        return $this->start_minute !== null;
    }

    /**
     * "HH:MM–HH:MM" of the time box, or null when it is not scheduled.
     */
    public function timeRange(): ?string
    {
        if ($this->start_minute === null) {
            return null;
        }

        $end = $this->start_minute + max(5, $this->duration_minutes);

        return sprintf('%02d:%02d–%02d:%02d', intdiv($this->start_minute, 60), $this->start_minute % 60, intdiv($end, 60) % 24, $end % 60);
    }

    /**
     * The member's own focus entries for a given day (personal, not shared in the household).
     *
     * @param  Builder<FocusEntry>  $query
     */
    public function scopeForDay(Builder $query, User $user, int $householdId, string $date): void
    {
        $query->where('household_id', $householdId)->where('user_id', $user->id)->whereDate('date', $date);
    }
}
