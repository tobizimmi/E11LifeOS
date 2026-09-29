<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A chore a child ticked off; parents confirm it before the reward is credited.
 */
#[Fillable(['kid_chore_id', 'kid_id', 'done_on', 'status', 'reward', 'reviewed_by'])]
class KidChoreCompletion extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected function casts(): array
    {
        return ['done_on' => 'date', 'reward' => 'float'];
    }

    public function chore(): BelongsTo
    {
        return $this->belongsTo(KidChore::class, 'kid_chore_id');
    }

    public function kid(): BelongsTo
    {
        return $this->belongsTo(Kid::class);
    }
}
