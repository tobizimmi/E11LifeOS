<?php

namespace App\Models;

use Database\Factories\SavingsGoalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A savings goal, e.g. "Emergency fund 10,000 €" – linked to a bank account or tracked by hand.
 */
#[Fillable(['user_id', 'name', 'icon', 'target_amount', 'saved_amount', 'bank_account_id', 'target_date', 'position'])]
class SavingsGoal extends Model
{
    /** @use HasFactory<SavingsGoalFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'target_amount' => 'float',
            'saved_amount' => 'float',
            'target_date' => 'date',
        ];
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /**
     * What is saved so far: the linked account's balance, else the amount entered.
     */
    public function savedSoFar(): float
    {
        return $this->bankAccount?->currentBalance() ?? $this->saved_amount;
    }

    public function progress(): float
    {
        return $this->target_amount > 0 ? min(100, max(0, round($this->savedSoFar() / $this->target_amount * 100, 1))) : 0;
    }

    /**
     * Monthly amount still needed to reach the goal by its date (null without a date or when reached).
     */
    public function monthlyNeeded(): ?float
    {
        $missing = $this->target_amount - $this->savedSoFar();

        if (! $this->target_date || $missing <= 0) {
            return null;
        }

        $months = max(1, (int) floor(today()->diffInMonths($this->target_date, false)));

        return round($missing / $months, 2);
    }
}
