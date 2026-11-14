<?php

namespace App\Models;

use Database\Factories\KidFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A child of the household with pocket money, chores and a savings goal (no own login needed).
 */
#[Fillable(['household_id', 'name', 'emoji', 'birth_date', 'weekly_allowance', 'allowance_day', 'last_allowance_on', 'auto_approve', 'goal_title', 'goal_amount'])]
class Kid extends Model
{
    /** @use HasFactory<KidFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'weekly_allowance' => 'float',
            'allowance_day' => 'integer',
            'last_allowance_on' => 'date',
            'auto_approve' => 'boolean',
            'goal_amount' => 'float',
        ];
    }

    /**
     * @return HasMany<KidTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(KidTransaction::class)->orderByDesc('date')->orderByDesc('id');
    }

    public function completions(): HasMany
    {
        return $this->hasMany(KidChoreCompletion::class);
    }

    public function balance(): float
    {
        return round((float) $this->transactions()->sum('amount'), 2);
    }

    public function age(): ?int
    {
        return $this->birth_date?->age;
    }
}
