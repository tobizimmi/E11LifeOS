<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property list<string> $entities
 * @property list<string>|null $lit
 * @property Carbon|null $next_change_at
 *
 * Simulated presence while nobody is at home: lights go on and off at random within the time
 * window (see App\Support\SmartHome\PresenceSimulator).
 */
#[Fillable(['household_id', 'automation_id', 'entities', 'from', 'until', 'active', 'lit', 'next_change_at'])]
class PresenceSimulation extends Model
{
    protected function casts(): array
    {
        return [
            'entities' => 'array',
            'lit' => 'array',
            'active' => 'boolean',
            'next_change_at' => 'datetime',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }
}
