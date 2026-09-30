<?php

namespace App\Models;

use Database\Factories\BankAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bank account whose transactions were imported; personal to its owner.
 */
#[Fillable(['household_id', 'user_id', 'name', 'iban', 'balance', 'balance_on'])]
class BankAccount extends Model
{
    /** @use HasFactory<BankAccountFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'balance' => 'float',
            'balance_on' => 'date',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * The entered balance plus everything booked after its day; null while no balance was entered.
     */
    public function currentBalance(): ?float
    {
        if ($this->balance === null) {
            return null;
        }

        $later = $this->transactions()->when($this->balance_on, fn ($query) => $query->where('booked_on', '>', $this->balance_on->toDateString()))->sum('amount');

        return round($this->balance + (float) $later, 2);
    }
}
