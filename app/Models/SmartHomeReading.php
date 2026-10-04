<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property float $value
 * @property Carbon $recorded_at
 *
 * A measured value every few minutes (evcc power values, numeric Home Assistant sensors) for the
 * curves of the day; kept a few weeks, the daily energy stays in SmartHomeDay.
 */
#[Fillable(['household_id', 'metric', 'value', 'recorded_at'])]
class SmartHomeReading extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'value' => 'float',
            'recorded_at' => 'datetime',
        ];
    }
}
