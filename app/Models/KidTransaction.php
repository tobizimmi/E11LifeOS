<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A booking on a child's pocket money account: allowance, chore reward, bonus, gift or spending.
 */
#[Fillable(['kid_id', 'date', 'kind', 'label', 'amount', 'kid_chore_completion_id', 'created_by'])]
class KidTransaction extends Model
{
    public const KINDS = ['allowance', 'chore', 'bonus', 'gift', 'spend'];

    protected function casts(): array
    {
        return ['date' => 'date', 'amount' => 'float'];
    }

    public function kid(): BelongsTo
    {
        return $this->belongsTo(Kid::class);
    }
}
