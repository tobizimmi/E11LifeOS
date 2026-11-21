<?php

namespace App\Models;

use App\Support\Finance\FinanceCategories;
use Database\Factories\FinanceCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user-defined spending/income category for a household, on top of the fixed catalogue in
 * {@see FinanceCategories}. The "group" (needs/wants/savings) places it in
 * the 50/30/20 check, budgets and the report just like a built-in category.
 */
#[Fillable(['household_id', 'user_id', 'key', 'label', 'icon', 'group'])]
class FinanceCategory extends Model
{
    /** @use HasFactory<FinanceCategoryFactory> */
    use HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
