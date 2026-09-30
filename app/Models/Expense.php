<?php

namespace App\Models;

use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One expense of a group, paid by a participant and split between several; a transfer is a
 * repayment between two participants.
 */
#[Fillable(['expense_group_id', 'paid_by', 'created_by', 'title', 'amount', 'spent_on', 'category', 'split_mode', 'is_transfer', 'notes'])]
class Expense extends Model
{
    /** @use HasFactory<ExpenseFactory> */
    use HasFactory;

    public const SPLIT_MODES = ['equal', 'shares', 'amounts'];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'spent_on' => 'date',
            'is_transfer' => 'boolean',
            'notes' => 'encrypted',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ExpenseGroup::class, 'expense_group_id');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(ExpenseMember::class, 'paid_by');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(ExpenseShare::class);
    }
}
