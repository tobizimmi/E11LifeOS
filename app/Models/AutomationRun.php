<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One run of an automation: why it ran and what each action did.
 */
#[Fillable(['automation_id', 'reason', 'status', 'log'])]
class AutomationRun extends Model
{
    protected function casts(): array
    {
        return ['log' => 'array'];
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }
}
