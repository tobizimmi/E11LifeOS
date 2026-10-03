<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What was done of a habit on one day.
 *
 * @property int $id
 * @property int $habit_id
 * @property Carbon $date
 * @property int $value
 */
#[Fillable(['habit_id', 'date', 'value'])]
class HabitLog extends Model
{
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'value' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Habit, $this>
     */
    public function habit(): BelongsTo
    {
        return $this->belongsTo(Habit::class);
    }
}
