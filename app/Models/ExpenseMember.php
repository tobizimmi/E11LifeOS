<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A participant of an expense group: a household member or an external person (name only).
 */
#[Fillable(['expense_group_id', 'user_id', 'name', 'weight', 'position'])]
class ExpenseMember extends Model
{
    protected function casts(): array
    {
        return ['weight' => 'float'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ExpenseGroup::class, 'expense_group_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
