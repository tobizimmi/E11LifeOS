<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The remaining actions of an automation after a "wait" action, run when their time has come –
 * or after an "ask by push" action, run when someone answers "yes" before expires_at.
 *
 * @property list<array<string, mixed>> $actions
 * @property Carbon|null $run_at
 * @property Carbon|null $expires_at
 */
#[Fillable(['automation_id', 'actions', 'depth', 'run_at', 'expires_at'])]
class AutomationPendingAction extends Model
{
    protected function casts(): array
    {
        return [
            'actions' => 'array',
            'run_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Automation, $this>
     */
    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }
}
