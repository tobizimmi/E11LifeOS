<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The part of an expense a participant carries: "value" is the entered share (weight or amount),
 * "amount" the resulting euros.
 *
 * @property int $expense_member_id
 * @property float $value
 * @property float $amount
 */
#[Fillable(['expense_id', 'expense_member_id', 'value', 'amount'])]
class ExpenseShare extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['value' => 'float', 'amount' => 'float'];
    }

    /**
     * @return BelongsTo<ExpenseMember, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(ExpenseMember::class, 'expense_member_id');
    }
}
