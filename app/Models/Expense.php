<?php

namespace App\Models;

use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One expense of a group, paid by a participant and split between several; a transfer is a
 * repayment between two participants.
 *
 * @property int $id
 * @property int $paid_by
 * @property string $title
 * @property float $amount
 * @property Carbon $spent_on
 * @property string $category
 * @property string $split_mode
 * @property bool $is_transfer
 * @property ?string $notes
 * @property-read ?ExpenseMember $payer
 * @property-read Collection<int, ExpenseShare> $shares
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

    /**
     * @return BelongsTo<ExpenseGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ExpenseGroup::class, 'expense_group_id');
    }

    /**
     * @return BelongsTo<ExpenseMember, $this>
     */
    public function payer(): BelongsTo
    {
        return $this->belongsTo(ExpenseMember::class, 'paid_by');
    }

    /**
     * @return HasMany<ExpenseShare, $this>
     */
    public function shares(): HasMany
    {
        return $this->hasMany(ExpenseShare::class);
    }
}
