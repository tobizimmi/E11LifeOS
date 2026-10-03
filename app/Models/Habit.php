<?php

namespace App\Models;

use Database\Factories\HabitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A habit of a member: ticked off ("check") or counted towards a daily target ("count"),
 * planned daily, on some weekdays or a number of times per week.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $icon
 * @property string $type
 * @property int $target
 * @property ?string $unit
 * @property string $schedule
 * @property ?array<int, int> $days
 * @property ?int $per_week
 * @property ?string $reminder_at
 * @property int $position
 * @property ?Carbon $archived_at
 * @property Carbon $created_at
 */
#[Fillable(['user_id', 'name', 'icon', 'type', 'target', 'unit', 'schedule', 'days', 'per_week', 'reminder_at', 'position', 'archived_at'])]
class Habit extends Model
{
    /** @use HasFactory<HabitFactory> */
    use HasFactory;

    public const CHECK = 'check';

    public const COUNT = 'count';

    public const DAILY = 'daily';

    public const WEEKDAYS = 'weekdays';

    public const WEEKLY = 'weekly';

    protected function casts(): array
    {
        return [
            'days' => 'array',
            'target' => 'integer',
            'per_week' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<HabitLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(HabitLog::class);
    }

    /**
     * Whether the habit is planned for a day (habits "x times a week" can be done any day).
     */
    public function isDueOn(Carbon $date): bool
    {
        return match ($this->schedule) {
            self::WEEKDAYS => in_array($date->dayOfWeekIso, array_map('intval', $this->days ?? []), true),
            default => true,
        };
    }

    /**
     * Whether an amount done on a day fulfils the habit.
     */
    public function isFulfilledBy(int $value): bool
    {
        return $value >= ($this->type === self::COUNT ? max(1, $this->target) : 1);
    }
}
