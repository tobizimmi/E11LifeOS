<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A participant of an expense group: a household member or an external person (name only).
 *
 * @property int $id
 * @property ?int $user_id
 * @property string $name
 * @property float $weight
 */
#[Fillable(['expense_group_id', 'user_id', 'name', 'weight', 'position'])]
class ExpenseMember extends Model
{
    protected function casts(): array
    {
        return ['weight' => 'float'];
    }

    /**
     * @return BelongsTo<ExpenseGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ExpenseGroup::class, 'expense_group_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
