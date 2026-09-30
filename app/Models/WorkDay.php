<?php

namespace App\Models;

use Database\Factories\WorkDayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One day of a member: worked (with start, end and break), on leave, half a day off, sick or
 * compensating overtime.
 *
 * @property int $id
 * @property int $user_id
 * @property Carbon $date
 * @property string $type
 * @property ?string $starts_at
 * @property ?string $ends_at
 * @property int $break_minutes
 * @property ?string $note
 */
#[Fillable(['user_id', 'date', 'type', 'starts_at', 'ends_at', 'break_minutes', 'note'])]
class WorkDay extends Model
{
    /** @use HasFactory<WorkDayFactory> */
    use HasFactory;

    public const TYPES = ['work', 'vacation', 'half_vacation', 'sick', 'overtime_off'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'break_minutes' => 'integer',
        ];
    }

    /**
     * @return array<string, array{label: string, icon: string}>
     */
    public static function types(): array
    {
        return [
            'work' => ['label' => __('Worked'), 'icon' => '💼'],
            'vacation' => ['label' => __('Holiday'), 'icon' => '🏖️'],
            'half_vacation' => ['label' => __('Half day off'), 'icon' => '🌗'],
            'sick' => ['label' => __('Sick'), 'icon' => '🤒'],
            'overtime_off' => ['label' => __('Time off in lieu'), 'icon' => '⏳'],
        ];
    }

    /**
     * Net minutes worked (end minus start minus break); a running day counts until now.
     */
    public function workedMinutes(?Carbon $now = null): int
    {
        if (! $this->starts_at) {
            return 0;
        }

        $start = Carbon::parse($this->date->toDateString().' '.$this->starts_at);
        $end = $this->ends_at ? Carbon::parse($this->date->toDateString().' '.$this->ends_at) : ($this->date->isToday() ? ($now ?? now()) : null);

        if (! $end || $end->lessThanOrEqualTo($start)) {
            return 0;
        }

        return max(0, (int) $start->diffInMinutes($end) - $this->break_minutes);
    }
}
