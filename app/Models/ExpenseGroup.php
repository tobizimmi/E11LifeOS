<?php

namespace App\Models;

use App\Models\Concerns\HasShareTokens;
use Database\Factories\ExpenseGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A group sharing expenses (holiday with friends, shared flat, club trip) like Splid or Tricount.
 */
#[Fillable(['household_id', 'created_by', 'visibility', 'title', 'icon', 'currency', 'trip_id', 'share_token', 'share_expires_at', 'archived_at'])]
#[Hidden(['share_token', 'share_token_encrypted'])]
class ExpenseGroup extends Model
{
    /** @use HasFactory<ExpenseGroupFactory> */
    use HasFactory, HasShareTokens;

    public const ICONS = ['🧾', '✈️', '🏖️', '🏠', '🍻', '⛺', '🎉', '⚽', '🚗', '🎿'];

    protected function casts(): array
    {
        return [
            'share_expires_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function members(): HasMany
    {
        return $this->hasMany(ExpenseMember::class)->orderBy('position')->orderBy('id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class)->orderByDesc('spent_on')->orderByDesc('id');
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /**
     * Shared groups of the household and the member's private ones.
     */
    public function scopeVisibleTo(Builder $query, User $user, int $householdId): void
    {
        $query->where('household_id', $householdId)
            ->where(fn (Builder $query) => $query->where('visibility', Item::VISIBILITY_SHARED)->orWhere('created_by', $user->id));
    }

    /**
     * @return list<string>
     */
    protected function shareTokenAttributes(): array
    {
        return ['share_token'];
    }
}
