<?php

namespace App\Models;

use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One booking of an imported bank statement (negative amount = expense).
 */
#[Fillable(['household_id', 'user_id', 'bank_account_id', 'booked_on', 'amount', 'counterparty', 'purpose', 'category', 'category_locked', 'import_hash'])]
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'booked_on' => 'date',
            'amount' => 'float',
            'category_locked' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function isExpense(): bool
    {
        return $this->amount < 0;
    }
}
