<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One reading of a meter.
 */
#[Fillable(['meter_id', 'created_by', 'read_on', 'value'])]
class MeterReading extends Model
{
    protected function casts(): array
    {
        return [
            'read_on' => 'date',
            'value' => 'float',
        ];
    }

    public function meter(): BelongsTo
    {
        return $this->belongsTo(Meter::class);
    }
}
