<?php

namespace App\Models;

use Database\Factories\WorkProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A member's working time contract: weekly hours, working weekdays, leave entitlement and state
 * (for public holidays).
 *
 * @property int $id
 * @property int $user_id
 * @property float $weekly_hours
 * @property list<int> $work_days ISO weekdays, 1 = Monday
 * @property float $vacation_days
 * @property float $carry_over_days
 * @property ?int $carry_over_year
 * @property string $state
 * @property int $overtime_start_minutes
 * @property ?Carbon $created_at
 */
#[Fillable(['user_id', 'weekly_hours', 'work_days', 'vacation_days', 'carry_over_days', 'carry_over_year', 'state', 'overtime_start_minutes'])]
class WorkProfile extends Model
{
    /** @use HasFactory<WorkProfileFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'weekly_hours' => 'float',
            'work_days' => 'array',
            'vacation_days' => 'float',
            'carry_over_days' => 'float',
            'carry_over_year' => 'integer',
            'overtime_start_minutes' => 'integer',
        ];
    }

    /**
     * The member's profile, created with the usual defaults (40 h, Monday to Friday, 30 days).
     */
    public static function for(User $user): self
    {
        return self::firstOrCreate(['user_id' => $user->id], ['weekly_hours' => 40, 'work_days' => [1, 2, 3, 4, 5], 'vacation_days' => 30, 'carry_over_days' => 0, 'state' => 'BY', 'overtime_start_minutes' => 0]);
    }

    /**
     * Target minutes of a normal working day.
     */
    public function dailyTargetMinutes(): int
    {
        return count($this->work_days) > 0 ? (int) round($this->weekly_hours * 60 / count($this->work_days)) : 0;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
