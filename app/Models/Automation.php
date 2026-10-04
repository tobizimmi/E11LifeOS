<?php

namespace App\Models;

use Database\Factories\AutomationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * "When … only if … then …" for the whole tool: a trigger (time, calendar, trip, mode, Home
 * Assistant, evcc …), optional conditions and a list of actions (see App\Support\Automation).
 *
 * @property array{type: string, ...} $trigger
 * @property list<array{type: string, ...}> $conditions
 * @property list<array{type: string, ...}> $actions
 * @property Carbon|null $last_run_at
 * @property-read User|null $user
 */
#[Fillable(['household_id', 'user_id', 'name', 'active', 'trigger', 'conditions', 'actions', 'cooldown_minutes', 'trigger_state', 'last_run_at'])]
class Automation extends Model
{
    /** @use HasFactory<AutomationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'trigger' => 'array',
            'conditions' => 'array',
            'actions' => 'array',
            'trigger_state' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /**
     * @return HasMany<AutomationRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }
}
