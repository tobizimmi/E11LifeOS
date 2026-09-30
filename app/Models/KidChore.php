<?php

namespace App\Models;

use Database\Factories\KidChoreFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A task children can earn money with: daily, once per week or once.
 */
#[Fillable(['household_id', 'kid_id', 'title', 'emoji', 'reward', 'frequency', 'active'])]
class KidChore extends Model
{
    /** @use HasFactory<KidChoreFactory> */
    use HasFactory;

    public const FREQUENCIES = ['daily', 'weekly', 'once'];

    protected function casts(): array
    {
        return ['reward' => 'float', 'active' => 'boolean'];
    }

    public function kid(): BelongsTo
    {
        return $this->belongsTo(Kid::class);
    }

    public function completions(): HasMany
    {
        return $this->hasMany(KidChoreCompletion::class);
    }

    /**
     * Open for the child on that day: not yet done (or waiting) today, this week or at all.
     */
    public function isOpenFor(Kid $kid, Carbon $day): bool
    {
        if (! $this->active || ($this->kid_id !== null && $this->kid_id !== $kid->id)) {
            return false;
        }

        $done = $this->completions()->where('kid_id', $kid->id)->where('status', '!=', KidChoreCompletion::REJECTED);

        return ! match ($this->frequency) {
            'daily' => $done->whereDate('done_on', $day->toDateString())->exists(),
            'weekly' => $done->whereBetween('done_on', [$day->copy()->startOfWeek()->toDateString(), $day->copy()->endOfWeek()->toDateString()])->exists(),
            default => $done->exists(),
        };
    }
}
