<?php

namespace App\Models;

use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One booking of an imported bank statement (negative amount = expense).
 *
 * @property int $id
 * @property int $household_id
 * @property int $user_id
 * @property ?int $bank_account_id
 * @property Carbon $booked_on
 * @property float $amount
 * @property ?string $counterparty
 * @property ?string $purpose
 * @property ?string $category
 * @property ?string $tax_category
 * @property-read ?BankAccount $account
 */
#[Fillable(['household_id', 'user_id', 'bank_account_id', 'booked_on', 'amount', 'counterparty', 'purpose', 'category', 'category_locked', 'tax_category', 'tax_amount', 'import_hash'])]
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
            'tax_amount' => 'float',
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
