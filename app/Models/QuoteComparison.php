<?php

namespace App\Models;

use Database\Factories\QuoteComparisonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Several quotes for one purchase (a kitchen, a car, the roofer for a property …) side by side;
 * optionally linked to a trade of a property, which takes over the chosen quote.
 */
#[Fillable(['household_id', 'created_by', 'visibility', 'title', 'icon', 'notes', 'decide_by', 'real_estate_id', 'budget_item_id', 'chosen_quote_id'])]
class QuoteComparison extends Model
{
    /** @use HasFactory<QuoteComparisonFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'decide_by' => 'date',
        ];
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class)->orderBy('position')->orderBy('id');
    }

    public function chosenQuote(): BelongsTo
    {
        return $this->belongsTo(Quote::class, 'chosen_quote_id');
    }

    public function realEstate(): BelongsTo
    {
        return $this->belongsTo(RealEstate::class);
    }

    public function budgetItem(): BelongsTo
    {
        return $this->belongsTo(RealEstateBudgetItem::class, 'budget_item_id');
    }

    /**
     * Shared comparisons of the household and the member's private ones.
     */
    public function scopeVisibleTo(Builder $query, User $user, int $householdId): void
    {
        $query->where('household_id', $householdId)
            ->where(fn (Builder $query) => $query->where('visibility', Item::VISIBILITY_SHARED)->orWhere('created_by', $user->id));
    }

    /**
     * Picks the winning quote (null undoes it). A linked trade of a property takes over vendor and
     * amount and counts as commissioned.
     */
    public function choose(?Quote $quote): void
    {
        abort_if($quote !== null && $quote->quote_comparison_id !== $this->id, 404);
        $this->update(['chosen_quote_id' => $quote?->id]);

        if ($quote && $this->budgetItem) {
            $this->budgetItem->update(array_filter([
                'contractor' => mb_substr($quote->vendor, 0, 255),
                'planned_amount' => $quote->amount,
                'status' => in_array($this->budgetItem->status, ['planned', 'quoted'], true) ? 'commissioned' : null,
            ], fn ($value) => $value !== null));
        }
    }

    /**
     * The quote with the lowest price, if any quote has a price.
     */
    public function cheapestQuote(): ?Quote
    {
        return $this->quotes->filter(fn (Quote $quote) => $quote->amount !== null)->sortBy('amount')->first();
    }

    public function isDecided(): bool
    {
        return $this->chosen_quote_id !== null;
    }
}
